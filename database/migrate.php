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

function migrationPath(): string
{
    return BASE_PATH . '/database/migrations';
}

/** @return array<int,string> */
function migrationFiles(): array
{
    $files = glob(migrationPath() . '/*.sql') ?: [];
    $names = array_map('basename', $files);
    sort($names, SORT_NATURAL);
    return $names;
}

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
    Database::connection()->exec(
        'CREATE TABLE IF NOT EXISTS migrations (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            filename   VARCHAR(255) NOT NULL,
            batch      INT NOT NULL,
            applied_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_migrations_filename (filename)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/** @return array<int,string> */
function appliedMigrations(): array
{
    return array_column(Database::select('SELECT filename FROM migrations ORDER BY id'), 'filename');
}

/** Split a migration file into its up and down statement lists. */
function parseMigration(string $filename): array
{
    $sql = (string) file_get_contents(migrationPath() . '/' . $filename);

    $upPos = strpos($sql, '-- @up');
    $downPos = strpos($sql, '-- @down');

    if ($upPos === false) {
        throw new RuntimeException("{$filename} is missing an '-- @up' section.");
    }
    if ($downPos === false) {
        throw new RuntimeException("{$filename} is missing an '-- @down' section — every migration must be reversible.");
    }

    $up = substr($sql, $upPos + 6, $downPos - $upPos - 6);
    $down = substr($sql, $downPos + 8);

    return ['up' => splitStatements($up), 'down' => splitStatements($down)];
}

/** @return array<int,string> */
function splitStatements(string $sql): array
{
    // Sufficient for DDL. If a migration ever needs a trigger or stored
    // procedure body, give it its own runner rather than complicating this.
    $parts = array_map('trim', explode(';', $sql));

    return array_values(array_filter($parts, static function (string $part): bool {
        if ($part === '') {
            return false;
        }
        // Skip chunks that are only comments.
        $lines = array_filter(
            array_map('trim', explode("\n", $part)),
            static fn ($l) => $l !== '' && !str_starts_with($l, '--')
        );
        return $lines !== [];
    }));
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
