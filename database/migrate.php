<?php
/**
 * Migration runner (no Composer, per docs/00).
 *
 *   php database/migrate.php            apply pending migrations
 *   php database/migrate.php --status   list applied and pending
 *   php database/migrate.php --rollback roll back the last batch
 *   php database/migrate.php --fresh    drop everything and re-apply (local only)
 *
 * Migration files are database/migrations/NNN_name.sql with an "-- @up" section
 * and an "-- @down" section. Statements are separated by semicolons.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('This script may only be run from the command line.');
}

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/lib/migrations.php';

use App\Core\Config;
use App\Core\Database;

$options = array_slice($argv, 1);
$command = $options[0] ?? '--migrate';

try {
    ensureDatabaseExists();
    ensureMigrationsTable();

    match ($command) {
        '--status'   => showStatus(),
        '--rollback' => rollback(),
        '--fresh'    => fresh(),
        default      => migrate(),
    };
} catch (Throwable $e) {
    fwrite(STDERR, "\n  ERROR: " . $e->getMessage() . "\n\n");
    exit(1);
}

// ---------------------------------------------------------------------------

function ensureDatabaseExists(): void
{
    $name = (string) Config::get('db.database');

    $pdo = Database::serverConnection();
    $exists = $pdo->query(
        'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ' . $pdo->quote($name)
    )->fetchColumn();

    if ($exists === false) {
        $pdo->exec(sprintf(
            'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            str_replace('`', '', $name)
        ));
        echo "  created database `{$name}`\n";
    }
}

function ensureMigrationsTable(): void
{
    Database::connection()->exec(migrationsTableSql());
}

/** @return array<int,string> */
function appliedMigrations(): array
{
    return array_column(Database::select('SELECT filename FROM migrations ORDER BY id'), 'filename');
}

function migrate(): void
{
    $applied = appliedMigrations();
    $pending = array_values(array_diff(migrationFiles(), $applied));

    if ($pending === []) {
        echo "  Nothing to migrate — the database is up to date.\n";
        return;
    }

    $batch = (int) Database::scalar('SELECT COALESCE(MAX(batch), 0) FROM migrations') + 1;

    foreach ($pending as $filename) {
        $statements = parseMigration($filename)['up'];

        echo "  migrating  {$filename} ... ";
        try {
            foreach ($statements as $statement) {
                Database::connection()->exec($statement);
            }
            Database::insert('migrations', [
                'filename'   => $filename,
                'batch'      => $batch,
                'applied_at' => date('Y-m-d H:i:s'),
            ]);
            echo "done\n";
        } catch (Throwable $e) {
            echo "FAILED\n";
            throw new RuntimeException("{$filename}: " . $e->getMessage(), 0, $e);
        }
    }

    echo "\n  Applied " . count($pending) . " migration(s) in batch {$batch}.\n";
}

function rollback(): void
{
    $batch = (int) Database::scalar('SELECT COALESCE(MAX(batch), 0) FROM migrations');
    if ($batch === 0) {
        echo "  Nothing to roll back.\n";
        return;
    }

    $rows = Database::select('SELECT filename FROM migrations WHERE batch = ? ORDER BY id DESC', [$batch]);

    foreach ($rows as $row) {
        $filename = (string) $row['filename'];
        echo "  rolling back  {$filename} ... ";

        foreach (parseMigration($filename)['down'] as $statement) {
            Database::connection()->exec($statement);
        }
        Database::query('DELETE FROM migrations WHERE filename = ?', [$filename]);
        echo "done\n";
    }

    echo "\n  Rolled back batch {$batch}.\n";
}

function fresh(): void
{
    if (Config::get('app.env') === 'production') {
        throw new RuntimeException('--fresh is refused in production.');
    }

    echo "  Dropping all tables...\n";

    $pdo = Database::connection();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (Database::select('SHOW TABLES') as $row) {
        $table = array_values($row)[0];
        $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    ensureMigrationsTable();
    migrate();
}

function showStatus(): void
{
    $applied = appliedMigrations();

    echo "\n  Migration status\n  ----------------\n";
    foreach (migrationFiles() as $filename) {
        $mark = in_array($filename, $applied, true) ? '[x] applied' : '[ ] pending';
        echo "  {$mark}  {$filename}\n";
    }
    echo "\n";
}
