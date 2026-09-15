<?php
/**
 * Reading migration files, shared by database/migrate.php and database/build_install_sql.php.
 *
 * Migration files are database/migrations/NNN_name.sql with an "-- @up" section and an
 * "-- @down" section. Statements are separated by semicolons.
 */

declare(strict_types=1);

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

/** The table that records which migrations ran. */
function migrationsTableSql(): string
{
    return 'CREATE TABLE IF NOT EXISTS migrations (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            filename   VARCHAR(255) NOT NULL,
            batch      INT NOT NULL,
            applied_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_migrations_filename (filename)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
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
    // Remove comment lines BEFORE splitting: a semicolon inside a comment
    // ("... as a constraint; a time overlap ...") would otherwise cut a
    // statement in half and leave a half-applied migration behind.
    $lines = array_filter(
        explode("\n", str_replace("\r\n", "\n", $sql)),
        static fn (string $line) => !str_starts_with(ltrim($line), '--')
    );
    $sql = implode("\n", $lines);

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
