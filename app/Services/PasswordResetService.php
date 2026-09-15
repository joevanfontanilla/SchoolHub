<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\ValidationException;
use App\Models\SystemSetting;
use App\Models\User;

/**
 * One-time password links (docs/07).
 *
 * No mail server is configured, so the Super Admin issues a link and gives it to the
 * user. Two purposes share one mechanism:
 *   SETUP  a new staff account's first password (valid setup_link_hours)
 *   RESET  a forgotten password (valid reset_link_minutes)
 *
 * Only a SHA-256 hash of the token is stored; the token itself exists only in the link.
 * A link works once. Issuing a new one invalidates the user's older links. Using it
 * activates a Pending account and unlocks a Locked one. A disabled account's link
 * does not work.
 */
final class PasswordResetService
{
    public const SETUP = 'SETUP';
    public const RESET = 'RESET';

    /**
     * @return array{token:string,expires_at:string} the plain token, shown once
     */
    public static function issue(User $user, string $purpose, ?User $issuedBy): array
    {
        $token = bin2hex(random_bytes(32));
        $expires = $purpose === self::SETUP
            ? date('Y-m-d H:i:s', time() + SystemSetting::security('setup_link_hours', 72) * 3600)
            : date('Y-m-d H:i:s', time() + SystemSetting::security('reset_link_minutes', 60) * 60);

        Database::transaction(static function () use ($user, $purpose, $issuedBy, $token, $expires): void {
            self::invalidateFor($user->id);
            Database::insert('password_reset_tokens', [
                'user_id'      => $user->id,
                'token_hash'   => self::hash($token),
                'purpose'      => $purpose,
                'expires_at'   => $expires,
                'requested_ip' => (new \App\Core\Request())->ip(),
                'created_by'   => $issuedBy?->id,
            ]);
            AuditService::log($purpose === self::SETUP ? 'password.setup_link_issued' : 'password.reset_link_issued', 'auth', 'User', $user->id,
                null, ['expires_at' => $expires], 'SUCCESS', null, $issuedBy?->id, $issuedBy?->role_name);
        });

        return ['token' => $token, 'expires_at' => $expires];
    }

    /**
     * The user a token belongs to, if the link is still usable.
     *
     * @return array{user:User,purpose:string,expires_at:string}|null
     */
    public static function find(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $row = Database::selectOne(
            'SELECT * FROM password_reset_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?',
            [self::hash($token), date('Y-m-d H:i:s')]
        );
        if ($row === null) {
            return null;
        }
        $user = User::find((int) $row['user_id']);
        if ($user === null || $user->status === User::STATUS_DISABLED) {
            return null;
        }
        return ['user' => $user, 'purpose' => (string) $row['purpose'], 'expires_at' => (string) $row['expires_at']];
    }

    /**
     * Set a new password through a link. Returns the user, who then signs in normally.
     *
     * @throws ValidationException
     */
    public static function complete(string $token, string $password, string $confirmation): User
    {
        $found = self::find($token);
        if ($found === null) {
            throw ValidationException::withMessage('This link is no longer valid. It may have expired or been used already. Ask the school administrator for a new one.');
        }
        $user = $found['user'];

        $problem = AuthService::passwordPolicyProblem($password);
        if ($problem !== null) {
            throw ValidationException::withErrors(['password' => [$problem]]);
        }
        if (!hash_equals($password, $confirmation)) {
            throw ValidationException::withErrors(['password_confirmation' => ['The two passwords do not match.']]);
        }

        return Database::transaction(static function () use ($token, $user, $password, $found): User {
            // Lock the token so the same link cannot be used twice at the same moment.
            $row = Database::selectOne('SELECT id FROM password_reset_tokens WHERE token_hash = ? AND used_at IS NULL FOR UPDATE', [self::hash($token)]);
            if ($row === null) {
                throw ValidationException::withMessage('This link has already been used.');
            }

            $update = [
                'password_hash'         => password_hash($password, PASSWORD_DEFAULT),
                'must_change_password'  => 0,
                'failed_login_attempts' => 0,
                'locked_until'          => null,
            ];
            if (in_array($user->status, [User::STATUS_PENDING, User::STATUS_LOCKED], true)) {
                $update['status'] = User::STATUS_ACTIVE;
            }
            Database::update('users', $update, ['id' => $user->id]);
            Database::query('UPDATE password_reset_tokens SET used_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), $row['id']]);
            self::invalidateFor($user->id);

            AuditService::log($found['purpose'] === self::SETUP ? 'password.set_up' : 'password.reset', 'auth', 'User', $user->id,
                ['status' => $user->status], ['status' => $update['status'] ?? $user->status], 'SUCCESS', 'Through a one-time link', $user->id, $user->role_name);

            return User::find($user->id);
        });
    }

    /** End every unused link of a user (a new link was issued, the account was disabled, or the password was set). */
    public static function invalidateFor(int $userId): void
    {
        Database::query(
            'UPDATE password_reset_tokens SET expires_at = ? WHERE user_id = ? AND used_at IS NULL AND expires_at > ?',
            [date('Y-m-d H:i:s', time() - 1), $userId, date('Y-m-d H:i:s')]
        );
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
