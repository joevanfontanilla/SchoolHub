<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;

/**
 * System health indicators for the Super Admin (docs/20). Read-only.
 *
 * Each check is OK, WARN, or FAIL with a plain explanation. Paths and log lines are
 * shown only to the Super Admin.
 */
final class SystemHealth
{
    public const REQUIRED_EXTENSIONS = ['pdo_mysql', 'mbstring', 'fileinfo', 'openssl', 'zip'];

    /**
     * @param array<string,mixed>|null $config override for tests: app.env, app.debug, session.secure
     * @return array<int,array{group:string,label:string,status:string,detail:string}>
     */
    public static function checks(?array $config = null): array
    {
        $env = (string) ($config['app.env'] ?? Config::get('app.env', 'local'));
        $debug = (bool) ($config['app.debug'] ?? Config::get('app.debug', false));
        $secure = (bool) ($config['session.secure'] ?? Config::get('session.secure', false));
        $checks = [];
        $add = static function (string $group, string $label, string $status, string $detail) use (&$checks): void {
            $checks[] = compact('group', 'label', 'status', 'detail');
        };

        // Application
        $add('Application', 'Version', 'OK', (string) Config::get('app.version', 'unknown') . ' · environment ' . $env);
        $add('Application', 'Debug mode', $env === 'production' && $debug ? 'FAIL' : 'OK',
            $debug ? ($env === 'production' ? 'On in production: error details are shown to visitors. Set app.debug to false.' : 'On (fine for local development).') : 'Off');
        $add('Application', 'Secure session cookie', $env === 'production' && !$secure ? 'WARN' : 'OK',
            $secure ? 'On' : ($env === 'production' ? 'Off in production: enable session.secure once the site uses HTTPS.' : 'Off (fine without HTTPS locally).'));

        // PHP
        $add('Server', 'PHP version', version_compare(PHP_VERSION, '8.2.0', '>=') ? 'OK' : 'FAIL', PHP_VERSION . ' (8.2 or newer required)');
        $missing = array_values(array_filter(self::REQUIRED_EXTENSIONS, static fn ($ext) => !extension_loaded($ext)));
        $add('Server', 'PHP extensions', $missing === [] ? 'OK' : 'FAIL', $missing === [] ? implode(', ', self::REQUIRED_EXTENSIONS) : 'Missing: ' . implode(', ', $missing));

        // Database
        try {
            $version = (string) Database::scalar('SELECT VERSION()');
            $add('Database', 'Connection', 'OK', $version);
            $pending = self::pendingMigrations();
            $add('Database', 'Migrations', $pending === [] ? 'OK' : 'WARN', $pending === [] ? 'All applied' : count($pending) . ' pending: run php database/migrate.php');
        } catch (\Throwable) {
            $add('Database', 'Connection', 'FAIL', 'The database cannot be reached.');
        }

        // Storage
        foreach (['uploads', 'backups', 'logs'] as $folder) {
            $path = STORAGE_PATH . '/' . $folder;
            $add('Storage', "storage/{$folder} writable", is_dir($path) && is_writable($path) ? 'OK' : 'FAIL', is_dir($path) ? (is_writable($path) ? 'Writable' : 'Not writable') : 'Missing');
        }
        $free = @disk_free_space(STORAGE_PATH);
        if ($free !== false) {
            $add('Storage', 'Free disk space', $free < 1024 ** 3 ? ($free < 200 * 1024 ** 2 ? 'FAIL' : 'WARN') : 'OK', self::bytes((int) $free));
        }

        // Backups
        try {
            $last = Database::selectOne("SELECT * FROM backups WHERE status = 'COMPLETED' ORDER BY completed_at DESC LIMIT 1");
            $failed = Database::selectOne("SELECT * FROM backups WHERE status = 'FAILED' ORDER BY started_at DESC LIMIT 1");
            if ($last === null) {
                $add('Backups', 'Latest backup', 'WARN', 'No backup has been made yet.');
            } else {
                $hours = (time() - strtotime((string) $last['completed_at'])) / 3600;
                $add('Backups', 'Latest backup', $hours > 26 ? 'WARN' : 'OK', dt($last['completed_at']) . ' · ' . self::bytes((int) $last['size_bytes']) . ($hours > 26 ? ' · older than a day' : ''));
            }
            if ($failed !== null && ($last === null || $failed['started_at'] > $last['completed_at'])) {
                $add('Backups', 'Last attempt', 'FAIL', 'Failed ' . dt($failed['started_at']) . ': ' . str_limit((string) $failed['error'], 120));
            }
        } catch (\Throwable) {
            $add('Backups', 'Latest backup', 'WARN', 'Backup history is not available.');
        }

        // Activity
        try {
            $since = date('Y-m-d H:i:s', time() - 86400);
            $failedLogins = (int) Database::scalar("SELECT COUNT(*) FROM login_logs WHERE result <> 'SUCCESS' AND attempted_at > ?", [$since]);
            $locked = (int) Database::scalar("SELECT COUNT(*) FROM users WHERE status = 'Locked'");
            $add('Security', 'Failed sign-ins (24 hours)', $failedLogins > 50 ? 'WARN' : 'OK', (string) $failedLogins);
            $add('Security', 'Locked accounts', $locked > 0 ? 'WARN' : 'OK', (string) $locked);
        } catch (\Throwable) {
        }
        $auditFailures = self::recentLines(STORAGE_PATH . '/logs/audit-failures.log', 7);
        $add('Security', 'Audit write failures (7 days)', $auditFailures > 0 ? 'WARN' : 'OK', $auditFailures > 0 ? "{$auditFailures} in storage/logs/audit-failures.log" : 'None');

        return $checks;
    }

    /** @return array<int,string> the last lines of the PHP error log (newest last) */
    public static function recentErrors(int $lines = 20): array
    {
        $file = STORAGE_PATH . '/logs/php-error.log';
        if (!is_file($file) || filesize($file) === 0) {
            return [];
        }
        $size = filesize($file);
        $handle = fopen($file, 'rb');
        fseek($handle, max(0, $size - 64 * 1024));
        $tail = (string) stream_get_contents($handle);
        fclose($handle);
        $all = preg_split('/\R/', trim($tail)) ?: [];
        return array_slice($all, -$lines);
    }

    /** 'OK', 'WARN', or 'FAIL' for a set of checks: the worst one. */
    public static function overall(array $checks): string
    {
        $statuses = array_column($checks, 'status');
        return in_array('FAIL', $statuses, true) ? 'FAIL' : (in_array('WARN', $statuses, true) ? 'WARN' : 'OK');
    }

    /** @return array<int,string> */
    public static function pendingMigrations(): array
    {
        $files = array_map('basename', glob(BASE_PATH . '/database/migrations/*.sql') ?: []);
        $applied = array_column(Database::select('SELECT filename FROM migrations'), 'filename');
        return array_values(array_diff($files, $applied));
    }

    public static function bytes(int $bytes): string
    {
        foreach (['GB' => 1024 ** 3, 'MB' => 1024 ** 2, 'KB' => 1024] as $unit => $size) {
            if ($bytes >= $size) {
                return number_format($bytes / $size, 1) . ' ' . $unit;
            }
        }
        return $bytes . ' bytes';
    }

    /** Lines of a log written in the last $days days (each line starts with [ISO time]). */
    private static function recentLines(string $file, int $days): int
    {
        if (!is_file($file)) {
            return 0;
        }
        $since = time() - $days * 86400;
        return count(array_filter(file($file, FILE_IGNORE_NEW_LINES) ?: [], static fn ($l) => preg_match('/^\[([^\]]+)\]/', $l, $m) === 1 && strtotime($m[1]) >= $since));
    }
}
