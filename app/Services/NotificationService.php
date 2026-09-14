<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Models\Notification;
use App\Models\User;

/**
 * In-app notifications (docs/16).
 *
 * Rows are written at the moment each workflow event happens. The inbox reads them
 * (/portal/notifications). States: UNREAD -> READ (opened, or "mark all read") ->
 * ARCHIVED (hidden from the inbox, restorable). Notifications are never deleted by
 * their owner.
 *
 * A notification must never reveal something its recipient is not allowed to
 * see (docs/16 "Principle"): callers pass only the recipient's own records, and
 * opening one still goes through the target page's own authorization.
 */
final class NotificationService
{
    /** @param string|null $link a RELATIVE application path; rendered through url() */
    public static function notify(int $userId, string $type, string $title, ?string $body = null, ?string $link = null): void
    {
        Database::insert('notifications', [
            'user_id'  => $userId,
            'type'     => $type,
            'title'    => mb_substr($title, 0, 200),
            'body'     => $body,
            'link_url' => $link,
            'state'    => 'UNREAD',
        ]);
    }

    /** Notify every active user holding a role, e.g. all registrars. */
    public static function notifyRole(string $roleName, string $type, string $title, ?string $body = null, ?string $link = null): void
    {
        $users = Database::select(
            "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = ? AND u.status = 'Active'",
            [$roleName]
        );
        foreach ($users as $user) {
            self::notify((int) $user['id'], $type, $title, $body, $link);
        }
    }

    public static function unreadCount(User $user): int
    {
        return Notification::unreadCount($user->id);
    }

    /**
     * Mark one of the user's notifications read and return where it points: its link if
     * that is a safe in-app path, otherwise null. Another user's notification is a 404.
     */
    public static function open(User $user, int $id): ?string
    {
        $notification = self::owned($user, $id);
        if ($notification['state'] === Notification::UNREAD) {
            Database::query("UPDATE notifications SET state = 'READ', read_at = ? WHERE id = ? AND user_id = ?", [date('Y-m-d H:i:s'), $id, $user->id]);
        }
        return self::safeLink($notification['link_url']);
    }

    /** @return int how many were marked read */
    public static function markAllRead(User $user): int
    {
        return Database::query(
            "UPDATE notifications SET state = 'READ', read_at = ? WHERE user_id = ? AND state = 'UNREAD'",
            [date('Y-m-d H:i:s'), $user->id]
        )->rowCount();
    }

    public static function archive(User $user, int $id): void
    {
        self::owned($user, $id);
        Database::query(
            "UPDATE notifications SET state = 'ARCHIVED', read_at = COALESCE(read_at, ?) WHERE id = ? AND user_id = ?",
            [date('Y-m-d H:i:s'), $id, $user->id]
        );
    }

    public static function restore(User $user, int $id): void
    {
        $notification = self::owned($user, $id);
        if ($notification['state'] === Notification::ARCHIVED) {
            Database::query("UPDATE notifications SET state = 'READ' WHERE id = ? AND user_id = ?", [$id, $user->id]);
        }
    }

    /**
     * A stored link is followed only if it is an application path: it starts with a
     * single "/", and has no scheme, backslash, or control character. Anything else
     * (for example "//evil.example" or "https://…") is ignored, so a notification can
     * never become an open redirect.
     */
    public static function safeLink(?string $link): ?string
    {
        if ($link === null || $link === '') {
            return null;
        }
        if (!preg_match('#^/(?![/\\\\])[A-Za-z0-9/_\-.~%?=&+]*(\#[A-Za-z0-9_\-]*)?$#', $link) || str_contains($link, '..')) {
            return null;
        }
        return $link;
    }

    /** @return array<string,mixed> */
    private static function owned(User $user, int $id): array
    {
        $notification = Notification::findOwned($id, $user->id);
        if ($notification === null) {
            throw HttpException::notFound('That notification was not found.');
        }
        return $notification;
    }
}
