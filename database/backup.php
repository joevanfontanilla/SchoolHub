<?php
/**
 * Create a backup of the database (and uploaded files, per System Settings) (docs/20).
 *
 *   php database/backup.php               a manual backup
 *   php database/backup.php --scheduled   for Windows Task Scheduler, daily at 02:00
 *   php database/backup.php --verify=ID   check an existing backup
 *
 * The archive is written to storage/backups, checksummed, and recorded in the backup
 * history. Older backups beyond the retention setting are removed afterwards.
 * Exits with status 1 on failure, so the scheduler can report it.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('This script may only be run from the command line.');
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\BackupService;
use App\Services\SystemHealth;

$arguments = array_slice($argv, 1);

try {
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, '--verify=')) {
            $result = BackupService::verify(null, (int) substr($argument, 9));
            echo ($result['ok'] ? '  OK: ' : '  FAILED: ') . $result['message'] . "\n";
            exit($result['ok'] ? 0 : 1);
        }
    }

    $type = in_array('--scheduled', $arguments, true) ? BackupService::SCHEDULED : BackupService::MANUAL;
    $started = microtime(true);
    $id = BackupService::create(null, $type, $type === BackupService::SCHEDULED ? 'Scheduled task' : 'Command line');
    $backup = BackupService::find($id);

    printf("\n  Backup created: storage/%s\n  %s, %d tables, %d uploaded files, %.1f seconds\n  SHA-256 %s\n\n",
        $backup['path'], SystemHealth::bytes((int) $backup['size_bytes']), $backup['table_count'], $backup['upload_count'],
        microtime(true) - $started, $backup['checksum']);
} catch (Throwable $e) {
    fwrite(STDERR, "\n  BACKUP FAILED: " . $e->getMessage() . "\n\n");
    exit(1);
}
