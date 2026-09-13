<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;

/**
 * Login rate limiting, counted from login_logs per account and per IP (docs/07).
 */
final class LoginThrottle
{
    public static function record(?int $userId, string $username, string $ip, string $userAgent, string $result): void
    {
        Database::insert('login_logs', [
            'user_id'            => $userId,
            'username_attempted' => mb_substr($username, 0, 150),
            'ip_address'         => $ip,
            'user_agent'         => $userAgent,
            'result'             => $result,
            'attempted_at'       => date('Y-m-d H:i:s'),
        ]);
    }

    /** Recent failures for this login name, since the last successful sign-in. */
    public static function recentFailuresForAccount(string $username): int
    {
        $window = (int) Config::get('security.ip_window_minutes', 15);
        $since = date('Y-m-d H:i:s', time() - ($window * 60));

        return (int) Database::scalar(
            "SELECT COUNT(*) FROM login_logs
              WHERE username_attempted = ?
                AND result = 'FAILED'
                AND attempted_at > ?",
            [$username, $since]
        );
    }

    public static function recentFailuresForIp(string $ip): int
    {
        $window = (int) Config::get('security.ip_window_minutes', 15);
        $since = date('Y-m-d H:i:s', time() - ($window * 60));

        return (int) Database::scalar(
            "SELECT COUNT(*) FROM login_logs
              WHERE ip_address = ?
                AND result IN ('FAILED','LOCKED_OUT')
                AND attempted_at > ?",
            [$ip, $since]
        );
    }

    public static function ipIsBlocked(string $ip): bool
    {
        return self::recentFailuresForIp($ip) >= (int) Config::get('security.ip_max_attempts', 20);
    }

    /** Clear the failure counter after a successful sign-in. */
    public static function clearFor(int $userId): void
    {
        Database::update('users', [
            'failed_login_attempts' => 0,
            'locked_until'          => null,
        ], ['id' => $userId]);
    }
}
