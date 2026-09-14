<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Read queries for notifications. Written by NotificationService; the inbox page
 * comes with the notifications module.
 */
final class Notification
{
    public static function unreadCount(int $userId): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId]);
    }
}
