<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\User;

/**
 * In-app notifications (docs/16).
 *
 * Rows are written now, at the moment each workflow event happens, so later
 * phases never have to retrofit the status code. The inbox that reads them
 * arrives with the communication module.
 *
 * A notification must never reveal something its recipient is not allowed to
 * see (docs/16 "Principle"): callers pass only the recipient's own records.
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
        return (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND state = 'UNREAD'", [$user->id]);
    }
}
