<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Read-only queries over audit_logs and login_logs for the Super Admin (docs/19).
 *
 * Append-only: this class has no method that changes a row, and no route leads to
 * one. Entries are written only by AuditService and LoginThrottle.
 */
final class AuditLog
{
    /**
     * @param array<string,string> $filters from, to (Y-m-d), actor (name, username, or email), action (prefix), module, result, target_type, target_id
     * @return array<int,array<string,mixed>>
     */
    public static function search(array $filters, int $limit = 50, int $offset = 0): array
    {
        [$where, $bindings] = self::where($filters);
        $limit = max(1, min(10000, $limit));
        $offset = max(0, $offset);
        return Database::select(
            "SELECT a.*, u.username, CONCAT(u.first_name, ' ', u.last_name) AS actor_name
               FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
              WHERE {$where}
              ORDER BY a.created_at DESC, a.id DESC
              LIMIT {$limit} OFFSET {$offset}",
            $bindings
        );
    }

    public static function count(array $filters): int
    {
        [$where, $bindings] = self::where($filters);
        // The users join is needed only to filter by who; counting 100,000+ rows without it is much faster.
        $join = ($filters['actor'] ?? '') !== '' ? ' LEFT JOIN users u ON u.id = a.user_id' : '';
        return (int) Database::scalar("SELECT COUNT(*) FROM audit_logs a{$join} WHERE {$where}", $bindings);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne(
            "SELECT a.*, u.username, u.email, CONCAT(u.first_name, ' ', u.last_name) AS actor_name
               FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.id = ?",
            [$id]
        );
    }

    /** @return array<int,string> */
    public static function modules(): array
    {
        return array_column(Database::select('SELECT DISTINCT module FROM audit_logs ORDER BY module'), 'module');
    }

    /**
     * Sign-in attempts, newest first.
     *
     * @param array<string,string> $filters from, to, login, ip, result
     * @return array<int,array<string,mixed>>
     */
    public static function loginAttempts(array $filters, int $limit = 50, int $offset = 0): array
    {
        [$where, $bindings] = self::loginWhere($filters);
        $limit = max(1, min(1000, $limit));
        $offset = max(0, $offset);
        return Database::select(
            "SELECT l.*, u.username, CONCAT(u.first_name, ' ', u.last_name) AS user_name
               FROM login_logs l LEFT JOIN users u ON u.id = l.user_id
              WHERE {$where} ORDER BY l.attempted_at DESC, l.id DESC LIMIT {$limit} OFFSET {$offset}",
            $bindings
        );
    }

    public static function loginAttemptCount(array $filters): int
    {
        [$where, $bindings] = self::loginWhere($filters);
        return (int) Database::scalar("SELECT COUNT(*) FROM login_logs l WHERE {$where}", $bindings);
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private static function where(array $filters): array
    {
        $where = ['1 = 1'];
        $bindings = [];
        if (self::date($filters['from'] ?? '')) {
            $where[] = 'a.created_at >= ?';
            $bindings[] = $filters['from'] . ' 00:00:00';
        }
        if (self::date($filters['to'] ?? '')) {
            $where[] = 'a.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
            $bindings[] = $filters['to'];
        }
        if (($filters['actor'] ?? '') !== '') {
            $where[] = "(u.username LIKE ? OR u.email LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
            $like = '%' . self::escapeLike($filters['actor']) . '%';
            array_push($bindings, $like, $like, $like);
        }
        if (($filters['action'] ?? '') !== '') {
            $where[] = 'a.action LIKE ?';
            $bindings[] = self::escapeLike($filters['action']) . '%';
        }
        if (($filters['module'] ?? '') !== '') {
            $where[] = 'a.module = ?';
            $bindings[] = $filters['module'];
        }
        if (in_array($filters['result'] ?? '', ['SUCCESS', 'FAILURE'], true)) {
            $where[] = 'a.result = ?';
            $bindings[] = $filters['result'];
        }
        if (($filters['target_type'] ?? '') !== '') {
            $where[] = 'a.target_type = ?';
            $bindings[] = $filters['target_type'];
        }
        if (ctype_digit((string) ($filters['target_id'] ?? ''))) {
            $where[] = 'a.target_id = ?';
            $bindings[] = (int) $filters['target_id'];
        }
        if (ctype_digit((string) ($filters['user_id'] ?? ''))) {
            $where[] = 'a.user_id = ?';
            $bindings[] = (int) $filters['user_id'];
        }
        return [implode(' AND ', $where), $bindings];
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private static function loginWhere(array $filters): array
    {
        $where = ['1 = 1'];
        $bindings = [];
        if (self::date($filters['from'] ?? '')) {
            $where[] = 'l.attempted_at >= ?';
            $bindings[] = $filters['from'] . ' 00:00:00';
        }
        if (self::date($filters['to'] ?? '')) {
            $where[] = 'l.attempted_at < DATE_ADD(?, INTERVAL 1 DAY)';
            $bindings[] = $filters['to'];
        }
        if (($filters['login'] ?? '') !== '') {
            $where[] = 'l.username_attempted LIKE ?';
            $bindings[] = '%' . self::escapeLike($filters['login']) . '%';
        }
        if (($filters['ip'] ?? '') !== '') {
            $where[] = 'l.ip_address = ?';
            $bindings[] = $filters['ip'];
        }
        if (($filters['result'] ?? '') !== '') {
            $where[] = 'l.result = ?';
            $bindings[] = $filters['result'];
        }
        if (ctype_digit((string) ($filters['user_id'] ?? ''))) {
            $where[] = 'l.user_id = ?';
            $bindings[] = (int) $filters['user_id'];
        }
        return [implode(' AND ', $where), $bindings];
    }

    private static function date(string $value): bool
    {
        $parsed = \DateTime::createFromFormat('!Y-m-d', $value);
        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
