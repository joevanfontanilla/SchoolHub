<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Models\SystemSetting;
use App\Models\User;

/**
 * Authentication workflow: sign-in, lockout, password change (docs/07).
 */
final class AuthService
{
    /** Deliberately identical for "no such user" and "wrong password" — do not split. */
    public const INVALID_CREDENTIALS = 'The username or password you entered is incorrect.';

    /**
     * Attempt a sign-in.
     *
     * @return array{ok:bool,message:string,user:?User}
     */
    public static function attempt(string $login, string $password, string $ip, string $userAgent): array
    {
        if ($login === '' || $password === '') {
            return self::failure(self::INVALID_CREDENTIALS);
        }

        // IP-level limit first, so enumeration across many accounts is throttled too.
        if (LoginThrottle::ipIsBlocked($ip)) {
            LoginThrottle::record(null, $login, $ip, $userAgent, 'LOCKED_OUT');
            AuditService::log('login.blocked_ip', 'auth', null, null, null, null, 'FAILURE', 'IP rate limit reached');
            throw new HttpException(429, 'Too many sign-in attempts from this device. Please wait and try again.');
        }

        $user = User::findByLogin($login);

        if ($user === null) {
            LoginThrottle::record(null, $login, $ip, $userAgent, 'FAILED');
            AuditService::log('login.failed', 'auth', null, null, null, ['login' => $login], 'FAILURE', 'No matching account', null, 'Guest');
            return self::failure(self::INVALID_CREDENTIALS);
        }

        if ($user->status === User::STATUS_DISABLED) {
            LoginThrottle::record($user->id, $login, $ip, $userAgent, 'DISABLED');
            AuditService::log('login.disabled', 'auth', 'User', $user->id, null, null, 'FAILURE', null, $user->id, $user->role_name);
            return self::failure('This account has been disabled. Please contact the school administrator.');
        }

        if ($user->isLocked()) {
            LoginThrottle::record($user->id, $login, $ip, $userAgent, 'LOCKED_OUT');
            AuditService::log('login.locked', 'auth', 'User', $user->id, null, null, 'FAILURE', null, $user->id, $user->role_name);
            return self::failure(self::lockoutMessage($user));
        }

        if (!password_verify($password, $user->password_hash)) {
            self::registerFailure($user, $login, $ip, $userAgent);
            return self::failure(self::INVALID_CREDENTIALS);
        }

        // -- Success ---------------------------------------------------------

        // Upgrade the hash transparently if PHP's default cost has moved on.
        if (password_needs_rehash($user->password_hash, PASSWORD_DEFAULT)) {
            Database::update('users', [
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ], ['id' => $user->id]);
        }

        LoginThrottle::clearFor($user->id);
        Database::update('users', ['last_login_at' => date('Y-m-d H:i:s')], ['id' => $user->id]);
        LoginThrottle::record($user->id, $login, $ip, $userAgent, 'SUCCESS');

        $user = User::find($user->id);
        Auth::login($user);

        AuditService::log('login.success', 'auth', 'User', $user->id, null, null, 'SUCCESS');

        return ['ok' => true, 'message' => '', 'user' => $user];
    }

    public static function logout(): void
    {
        $user = Auth::user();
        if ($user !== null) {
            AuditService::log('logout', 'auth', 'User', $user->id);
        }
        Auth::logout();
    }

    /**
     * Change the signed-in user's password.
     *
     * @return array{ok:bool,message:string}
     */
    public static function changePassword(User $user, string $current, string $new): array
    {
        if (!password_verify($current, $user->password_hash)) {
            AuditService::log('password.change_failed', 'auth', 'User', $user->id, null, null, 'FAILURE', 'Current password incorrect');
            return ['ok' => false, 'message' => 'Your current password is incorrect.'];
        }

        if (password_verify($new, $user->password_hash)) {
            return ['ok' => false, 'message' => 'Your new password must be different from your current one.'];
        }

        $problem = self::passwordPolicyProblem($new);
        if ($problem !== null) {
            return ['ok' => false, 'message' => $problem];
        }

        Database::update('users', [
            'password_hash'        => password_hash($new, PASSWORD_DEFAULT),
            'must_change_password' => 0,
        ], ['id' => $user->id]);

        Auth::forgetCache();

        // The password is never included in the audit entry — only the fact of change.
        AuditService::log('password.changed', 'auth', 'User', $user->id);

        return ['ok' => true, 'message' => 'Your password has been changed.'];
    }

    /** Password policy from config (docs/07: configurable, reasonable minimum). */
    public static function passwordPolicyProblem(string $password): ?string
    {
        $min = SystemSetting::security('password_min_length', 8);

        // Reject NUL and other control characters. A NUL byte in particular truncates the
        // string that reaches bcrypt on PHP before 8.2.19/8.1.28/8.3.7 (CVE-2024-3096), so a
        // hash made from "secret\0..." would verify against just "secret". Refusing them here
        // means such a hash is never created, whatever the PHP version. A very long password is
        // also refused: bcrypt only reads the first 72 bytes, so anything past that is ignored.
        if (preg_match('/[\x00-\x1F\x7F]/', $password)) {
            return 'Your password contains a character that is not allowed. Use letters, numbers, and ordinary symbols.';
        }
        if (strlen($password) > 72) {
            return 'Your password is too long. Use at most 72 characters.';
        }
        if (mb_strlen($password) < $min) {
            return "Your new password must be at least {$min} characters long.";
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return 'Your new password must contain at least one letter and one number.';
        }

        return null;
    }

    private static function registerFailure(User $user, string $login, string $ip, string $userAgent): void
    {
        $attempts = $user->failed_login_attempts + 1;
        $max = SystemSetting::security('max_login_attempts', 5);

        $update = ['failed_login_attempts' => $attempts];

        if ($attempts >= $max) {
            $minutes = SystemSetting::security('lockout_minutes', 15);
            $update['status'] = User::STATUS_LOCKED;
            $update['locked_until'] = date('Y-m-d H:i:s', time() + ($minutes * 60));
        }

        Database::update('users', $update, ['id' => $user->id]);
        LoginThrottle::record($user->id, $login, $ip, $userAgent, 'FAILED');

        AuditService::log(
            $attempts >= $max ? 'login.locked_out' : 'login.failed',
            'auth',
            'User',
            $user->id,
            null,
            ['attempts' => $attempts],
            'FAILURE',
            null,
            $user->id,
            $user->role_name
        );
    }

    private static function lockoutMessage(User $user): string
    {
        if ($user->locked_until === null) {
            return 'This account is locked. Please contact the school administrator.';
        }

        $minutes = max(1, (int) ceil((strtotime($user->locked_until) - time()) / 60));

        return "Too many failed sign-in attempts. This account is locked for another {$minutes} minute"
            . ($minutes === 1 ? '' : 's') . '.';
    }

    /** @return array{ok:bool,message:string,user:?User} */
    private static function failure(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'user' => null];
    }
}
