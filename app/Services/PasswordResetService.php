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
 * The Super Admin issues a link and gives it to the user or emails it, and when email is set
 * up anyone can ask for a reset link at "Forgot password?". Two purposes share one mechanism:
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

    /** Links one account may be sent through "Forgot password?" in an hour. */
    public const ACCOUNT_REQUESTS_PER_HOUR = 3;

    /**
     * "Forgot password?": issue a reset link for the account with this email address and
     * return the step that emails it, or null when nothing is sent. The caller shows the same
     * message either way, so the page never reveals whether an address has an account.
     *
     * Nothing is sent when the address has no account, the account is disabled or not yet set
     * up (its set-up link comes from the Super Admin), this network address asked too often, or
     * this account was sent too many links in the last hour. Every request is audited, without
     * the address that was typed.
     */
    public static function requestByEmail(string $email, string $ip): ?\Closure
    {
        $email = trim($email);
        $hourAgo = date('Y-m-d H:i:s', time() - 3600);
        $fromThisAddress = (int) Database::scalar(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'password.reset_requested' AND ip_address = ? AND created_at > ?",
            [$ip, $hourAgo]
        );
        $user = filter_var($email, FILTER_VALIDATE_EMAIL) !== false && strlen($email) <= 150 ? User::findByEmail($email) : null;

        $refused = match (true) {
            $fromThisAddress >= SystemSetting::security('reset_requests_per_hour', 5) => 'Too many requests from this network address',
            !\App\Core\Mailer::enabled() => 'Email is turned off',
            $user === null => 'No account uses that address',
            !in_array($user->status, [User::STATUS_ACTIVE, User::STATUS_LOCKED], true) => "Account is {$user->status}",
            (int) Database::scalar(
                'SELECT COUNT(*) FROM password_reset_tokens WHERE user_id = ? AND purpose = ? AND created_by IS NULL AND created_at > ?',
                [$user->id, self::RESET, $hourAgo]
            ) >= self::ACCOUNT_REQUESTS_PER_HOUR => 'Too many links for this account in the last hour',
            default => null,
        };

        AuditService::log('password.reset_requested', 'auth', $user !== null ? 'User' : null, $user?->id, null, null,
            $refused === null ? 'SUCCESS' : 'FAILURE', $refused ?? 'Reset link emailed');
        if ($refused !== null) {
            return null;
        }

        $issued = self::issue($user, self::RESET, null);
        return static function () use ($user, $issued): void {
            try {
                self::emailLink($user, $issued['token'], self::RESET, $issued['expires_at']);
            } catch (\Throwable $e) {
                error_log("Password reset email to user #{$user->id} failed: " . $e->getMessage());
                AuditService::log('password.reset_email_failed', 'auth', 'User', $user->id, null, null, 'FAILURE', substr($e->getMessage(), 0, 250));
            }
        };
    }

    /**
     * Email a link that was just issued to the account's own address.
     *
     * @throws \RuntimeException when email is off, the site address is not set in production, or delivery fails
     */
    public static function emailLink(User $user, string $token, string $purpose, string $expiresAt): void
    {
        // In production a link must never be built from the Host header a visitor sent.
        if (\App\Core\Config::get('app.env') === 'production' && !preg_match('#^https://#i', (string) \App\Core\Config::get('app.base_url', ''))) {
            throw new \RuntimeException('Set app.base_url to the site\'s https:// address before sending password links.');
        }
        $school = (string) SystemSetting::get('school_name', (string) \App\Core\Config::get('app.name', 'SchoolHub'));
        $link = absolute_url('/password/reset/' . $token);
        $until = dt($expiresAt);
        $setup = $purpose === self::SETUP;

        $subject = ($setup ? 'Set up your account' : 'Reset your password') . ' · ' . $school;
        $intro = $setup
            ? "An account was created for you on the {$school} portal. Your username is {$user->username}. Choose your password to start using it."
            : "Someone asked to reset the password of your {$school} portal account ({$user->username}). If it was you, choose a new password.";
        $text = "Hello {$user->first_name},\n\n{$intro}\n\n" . ($setup ? 'Set your password' : 'Choose a new password') . ":\n{$link}\n\n"
            . "The link works once, until {$until}. Do not forward it: anyone with the link can set the password.\n\n"
            . ($setup ? '' : "If you did not ask for this, ignore this email. Your password stays the same.\n\n")
            . "{$school}\nThis message was sent automatically. Replies are not read.";

        $html = '<!doctype html><html><body style="margin:0;padding:24px;background:#EAF6FF;font-family:Segoe UI,Arial,sans-serif;color:#1f2937">'
            . '<table role="presentation" width="100%" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;border:1px solid #B3DFF5"><tr><td style="padding:28px">'
            . '<p style="margin:0 0 4px;font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#1E7BAE;font-weight:700">' . e($school) . '</p>'
            . '<h1 style="margin:0 0 16px;font-size:22px;color:#0F4C5C">' . e($setup ? 'Set up your account' : 'Reset your password') . '</h1>'
            . '<p style="margin:0 0 12px;font-size:15px;line-height:1.55">Hello ' . e($user->first_name) . ',</p>'
            . '<p style="margin:0 0 20px;font-size:15px;line-height:1.55">' . e($intro) . '</p>'
            . '<p style="margin:0 0 20px"><a href="' . e($link) . '" style="display:inline-block;background:#1E7BAE;color:#ffffff;text-decoration:none;font-weight:600;padding:12px 20px;border-radius:8px">'
            . e($setup ? 'Set your password' : 'Choose a new password') . '</a></p>'
            . '<p style="margin:0 0 12px;font-size:13px;line-height:1.55;color:#4b5563">The link works once, until ' . e($until) . '. Do not forward it: anyone with the link can set the password.</p>'
            . ($setup ? '' : '<p style="margin:0 0 12px;font-size:13px;line-height:1.55;color:#4b5563">If you did not ask for this, ignore this email. Your password stays the same.</p>')
            . '<p style="margin:16px 0 0;font-size:12px;color:#6B7280;word-break:break-all">If the button does not open, copy this address into your browser:<br>' . e($link) . '</p>'
            . '</td></tr></table></body></html>';

        \App\Core\Mailer::send($user->email, $subject, $text, $html);
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
