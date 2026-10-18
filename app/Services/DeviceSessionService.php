<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Session;
use App\Core\ValidationException;
use App\Models\User;

/**
 * One device at a time, and the sign-in alert (docs/07 "One device at a time").
 *
 * A correct password entered while the account is signed in on another device does not sign the new browser in: it waits (PENDING) until the owner approves it
 * on the device they are using, denies it, or that device goes idle. Only a hash of each browser's random token is stored.
 */
final class DeviceSessionService
{
    /** A device counts as "in use" when it was seen this recently. */
    public const IDLE_MINUTES = 30;
    /** A waiting request nobody answered. */
    public const PENDING_MINUTES = 10;

    private const TOKEN_KEY = '_device_token';
    private const PENDING_KEY = '_pending_device_token';

    // -- sign-in ----------------------------------------------------------------------

    /**
     * Register a browser whose password was correct.
     *
     * @param bool $adopt register an already signed-in browser: always active, never an alert
     * @return array{state:string,token:string,id:int} state is 'active' (sign in now) or 'pending' (wait for the owner)
     */
    public static function begin(User $user, string $ip, string $userAgent, bool $adopt = false): array
    {
        $token = bin2hex(random_bytes(32));
        $label = self::deviceLabel($userAgent);
        $inUse = !$adopt && self::inUse($user->id) !== [];

        if ($inUse) {
            // The same browser asking again replaces its earlier request instead of stacking alerts.
            Database::query(
                "UPDATE user_sessions SET status = 'ENDED', ended_at = NOW(), end_reason = 'replaced'
                  WHERE user_id = ? AND status = 'PENDING' AND ip_address = ? AND device_label = ?",
                [$user->id, $ip, $label]
            );
        }

        $id = Database::insert('user_sessions', [
            'user_id' => $user->id, 'token_hash' => self::hash($token), 'status' => $inUse ? 'PENDING' : 'ACTIVE',
            'device_label' => $label, 'ip_address' => mb_substr($ip, 0, 45), 'last_seen_at' => date('Y-m-d H:i:s'),
        ]);

        if ($inUse) {
            NotificationService::notify(
                $user->id,
                'security',
                'Someone is trying to sign in to your account',
                "A sign-in with your password is waiting for your approval: {$label}, IP address {$ip}, " . date('g:i A') . '. If this was not you, deny it.',
                '/account#devices'
            );
            AuditService::log('login.device_pending', 'auth', 'User', $user->id, null, ['device' => $label, 'ip' => $ip], 'SUCCESS', null, $user->id, $user->role_name);
        }

        return ['state' => $inUse ? 'pending' : 'active', 'token' => $token, 'id' => $id];
    }

    public static function rememberActive(string $token): void
    {
        Session::put(self::TOKEN_KEY, $token);
        Session::forget(self::PENDING_KEY);
    }

    public static function rememberPending(string $token): void
    {
        Session::put(self::PENDING_KEY, $token);
    }

    public static function pendingToken(): ?string
    {
        $token = Session::get(self::PENDING_KEY);
        return is_string($token) && $token !== '' ? $token : null;
    }

    public static function currentToken(): ?string
    {
        $token = Session::get(self::TOKEN_KEY);
        return is_string($token) && $token !== '' ? $token : null;
    }

    // -- the waiting browser ------------------------------------------------------------

    /**
     * Where a waiting browser stands.
     *
     * @return array{state:string,user:?User} waiting | approved | denied | expired | gone; 'approved' carries the user to sign in
     */
    public static function resolvePending(?string $token): array
    {
        $row = $token === null ? null : Database::selectOne('SELECT * FROM user_sessions WHERE token_hash = ?', [self::hash($token)]);
        if ($row === null) {
            return ['state' => 'gone', 'user' => null];
        }

        $user = User::find((int) $row['user_id']);
        if ($user === null || !$user->canSignIn()) {
            return ['state' => 'gone', 'user' => null];
        }

        if ($row['status'] === 'ACTIVE') {
            return ['state' => 'approved', 'user' => $user];
        }
        if ($row['status'] === 'DENIED') {
            return ['state' => 'denied', 'user' => null];
        }
        if ($row['status'] !== 'PENDING') {
            return ['state' => 'gone', 'user' => null];
        }

        if (strtotime((string) $row['created_at']) < time() - self::PENDING_MINUTES * 60) {
            Database::update('user_sessions', ['status' => 'ENDED', 'ended_at' => date('Y-m-d H:i:s'), 'end_reason' => 'expired'], ['id' => $row['id']]);
            AuditService::log('login.device_expired', 'auth', 'User', $user->id, null, ['device' => $row['device_label']], 'FAILURE', null, $user->id, $user->role_name);
            return ['state' => 'expired', 'user' => null];
        }

        // The device that was in use has gone idle or signed out: nobody is left to ask.
        if (self::inUse($user->id) === []) {
            Database::update('user_sessions', ['status' => 'ACTIVE', 'last_seen_at' => date('Y-m-d H:i:s')], ['id' => $row['id']]);
            AuditService::log('login.device_auto_allowed', 'auth', 'User', $user->id, null, ['device' => $row['device_label']], 'SUCCESS', null, $user->id, $user->role_name);
            return ['state' => 'approved', 'user' => $user];
        }

        return ['state' => 'waiting', 'user' => null];
    }

    /** The waiting browser gives up. */
    public static function cancelPending(?string $token): void
    {
        if ($token !== null) {
            Database::query("UPDATE user_sessions SET status = 'ENDED', ended_at = NOW(), end_reason = 'cancelled' WHERE token_hash = ? AND status = 'PENDING'", [self::hash($token)]);
        }
        Session::forget(self::PENDING_KEY);
    }

    // -- the signed-in browser ----------------------------------------------------------

    /**
     * Called on every signed-in request. False when this browser was signed out elsewhere (or its row is gone), so the caller signs it out.
     * A browser signed in before this feature existed is adopted.
     */
    public static function touch(User $user, bool $background = false): bool
    {
        $token = self::currentToken();
        if ($token === null) {
            // A browser signed in before this feature existed: adopted quietly, with no alert.
            $created = self::begin($user, (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), true);
            Session::put(self::TOKEN_KEY, $created['token']);
            return true;
        }

        $row = Database::selectOne('SELECT id, user_id, status, last_seen_at FROM user_sessions WHERE token_hash = ?', [self::hash($token)]);
        if ($row === null || (int) $row['user_id'] !== $user->id || $row['status'] !== 'ACTIVE') {
            return false;
        }
        if (!$background && strtotime((string) $row['last_seen_at']) < time() - 30) {
            Database::update('user_sessions', ['last_seen_at' => date('Y-m-d H:i:s')], ['id' => $row['id']]);
        }

        return true;
    }

    /** This browser signs out. */
    public static function endCurrent(string $reason = 'signed_out'): void
    {
        $token = self::currentToken();
        if ($token !== null) {
            Database::query("UPDATE user_sessions SET status = 'ENDED', ended_at = NOW(), end_reason = ? WHERE token_hash = ? AND status = 'ACTIVE'", [$reason, self::hash($token)]);
        }
    }

    // -- the owner's answers -------------------------------------------------------------

    /** Waiting requests for an account, newest first. @return array<int,array<string,mixed>> */
    public static function pendingFor(int $userId): array
    {
        return Database::select(
            "SELECT id, device_label, ip_address, created_at FROM user_sessions
              WHERE user_id = ? AND status = 'PENDING' AND created_at >= DATE_SUB(NOW(), INTERVAL " . self::PENDING_MINUTES . " MINUTE)
              ORDER BY created_at DESC",
            [$userId]
        );
    }

    /** Devices in use, with whether each is this browser. @return array<int,array<string,mixed>> */
    public static function devicesFor(int $userId): array
    {
        $current = self::currentToken();
        $currentHash = $current === null ? '' : self::hash($current);
        $rows = Database::select(
            "SELECT id, token_hash, device_label, ip_address, created_at, last_seen_at FROM user_sessions
              WHERE user_id = ? AND status = 'ACTIVE' AND last_seen_at >= DATE_SUB(NOW(), INTERVAL " . self::IDLE_MINUTES . " MINUTE)
              ORDER BY last_seen_at DESC",
            [$userId]
        );
        foreach ($rows as &$row) {
            $row['current'] = hash_equals((string) $row['token_hash'], $currentHash);
            unset($row['token_hash']);
        }

        return $rows;
    }

    public static function approve(User $user, int $id): void
    {
        $row = self::pendingRow($user, $id);
        Database::update('user_sessions', ['status' => 'ACTIVE', 'last_seen_at' => date('Y-m-d H:i:s')], ['id' => $row['id']]);
        AuditService::log('login.device_approved', 'auth', 'User', $user->id, null, ['device' => $row['device_label'], 'ip' => $row['ip_address']], 'SUCCESS', null, $user->id, $user->role_name);
    }

    public static function deny(User $user, int $id): void
    {
        $row = self::pendingRow($user, $id);
        Database::update('user_sessions', ['status' => 'DENIED', 'ended_at' => date('Y-m-d H:i:s'), 'end_reason' => 'denied'], ['id' => $row['id']]);
        AuditService::log('login.device_denied', 'auth', 'User', $user->id, null, ['device' => $row['device_label'], 'ip' => $row['ip_address']], 'SUCCESS', null, $user->id, $user->role_name);
    }

    /** The owner signs another of their devices out; it is signed out on its next request. */
    public static function signOutDevice(User $user, int $id): void
    {
        $row = Database::selectOne("SELECT * FROM user_sessions WHERE id = ? AND user_id = ? AND status = 'ACTIVE'", [$id, $user->id]);
        if ($row === null) {
            throw ValidationException::withMessage('That device is no longer signed in.');
        }
        if (self::currentToken() !== null && hash_equals((string) $row['token_hash'], self::hash(self::currentToken()))) {
            throw ValidationException::withMessage('This is the device you are using now. Use Sign out in the menu instead.');
        }
        Database::update('user_sessions', ['status' => 'ENDED', 'ended_at' => date('Y-m-d H:i:s'), 'end_reason' => 'signed_out_remotely'], ['id' => $row['id']]);
        AuditService::log('session.signed_out_remotely', 'auth', 'User', $user->id, null, ['device' => $row['device_label']], 'SUCCESS', null, $user->id, $user->role_name);
    }

    /** A Super Admin ends every session of an account, so a lost device never locks someone out. */
    public static function releaseAll(User $admin, int $userId): int
    {
        if (!$admin->isSuperAdmin()) {
            throw new \App\Core\HttpException(403, 'Only the Super Admin can release an account\'s devices.');
        }
        $target = User::find($userId);
        if ($target === null) {
            throw new \App\Core\HttpException(404, 'Account not found.');
        }
        $count = (int) Database::scalar("SELECT COUNT(*) FROM user_sessions WHERE user_id = ? AND status IN ('ACTIVE','PENDING')", [$userId]);
        Database::query("UPDATE user_sessions SET status = 'ENDED', ended_at = NOW(), end_reason = 'admin_release' WHERE user_id = ? AND status IN ('ACTIVE','PENDING')", [$userId]);
        AuditService::log('session.released', 'administration', 'User', $userId, null, ['devices' => $count], 'SUCCESS', null, $admin->id, $admin->role_name);

        return $count;
    }

    /** Repeated wrong passwords locked the account: one notice to its owner (a single wrong guess is not announced). */
    public static function notifyLockout(int $userId, string $ip): void
    {
        NotificationService::notify(
            $userId,
            'security',
            'Someone tried to sign in to your account and was locked out',
            "Several wrong passwords were entered for your account from IP address {$ip}, so it was locked for a while. If this was not you, consider changing your password.",
            '/account#devices'
        );
    }

    // -- helpers -------------------------------------------------------------------------

    /** Devices seen within the idle window. @return array<int,array<string,mixed>> */
    private static function inUse(int $userId): array
    {
        return Database::select(
            "SELECT id FROM user_sessions WHERE user_id = ? AND status = 'ACTIVE' AND last_seen_at >= DATE_SUB(NOW(), INTERVAL " . self::IDLE_MINUTES . ' MINUTE)',
            [$userId]
        );
    }

    /** @return array<string,mixed> */
    private static function pendingRow(User $user, int $id): array
    {
        $row = Database::selectOne("SELECT * FROM user_sessions WHERE id = ? AND user_id = ? AND status = 'PENDING'", [$id, $user->id]);
        if ($row === null) {
            throw ValidationException::withMessage('That sign-in request is no longer waiting.');
        }

        return $row;
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** "Chrome on Windows" from a User-Agent string. */
    public static function deviceLabel(string $userAgent): string
    {
        $browser = match (true) {
            str_contains($userAgent, 'Edg/')                                  => 'Edge',
            str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'Firefox/')                              => 'Firefox',
            str_contains($userAgent, 'Chrome/') || str_contains($userAgent, 'CriOS/') => 'Chrome',
            str_contains($userAgent, 'Safari/')                               => 'Safari',
            default                                                           => 'A browser',
        };
        $os = match (true) {
            str_contains($userAgent, 'Windows')                                    => 'Windows',
            str_contains($userAgent, 'Android')                                    => 'Android',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iPhone/iPad',
            str_contains($userAgent, 'Mac OS')                                     => 'Mac',
            str_contains($userAgent, 'Linux')                                      => 'Linux',
            default                                                                => 'an unknown device',
        };

        return "{$browser} on {$os}";
    }
}
