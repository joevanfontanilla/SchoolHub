<?php
/**
 * Restore a backup (docs/20). Replaces the ENTIRE database and ALL uploaded files.
 *
 *   php database/restore.php schoolhub-20260915-020000-scheduled.zip
 *   php database/restore.php C:\path\to\a\downloaded-backup.zip
 *
 * Deliberately command-line only, never from the website. Before anything changes it:
 *   1. verifies the archive (and its recorded checksum, when it is in the history),
 *   2. shows what will be restored and asks you to type the file name to confirm,
 *   3. makes a safety backup of the current state (type PRE_RESTORE).
 * The previous uploads folder is kept beside the new one (storage/uploads.before-restore-*).
 * Afterwards, run php database/migrate.php if the backup came from an older version.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('This script may only be run from the command line.');
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\AuditService;
use App\Services\BackupService;

$name = $argv[1] ?? '';
if ($name === '') {
    fwrite(STDERR, "\n  Usage: php database/restore.php <backup file name or path>\n\n");
    exit(1);
}

try {
    // Find the archive: a file in storage/backups, or a path to a downloaded copy.
    $recorded = Database::selectOne('SELECT * FROM backups WHERE filename = ?', [basename($name)]);
    $archive = is_file($name) ? realpath($name) : STORAGE_PATH . '/backups/' . basename($name);
    if (!is_file($archive)) {
        throw new RuntimeException("Backup not found: {$name}");
    }

    $check = BackupService::checkArchive($archive, $recorded['checksum'] ?? null);
    if (!$check['ok']) {
        throw new RuntimeException('The archive failed verification: ' . $check['message']);
    }
    $manifest = $check['manifest'];

    $database = (string) Config::get('db.database');
    echo "\n  RESTORE — this replaces everything in the database \"{$database}\"";
    echo !empty($manifest['includes_uploads']) ? " and all uploaded files.\n" : ".\n";
    echo '  Backup:   ' . basename($archive) . "\n";
    echo '  Made:     ' . ($manifest['created_at'] ?? '?') . ' (version ' . ($manifest['version'] ?? '?') . ")\n";
    echo '  Contains: ' . count((array) $manifest['tables']) . ' tables, ' . (int) ($manifest['upload_count'] ?? 0) . " uploaded files\n";
    echo $recorded === null ? "  Note:     this file is not in the backup history, so only its internal checksum was checked.\n" : '';
    echo "\n  Everything recorded since then will be lost (a safety backup is taken first).\n";
    echo '  Type the backup file name to continue: ';
    $answer = trim((string) fgets(STDIN));
    if ($answer !== basename($archive)) {
        echo "\n  Cancelled. Nothing was changed.\n\n";
        exit(1);
    }

    echo "\n  Making a safety backup of the current state…\n";
    $safetyId = BackupService::create(null, BackupService::PRE_RESTORE, 'Automatic, before restoring ' . basename($archive));
    $safety = BackupService::find($safetyId);
    echo "  Safety backup: storage/{$safety['path']}\n";

    $result = BackupService::restore($archive, $recorded['checksum'] ?? null, BackupService::connect(), STORAGE_PATH . '/uploads',
        static function (string $message): void { echo "  {$message}\n"; });

    // The restored database has no record of the safety backup or of this restore: add both.
    Database::reset();
    try {
        Database::query(
        'INSERT IGNORE INTO backups (filename, path, size_bytes, type, status, checksum, includes_uploads, table_count, upload_count, started_at, completed_at, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$safety['filename'], $safety['path'], $safety['size_bytes'], $safety['type'], 'COMPLETED', $safety['checksum'], $safety['includes_uploads'],
         $safety['table_count'], $safety['upload_count'], $safety['started_at'], $safety['completed_at'], $safety['notes']]
        );
    } catch (Throwable) {
        echo "  (The restored database predates backup history; run migrate.php, the safety backup file is still in storage/backups.)
";
    }
    AuditService::log('backup.restored', 'administration', 'Backup', null, null, [
        'restored' => basename($archive), 'safety_backup' => $safety['filename'], 'tables' => $result['tables'], 'uploads' => $result['uploads'],
    ], 'SUCCESS', 'Restored from the command line', null, 'System');

    printf("\n  Restored %d tables (%d statements) and %d uploaded files.\n", $result['tables'], $result['statements'], $result['uploads']);
    echo "  Next: php database/migrate.php (in case the backup predates the current version).\n\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\n  RESTORE FAILED: " . $e->getMessage() . "\n\n");
    exit(1);
}
