<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;
use RuntimeException;

/**
 * PDO wrapper. Prepared statements only — no method here accepts raw SQL built
 * from user input (docs/00).
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static int $transactionDepth = 0;

    /** Statements run in this request. Reported in a response header in local debug mode (docs/22). */
    private static int $queryCount = 0;

    public static function queryCount(): int
    {
        return self::$queryCount;
    }

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host    = (string) Config::get('db.host', '127.0.0.1');
        $port    = (int) Config::get('db.port', 3306);
        $name    = (string) Config::get('db.database', 'schoolhub');
        $charset = (string) Config::get('db.charset', 'utf8mb4');

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);

        try {
            self::$pdo = new PDO(
                $dsn,
                (string) Config::get('db.username', 'root'),
                (string) Config::get('db.password', ''),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    // Real prepared statements, not client-side emulation.
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => self::sessionSetup(),
                ]
            );
        } catch (\PDOException $e) {
            // Never surface credentials or the DSN to the caller.
            throw new RuntimeException('Database connection failed. Check config/config.local.php.', 0, $e);
        }

        return self::$pdo;
    }

    /** Connect without selecting a database — used by the migration runner. */
    public static function serverConnection(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=%s',
            (string) Config::get('db.host', '127.0.0.1'),
            (int) Config::get('db.port', 3306),
            (string) Config::get('db.charset', 'utf8mb4')
        );

        return new PDO(
            $dsn,
            (string) Config::get('db.username', 'root'),
            (string) Config::get('db.password', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_INIT_COMMAND => self::sessionSetup()]
        );
    }

    /**
     * Run on every connection, so the database behaves the same on XAMPP's MariaDB and on a
     * hosting provider's MySQL 8 (docs/23): MySQL 8's strict SQL mode, which refuses loose
     * GROUP BY queries and invalid dates that MariaDB's default mode lets through; and the
     * application's time zone, so CURRENT_TIMESTAMP agrees with PHP's clock even when the
     * database server runs on UTC or another country's time.
     */
    public static function sessionSetup(): string
    {
        return "SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION', time_zone = '" . date('P') . "'";
    }

    public static function query(string $sql, array $bindings = []): PDOStatement
    {
        self::$queryCount++;
        $statement = self::connection()->prepare($sql);
        $statement->execute($bindings);
        return $statement;
    }

    /** @return array<int,array<string,mixed>> */
    public static function select(string $sql, array $bindings = []): array
    {
        return self::query($sql, $bindings)->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = self::query($sql, $bindings)->fetch();
        return $row === false ? null : $row;
    }

    public static function scalar(string $sql, array $bindings = []): mixed
    {
        $value = self::query($sql, $bindings)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** Insert a row from an associative array and return the new id. */
    public static function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            '`' . implode('`, `', $columns) . '`',
            implode(', ', array_fill(0, count($columns), '?'))
        );
        self::query($sql, array_values($data));
        return (int) self::connection()->lastInsertId();
    }

    /** Update rows matching a simple equality WHERE array. */
    public static function update(string $table, array $data, array $where): int
    {
        $set = implode(', ', array_map(static fn ($c) => "`$c` = ?", array_keys($data)));
        $conditions = implode(' AND ', array_map(static fn ($c) => "`$c` = ?", array_keys($where)));
        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, $set, $conditions);

        return self::query($sql, array_merge(array_values($data), array_values($where)))->rowCount();
    }

    /**
     * Run a callback inside a transaction. Nested calls join the outer
     * transaction rather than starting a second one.
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();

        if (self::$transactionDepth === 0) {
            $pdo->beginTransaction();
        }
        self::$transactionDepth++;

        try {
            $result = $callback();
            self::$transactionDepth--;
            if (self::$transactionDepth === 0) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            self::$transactionDepth--;
            if (self::$transactionDepth === 0 && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Test support: drop the cached connection. */
    public static function reset(): void
    {
        self::$pdo = null;
        self::$transactionDepth = 0;
    }
}
