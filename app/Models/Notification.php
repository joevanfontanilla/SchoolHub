<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Read queries for a user's own notifications (docs/16). Written and changed only
 * through NotificationService. Every query is scoped to the owner.
 */
final class Notification
{
    public const UNREAD   = 'UNREAD';
    public const READ     = 'READ';
    public const ARCHIVED = 'ARCHIVED';

    public static function unreadCount(int $userId): int
    {
        return (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND state = 'UNREAD'", [$userId]);
    }

    /**
     * The inbox: 'unread', 'all' (unread and read), or 'archived'. Newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function inbox(int $userId, string $filter = 'all', int $limit = 20, int $offset = 0): array
    {
        [$where, $bindings] = self::filterWhere($userId, $filter);
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        return Database::select(
            "SELECT id, `type`, title, body, link_url, state, read_at, created_at FROM notifications
              WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT {$limit} OFFSET {$offset}",
            $bindings
        );
    }

    public static function count(int $userId, string $filter = 'all'): int
    {
        [$where, $bindings] = self::filterWhere($userId, $filter);
        return (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE {$where}", $bindings);
    }

    /** @return array<int,array<string,mixed>> the latest few not archived, for the bell menu */
    public static function latest(int $userId, int $limit = 5): array
    {
        return self::inbox($userId, 'all', $limit);
    }

    /** @return array<string,mixed>|null only when it belongs to this user */
    public static function findOwned(int $id, int $userId): ?array
    {
        return Database::selectOne('SELECT * FROM notifications WHERE id = ? AND user_id = ?', [$id, $userId]);
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private static function filterWhere(int $userId, string $filter): array
    {
        return match ($filter) {
            'unread'   => ["user_id = ? AND state = 'UNREAD'", [$userId]],
            'archived' => ["user_id = ? AND state = 'ARCHIVED'", [$userId]],
            default    => ["user_id = ? AND state <> 'ARCHIVED'", [$userId]],
        };
    }
}
