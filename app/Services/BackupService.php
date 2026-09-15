<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Models\SystemSetting;
use App\Models\User;
use PDO;
use RuntimeException;
use ZipArchive;

/**
 * Backups and restores (docs/20), with no external tool: the database is written by
 * PHP itself through PDO, so it works on any XAMPP install.
 *
 * An archive (storage/backups/schoolhub-YYYYmmdd-His-type.zip) holds:
 *   database.sql   one statement per line: DROP/CREATE for every table, then INSERTs
 *   manifest.json  app version, time, row count per table, SHA-256 of database.sql
 *   uploads/...    the uploaded files, when backup_include_uploads is on
 * The archive's own SHA-256 is recorded in `backups`, so tampering or corruption is
 * caught by verify() and before any restore.
 *
 * Restoring replaces the whole database and the uploads folder, so it is deliberately
 * NOT available from the web: only `php database/restore.php`, on the server, with a
 * typed confirmation and an automatic safety backup first.
 */
final class BackupService
{
    public const MANUAL      = 'MANUAL';
    public const SCHEDULED   = 'SCHEDULED';
    public const PRE_RESTORE = 'PRE_RESTORE';

    /** Tables whose rows are not worth keeping (their structure is still backed up). */
    private const SKIP_DATA = ['sessions'];

    /** Largest INSERT statement, well under MariaDB's default max_allowed_packet. */
    private const MAX_STATEMENT_BYTES = 512 * 1024;

    /**
     * Create a backup. Returns the backups row id; a failure is recorded as FAILED and
     * rethrown.
     */
    public static function create(?User $actor, string $type = self::MANUAL, ?string $notes = null): int
    {
        if ($actor !== null && !$actor->isSuperAdmin()) {
            throw HttpException::forbidden('Only the Super Admin creates backups.');
        }
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required for backups. Enable extension=zip in php.ini.');
        }
        // One backup at a time, across web requests and the scheduled task.
        if ((int) Database::scalar("SELECT GET_LOCK('schoolhub_backup', 0)") !== 1) {
            throw ValidationException::withMessage('Another backup is running. Try again when it has finished.');
        }
        @set_time_limit(0);

        $dir = self::directory();
        $filename = 'schoolhub-' . date('Ymd-His') . '-' . strtolower(str_replace('_', '-', $type)) . '.zip';
        for ($n = 2; is_file($dir . '/' . $filename) || Database::scalar('SELECT id FROM backups WHERE filename = ?', [$filename]) !== null; $n++) {
            $filename = 'schoolhub-' . date('Ymd-His') . '-' . strtolower(str_replace('_', '-', $type)) . "-{$n}.zip";
        }
        $includeUploads = (bool) SystemSetting::get('backup_include_uploads', true);
        $id = Database::insert('backups', [
            'filename'         => $filename,
            'path'             => 'backups/' . $filename,
            'type'             => $type,
            'status'           => 'RUNNING',
            'includes_uploads' => $includeUploads ? 1 : 0,
            'started_at'       => date('Y-m-d H:i:s'),
            'created_by'       => $actor?->id,
            'notes'            => $notes !== null ? mb_substr($notes, 0, 255) : null,
        ]);

        $sqlPath = $dir . "/.backup-{$id}.sql";
        $partial = $dir . "/.backup-{$id}.zip.partial";
        try {
            $pdo = self::connect();
            $handle = fopen($sqlPath, 'wb');
            $tables = self::dump($pdo, $handle, $id);
            fclose($handle);

            $manifest = [
                'application'     => 'SchoolHub',
                'version'         => (string) Config::get('app.version', ''),
                'created_at'      => date('c'),
                'database'        => (string) Config::get('db.database', ''),
                'type'            => $type,
                'tables'          => $tables,
                'sql_sha256'      => hash_file('sha256', $sqlPath),
                'includes_uploads' => $includeUploads,
                'upload_count'    => 0,
            ];

            $zip = new ZipArchive();
            if ($zip->open($partial, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create the backup archive.');
            }
            $zip->addFile($sqlPath, 'database.sql');
            if ($includeUploads) {
                $manifest['upload_count'] = self::addUploads($zip);
            }
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            if (!$zip->close()) {
                throw new RuntimeException('Could not finish writing the backup archive.');
            }
            @unlink($sqlPath);
            if (!rename($partial, $dir . '/' . $filename)) {
                throw new RuntimeException('Could not move the finished backup into place.');
            }

            $full = $dir . '/' . $filename;
            Database::update('backups', [
                'status'       => 'COMPLETED',
                'size_bytes'   => filesize($full),
                'checksum'     => hash_file('sha256', $full),
                'table_count'  => count($tables),
                'upload_count' => $manifest['upload_count'],
                'completed_at' => date('Y-m-d H:i:s'),
            ], ['id' => $id]);
            AuditService::log('backup.created', 'administration', 'Backup', $id, null, [
                'filename' => $filename, 'type' => $type, 'tables' => count($tables), 'uploads' => $manifest['upload_count'],
            ], 'SUCCESS', $notes, $actor?->id, $actor?->role_name ?? 'System');

            if ($type !== self::PRE_RESTORE) {
                self::prune((int) SystemSetting::get('backup_retention_count', 14), $actor);
            }
            return $id;
        } catch (\Throwable $e) {
            @unlink($sqlPath);
            @unlink($partial);
            Database::update('backups', ['status' => 'FAILED', 'completed_at' => date('Y-m-d H:i:s'), 'error' => mb_substr($e->getMessage(), 0, 2000)], ['id' => $id]);
            AuditService::log('backup.failed', 'administration', 'Backup', $id, null, ['filename' => $filename], 'FAILURE', mb_substr($e->getMessage(), 0, 500), $actor?->id, $actor?->role_name ?? 'System');
            throw $e;
        } finally {
            Database::scalar("SELECT RELEASE_LOCK('schoolhub_backup')");
        }
    }

    /**
     * Check a backup: the file is present, its checksum matches, the archive opens, and
     * database.sql matches its manifest. The result is recorded and audited.
     *
     * @return array{ok:bool,message:string}
     */
    public static function verify(?User $actor, int $id): array
    {
        $backup = self::find($id);
        $result = self::checkArchive(STORAGE_PATH . '/' . $backup['path'], $backup['checksum']);
        Database::update('backups', ['verified_at' => date('Y-m-d H:i:s'), 'verify_result' => mb_substr(($result['ok'] ? 'OK: ' : 'FAILED: ') . $result['message'], 0, 255)], ['id' => $id]);
        AuditService::log('backup.verified', 'administration', 'Backup', $id, null, ['ok' => $result['ok']], $result['ok'] ? 'SUCCESS' : 'FAILURE', $result['message'], $actor?->id, $actor?->role_name ?? 'System');
        return $result;
    }

    /**
     * @return array{ok:bool,message:string,manifest?:array<string,mixed>}
     */
    public static function checkArchive(string $file, ?string $expectedChecksum): array
    {
        if (!is_file($file)) {
            return ['ok' => false, 'message' => 'The backup file is missing.'];
        }
        if ($expectedChecksum !== null && !hash_equals($expectedChecksum, hash_file('sha256', $file))) {
            return ['ok' => false, 'message' => 'The file does not match its recorded checksum: it was changed or damaged.'];
        }
        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::RDONLY) !== true) {
            return ['ok' => false, 'message' => 'The archive cannot be opened.'];
        }
        try {
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
            if (!is_array($manifest) || ($manifest['application'] ?? '') !== 'SchoolHub') {
                return ['ok' => false, 'message' => 'The archive has no valid SchoolHub manifest.'];
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (!self::safeEntry($name)) {
                    return ['ok' => false, 'message' => "The archive contains an unsafe path: {$name}"];
                }
            }
            $stream = $zip->getStream('database.sql');
            if ($stream === false) {
                return ['ok' => false, 'message' => 'The archive has no database.sql.'];
            }
            $context = hash_init('sha256');
            $created = [];
            while (($line = fgets($stream)) !== false) {
                hash_update($context, $line);
                if (preg_match('/^CREATE TABLE `([^`]+)`/', $line, $m)) {
                    $created[] = $m[1];
                }
            }
            fclose($stream);
            if (!hash_equals((string) ($manifest['sql_sha256'] ?? ''), hash_final($context))) {
                return ['ok' => false, 'message' => 'database.sql does not match the checksum in its manifest.'];
            }
            $missing = array_diff(array_keys((array) ($manifest['tables'] ?? [])), $created);
            if ($missing !== [] || $created === []) {
                return ['ok' => false, 'message' => 'database.sql is missing tables listed in the manifest.'];
            }
            return ['ok' => true, 'message' => count($created) . ' tables, ' . (int) ($manifest['upload_count'] ?? 0) . ' uploaded files.', 'manifest' => $manifest];
        } finally {
            $zip->close();
        }
    }

    /** Stream a backup to the Super Admin after re-checking their password. Audited either way. */
    public static function download(User $admin, int $id, string $password): void
    {
        self::assertPassword($admin, $password, 'backup.download_refused', $id);
        $backup = self::find($id);
        if ($backup['status'] !== 'COMPLETED') {
            throw ValidationException::withMessage('Only a completed backup can be downloaded.');
        }
        AuditService::log('backup.downloaded', 'administration', 'Backup', $id, null, ['filename' => $backup['filename']], 'SUCCESS', null, $admin->id, $admin->role_name);
        FileStreamer::send($backup['path'], $backup['filename'], 'application/zip');
    }

    /** Remove a backup's file; the record stays, marked DELETED. Needs the password. */
    public static function delete(User $admin, int $id, string $password): void
    {
        self::assertPassword($admin, $password, 'backup.delete_refused', $id);
        $backup = self::find($id);
        if (in_array($backup['status'], ['RUNNING', 'DELETED'], true)) {
            throw ValidationException::withMessage('This backup cannot be deleted.');
        }
        @unlink(STORAGE_PATH . '/' . $backup['path']);
        Database::update('backups', ['status' => 'DELETED'], ['id' => $id]);
        AuditService::log('backup.deleted', 'administration', 'Backup', $id, ['status' => $backup['status']], ['status' => 'DELETED'], 'SUCCESS', $backup['filename'], $admin->id, $admin->role_name);
    }

    /**
     * Keep the newest $keep completed manual and scheduled backups; remove the files of
     * older ones. $onlyCreatedBy limits pruning to one user's backups (tests).
     *
     * @return int backups removed
     */
    public static function prune(int $keep, ?User $actor = null, ?int $onlyCreatedBy = null): int
    {
        $keep = max(1, $keep);
        $where = "status = 'COMPLETED' AND type IN ('MANUAL', 'SCHEDULED')";
        $bindings = [];
        if ($onlyCreatedBy !== null) {
            $where .= ' AND created_by = ?';
            $bindings[] = $onlyCreatedBy;
        }
        $old = Database::select("SELECT id, path, filename FROM backups WHERE {$where} ORDER BY completed_at DESC, id DESC LIMIT 1000 OFFSET {$keep}", $bindings);
        foreach ($old as $backup) {
            @unlink(STORAGE_PATH . '/' . $backup['path']);
            Database::update('backups', ['status' => 'DELETED'], ['id' => $backup['id']]);
            AuditService::log('backup.pruned', 'administration', 'Backup', (int) $backup['id'], null, ['filename' => $backup['filename'], 'keep' => $keep], 'SUCCESS', null, $actor?->id, $actor?->role_name ?? 'System');
        }
        return count($old);
    }

    /**
     * Restore an archive into a database and uploads folder. Everything in the target
     * database is replaced. Only database/restore.php (and tests, into a scratch
     * database) call this.
     *
     * @param callable(string):void|null $log
     * @return array{tables:int,statements:int,uploads:int}
     */
    public static function restore(string $archive, ?string $expectedChecksum, PDO $target, string $uploadsDir, ?callable $log = null): array
    {
        $log ??= static function (string $message): void {};
        $check = self::checkArchive($archive, $expectedChecksum);
        if (!$check['ok']) {
            throw new RuntimeException('Refusing to restore: ' . $check['message']);
        }
        $manifest = $check['manifest'];

        $zip = new ZipArchive();
        $zip->open($archive, ZipArchive::RDONLY);
        try {
            // 1. Database: drop every table, then replay the dump statement by statement.
            $log('Replacing the database…');
            $target->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach ($target->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as [$table]) {
                $target->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
            }
            $stream = $zip->getStream('database.sql');
            $statements = 0;
            while (($line = fgets($stream)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '' || str_starts_with($line, '--')) {
                    continue;
                }
                $target->exec($line);
                $statements++;
            }
            fclose($stream);
            $target->exec('SET FOREIGN_KEY_CHECKS=1');

            // 2. Uploads: extract beside the current folder, then swap, keeping the old one.
            $uploads = 0;
            if (!empty($manifest['includes_uploads'])) {
                $log('Replacing uploaded files…');
                $staging = rtrim($uploadsDir, '/\\') . '.restoring-' . date('YmdHis');
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = (string) $zip->getNameIndex($i);
                    if (!str_starts_with($name, 'uploads/') || str_ends_with($name, '/')) {
                        continue;
                    }
                    $relative = substr($name, strlen('uploads/'));
                    $destination = $staging . '/' . $relative;
                    if (!is_dir(dirname($destination))) {
                        mkdir(dirname($destination), 0750, true);
                    }
                    $in = $zip->getStream($name);
                    $out = fopen($destination, 'wb');
                    stream_copy_to_stream($in, $out);
                    fclose($in);
                    fclose($out);
                    $uploads++;
                }
                if (!is_dir($staging)) {
                    mkdir($staging, 0750, true);
                }
                if (is_dir($uploadsDir)) {
                    rename($uploadsDir, rtrim($uploadsDir, '/\\') . '.before-restore-' . date('YmdHis'));
                }
                rename($staging, $uploadsDir);
            }

            return ['tables' => count((array) $manifest['tables']), 'statements' => $statements, 'uploads' => $uploads];
        } finally {
            $zip->close();
        }
    }

    /** A PDO connection of its own (so a dump can hold a consistent snapshot). */
    public static function connect(?string $database = null): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=%s%s',
            (string) Config::get('db.host', '127.0.0.1'), (int) Config::get('db.port', 3306), (string) Config::get('db.charset', 'utf8mb4'),
            ($database ?? (string) Config::get('db.database', 'schoolhub')) !== '' ? ';dbname=' . ($database ?? (string) Config::get('db.database', 'schoolhub')) : '');
        return new PDO($dsn, (string) Config::get('db.username', 'root'), (string) Config::get('db.password', ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => Database::sessionSetup(),
        ]);
    }

    /** @return array<int,array<string,mixed>> newest first, with who made each one */
    public static function history(int $limit = 100): array
    {
        return Database::select(
            "SELECT b.*, CONCAT(u.first_name, ' ', u.last_name) AS created_by_name
               FROM backups b LEFT JOIN users u ON u.id = b.created_by
              ORDER BY b.started_at DESC, b.id DESC LIMIT " . max(1, $limit)
        );
    }

    /** @return array<string,mixed> */
    public static function find(int $id): array
    {
        $backup = Database::selectOne('SELECT * FROM backups WHERE id = ?', [$id]);
        if ($backup === null) {
            throw HttpException::notFound('That backup was not found.');
        }
        return $backup;
    }

    /** A zip entry name that cannot escape the folder it is extracted into. */
    public static function safeEntry(string $name): bool
    {
        return $name !== ''
            && !str_contains($name, '..')
            && !str_contains($name, '\\')
            && !str_starts_with($name, '/')
            && !preg_match('/^[A-Za-z]:/', $name)
            && ($name === 'database.sql' || $name === 'manifest.json' || str_starts_with($name, 'uploads/'));
    }

    // -- Internals --------------------------------------------------------------------

    /**
     * Write every table to $handle inside one consistent snapshot.
     *
     * @param resource $handle
     * @return array<string,int> table => rows written
     */
    private static function dump(PDO $pdo, $handle, int $currentBackupId): array
    {
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

        fwrite($handle, "-- SchoolHub database backup, " . date('c') . "\n");
        fwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");

        $counts = [];
        foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as [$table]) {
            $quoted = '`' . str_replace('`', '``', $table) . '`';
            $create = (string) $pdo->query("SHOW CREATE TABLE {$quoted}")->fetch(PDO::FETCH_NUM)[1];
            fwrite($handle, "DROP TABLE IF EXISTS {$quoted};\n");
            // One line per statement: the formatting newlines of CREATE TABLE carry no meaning.
            fwrite($handle, preg_replace('/\s*\R\s*/', ' ', $create) . ";\n");

            $counts[$table] = 0;
            if (in_array($table, self::SKIP_DATA, true)) {
                continue;
            }
            $select = "SELECT * FROM {$quoted}" . ($table === 'backups' ? ' WHERE id <> ' . $currentBackupId : '');
            $rows = $pdo->query($select);
            $columns = null;
            $values = [];
            $bytes = 0;
            $flush = static function () use (&$values, &$bytes, &$columns, $handle, $quoted): void {
                if ($values !== []) {
                    fwrite($handle, "INSERT INTO {$quoted} ({$columns}) VALUES " . implode(',', $values) . ";\n");
                }
                $values = [];
                $bytes = 0;
            };
            while (($row = $rows->fetch(PDO::FETCH_ASSOC)) !== false) {
                $columns ??= implode(',', array_map(static fn ($c) => '`' . str_replace('`', '``', $c) . '`', array_keys($row)));
                // PDO::quote escapes newlines and quotes, so each INSERT stays on one line.
                $tuple = '(' . implode(',', array_map(static fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)) . ')';
                $values[] = $tuple;
                $bytes += strlen($tuple);
                $counts[$table]++;
                if ($bytes >= self::MAX_STATEMENT_BYTES) {
                    $flush();
                }
            }
            $flush();
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        $pdo->exec('COMMIT');
        return $counts;
    }

    private static function addUploads(ZipArchive $zip): int
    {
        $root = STORAGE_PATH . '/uploads';
        if (!is_dir($root)) {
            return 0;
        }
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $zip->addFile($file->getPathname(), 'uploads/' . $relative);
            $count++;
        }
        return $count;
    }

    private static function directory(): string
    {
        $dir = STORAGE_PATH . '/backups';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create storage/backups.');
        }
        return $dir;
    }

    private static function assertPassword(User $admin, string $password, string $refusedAction, int $id): void
    {
        if (!$admin->isSuperAdmin()) {
            throw HttpException::forbidden('Only the Super Admin manages backups.');
        }
        if ($password === '' || !password_verify($password, $admin->password_hash)) {
            AuditService::log($refusedAction, 'administration', 'Backup', $id, null, null, 'FAILURE', 'Password confirmation failed', $admin->id, $admin->role_name);
            throw ValidationException::withErrors(['password' => ['Your password is incorrect.']], 'Confirm with your own password to continue.');
        }
    }
}
