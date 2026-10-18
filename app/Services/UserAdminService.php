<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Core\ValidationException;
use App\Models\User;

/**
 * Account administration by the Super Admin (docs/04, docs/07).
 *
 * - Staff accounts (Teacher, Registrar, Cashier, Super Admin) are created here as
 *   Pending, with no usable password, and a one-time set-password link.
 *   Students keep registering themselves.
 * - Any account can be edited, disabled, re-enabled, unlocked, or given a reset link.
 * - A role changes only between staff roles, never for the administrator's own
 *   account, never away from the last active Super Admin, and not for a teacher who
 *   still teaches classes.
 * - An administrator cannot disable their own account or the last active Super Admin.
 * - An account can be deleted (anonymised, records kept), restored, or, when nothing refers to it, erased.
 *
 * Every action is audited. Passwords never pass through here.
 */
final class UserAdminService
{
    public const STAFF_ROLES = [User::ROLE_TEACHER, User::ROLE_REGISTRAR, User::ROLE_CASHIER, User::ROLE_SUPER_ADMIN];

    /**
     * @param array<string,mixed> $input first_name, middle_name, last_name, username, email, role
     * @return array{id:int,token:string,expires_at:string}
     */
    public static function createStaff(User $admin, array $input): array
    {
        self::assertAdmin($admin);
        $input = self::normalize($input);

        $validator = Validator::make($input, [
            'first_name'  => 'required|max:100',
            'middle_name' => 'nullable|max:100',
            'last_name'   => 'required|max:100',
            'suffix'      => 'nullable|max:20',
            'username'    => 'required|min:3|max:50|alpha_dash|unique:users,username',
            'email'       => 'required|email|max:150|unique:users,email',
            'role'        => 'required|in:' . implode(',', self::STAFF_ROLES),
        ]);
        if ($validator->fails()) {
            throw ValidationException::withErrors($validator->errors());
        }

        return Database::transaction(static function () use ($admin, $input): array {
            $id = Database::insert('users', [
                'role_id'              => self::roleId($input['role']),
                'username'             => $input['username'],
                'email'                => $input['email'],
                // No one knows this password: the user sets their own through the link.
                'password_hash'        => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                'first_name'           => $input['first_name'],
                'middle_name'          => $input['middle_name'] !== '' ? $input['middle_name'] : null,
                'last_name'            => $input['last_name'],
                'suffix'               => $input['suffix'] !== '' ? $input['suffix'] : null,
                'status'               => User::STATUS_PENDING,
                'must_change_password' => 0,
            ]);
            AuditService::log('user.created', 'administration', 'User', $id, null, [
                'username' => $input['username'], 'email' => $input['email'], 'role' => $input['role'],
            ], 'SUCCESS', null, $admin->id, $admin->role_name);

            $link = PasswordResetService::issue(User::find($id), PasswordResetService::SETUP, $admin);
            return ['id' => $id, 'token' => $link['token'], 'expires_at' => $link['expires_at']];
        });
    }

    /** @param array<string,mixed> $input first_name, middle_name, last_name, username, email, role (optional) */
    public static function update(User $admin, int $userId, array $input): void
    {
        self::assertAdmin($admin);
        $user = self::user($userId);
        $input = self::normalize($input);

        $validator = Validator::make($input, [
            'first_name'  => 'required|max:100',
            'middle_name' => 'nullable|max:100',
            'last_name'   => 'required|max:100',
            'suffix'      => 'nullable|max:20',
            'username'    => 'required|min:3|max:50|alpha_dash',
            'email'       => 'required|email|max:150',
        ]);
        foreach (['username', 'email'] as $field) {
            if (Database::scalar("SELECT id FROM users WHERE {$field} = ? AND id <> ?", [$input[$field], $user->id]) !== null) {
                $validator->addError($field, "That {$field} belongs to another account.");
            }
        }

        $newRole = $input['role'] !== '' ? $input['role'] : $user->role_name;
        if ($newRole !== $user->role_name) {
            $problem = self::roleChangeProblem($admin, $user, $newRole);
            if ($problem !== null) {
                $validator->addError('role', $problem);
            }
        }
        if ($validator->fails()) {
            throw ValidationException::withErrors($validator->errors());
        }

        Database::transaction(static function () use ($admin, $user, $input, $newRole): void {
            $before = ['first_name' => $user->first_name, 'middle_name' => $user->middle_name, 'last_name' => $user->last_name, 'suffix' => $user->suffix, 'username' => $user->username, 'email' => $user->email];
            $after = ['first_name' => $input['first_name'], 'middle_name' => $input['middle_name'] !== '' ? $input['middle_name'] : null, 'last_name' => $input['last_name'], 'suffix' => $input['suffix'] !== '' ? $input['suffix'] : null, 'username' => $input['username'], 'email' => $input['email']];
            $changed = array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));

            if ($changed !== []) {
                Database::update('users', $after, ['id' => $user->id]);
                AuditService::log('user.updated', 'administration', 'User', $user->id,
                    array_intersect_key($before, array_flip($changed)), array_intersect_key($after, array_flip($changed)),
                    'SUCCESS', null, $admin->id, $admin->role_name);
            }
            if ($newRole !== $user->role_name) {
                Database::update('users', ['role_id' => self::roleId($newRole)], ['id' => $user->id]);
                AuditService::log('user.role_changed', 'administration', 'User', $user->id,
                    ['role' => $user->role_name], ['role' => $newRole], 'SUCCESS', null, $admin->id, $admin->role_name);
            }
        });
    }

    public static function disable(User $admin, int $userId, string $reason): void
    {
        self::assertAdmin($admin);
        $user = self::user($userId);
        $reason = trim($reason);
        if ($user->id === $admin->id) {
            throw ValidationException::withMessage('You cannot disable your own account.');
        }
        if ($user->status === User::STATUS_DISABLED) {
            throw ValidationException::withMessage('This account is already disabled.');
        }
        if ($user->isSuperAdmin() && self::otherActiveSuperAdmins($user->id) === 0) {
            throw ValidationException::withMessage('This is the last active Super Admin. Create or enable another Super Admin first.');
        }
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withErrors(['reason' => ['Give the reason for disabling this account (at least 5 characters).']]);
        }

        Database::transaction(static function () use ($admin, $user, $reason): void {
            Database::update('users', ['status' => User::STATUS_DISABLED], ['id' => $user->id]);
            PasswordResetService::invalidateFor($user->id);
            // Any open session of this user ends on its next request (Auth::user checks canSignIn).
            AuditService::log('user.disabled', 'administration', 'User', $user->id, ['status' => $user->status], ['status' => User::STATUS_DISABLED], 'SUCCESS', $reason, $admin->id, $admin->role_name);
        });
    }

    /** Disabled -> Active (or back to Pending if the account was never set up). */
    public static function enable(User $admin, int $userId): void
    {
        self::assertAdmin($admin);
        $user = self::user($userId);
        if ($user->status !== User::STATUS_DISABLED) {
            throw ValidationException::withMessage('Only a disabled account can be enabled.');
        }
        $status = $user->last_login_at === null && self::neverSetUp($user->id) ? User::STATUS_PENDING : User::STATUS_ACTIVE;
        Database::update('users', ['status' => $status, 'failed_login_attempts' => 0, 'locked_until' => null], ['id' => $user->id]);
        AuditService::log('user.enabled', 'administration', 'User', $user->id, ['status' => User::STATUS_DISABLED], ['status' => $status], 'SUCCESS', null, $admin->id, $admin->role_name);
    }

    public static function unlock(User $admin, int $userId): void
    {
        self::assertAdmin($admin);
        $user = self::user($userId);
        if ($user->status !== User::STATUS_LOCKED && (int) $user->failed_login_attempts === 0) {
            throw ValidationException::withMessage('This account is not locked.');
        }
        $status = $user->status === User::STATUS_LOCKED ? User::STATUS_ACTIVE : $user->status;
        Database::update('users', ['status' => $status, 'failed_login_attempts' => 0, 'locked_until' => null], ['id' => $user->id]);
        AuditService::log('user.unlocked', 'administration', 'User', $user->id, ['status' => $user->status], ['status' => $status], 'SUCCESS', null, $admin->id, $admin->role_name);
    }

    /** @return array{token:string,expires_at:string} */
    public static function issueResetLink(User $admin, int $userId): array
    {
        self::assertAdmin($admin);
        $user = self::user($userId);
        if ($user->status === User::STATUS_DISABLED) {
            throw ValidationException::withMessage('This account is disabled. Enable it before issuing a password link.');
        }
        $purpose = $user->status === User::STATUS_PENDING ? PasswordResetService::SETUP : PasswordResetService::RESET;
        return PasswordResetService::issue($user, $purpose, $admin);
    }

    /**
     * Email a link the Super Admin was just shown to the account's own address. The link page
     * sends the token back; it must still be an unused link of this very account.
     *
     * @throws ValidationException
     */
    public static function emailLink(User $admin, int $userId, string $token): User
    {
        self::assertAdmin($admin);
        $user = self::user($userId);
        $found = PasswordResetService::find($token);
        if ($found === null || $found['user']->id !== $user->id) {
            throw ValidationException::withMessage('That link is no longer valid. Issue a new one and email it from there.');
        }
        try {
            PasswordResetService::emailLink($user, $token, $found['purpose'], $found['expires_at']);
        } catch (\RuntimeException $e) {
            AuditService::log('password.link_email_failed', 'auth', 'User', $user->id, null, null, 'FAILURE', substr($e->getMessage(), 0, 250), $admin->id, $admin->role_name);
            throw ValidationException::withMessage('The email could not be sent: ' . $e->getMessage() . ' The link still works; copy it instead, or check the mail settings.');
        }
        AuditService::log('password.link_emailed', 'auth', 'User', $user->id, null, ['purpose' => $found['purpose']], 'SUCCESS', null, $admin->id, $admin->role_name);
        return $user;
    }

    /** Why a role change is refused, or null when it is allowed. */
    public static function roleChangeProblem(User $admin, User $user, string $newRole): ?string
    {
        return match (true) {
            !in_array($newRole, self::STAFF_ROLES, true) || $user->isStudent()
                => 'Roles change only between staff roles. Student accounts keep the Student role.',
            $user->id === $admin->id
                => 'You cannot change your own role.',
            $user->isSuperAdmin() && $user->status === User::STATUS_ACTIVE && self::otherActiveSuperAdmins($user->id) === 0
                => 'This is the last active Super Admin. Create or enable another Super Admin first.',
            $user->isTeacher() && (int) Database::scalar("SELECT COUNT(*) FROM class_schedules WHERE teacher_user_id = ? AND status = 'ACTIVE'", [$user->id]) > 0
                => 'This teacher still teaches classes. Assign those classes to another teacher first.',
            default => null,
        };
    }

    public static function otherActiveSuperAdmins(int $exceptUserId): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'Super Admin' AND u.status = 'Active' AND u.id <> ?",
            [$exceptUserId]
        );
    }

    // -- Deleting an account (docs/07) -------------------------------------------------

    /** @return array<string,mixed>|null the original username, when, by whom, and why a deleted account was deleted */
    public static function deletedInfo(int $userId): ?array
    {
        return Database::selectOne(
            'SELECT u.deleted_username, u.deleted_at, u.delete_reason, d.first_name, d.last_name FROM users u LEFT JOIN users d ON d.id = u.deleted_by WHERE u.id = ?',
            [$userId]
        );
    }

    /** Why an account cannot be deleted, or null when it can. */
    public static function deleteProblem(User $admin, User $user): ?string
    {
        return match (true) {
            $user->status === User::STATUS_DELETED
                => 'This account is already deleted.',
            $user->id === $admin->id
                => 'You cannot delete your own account.',
            $user->isSuperAdmin() && $user->status === User::STATUS_ACTIVE && self::otherActiveSuperAdmins($user->id) === 0
                => 'This is the last active Super Admin. Create or enable another Super Admin first.',
            $user->isStudent() && EIdService::enrolledNow($user->id)
                => 'This student is currently enrolled. Ask the registrar to withdraw them from the term first, or wait until the school year ends.',
            $user->isTeacher() && (int) Database::scalar("SELECT COUNT(*) FROM class_schedules WHERE teacher_user_id = ? AND status = 'ACTIVE'", [$user->id]) > 0
                => 'This teacher still teaches classes. Assign those classes to another teacher first.',
            (int) Database::scalar('SELECT COUNT(*) FROM sections WHERE adviser_user_id = ? AND is_active = 1', [$user->id]) > 0
                => 'This person advises an active section. Choose another adviser first.',
            default => null,
        };
    }

    /**
     * Delete an account: it can no longer sign in, its username and email are freed, and its name and records stay.
     * Reversible with restore() until it is erased.
     */
    public static function delete(User $admin, int $userId, string $reason, string $confirmUsername): void
    {
        self::assertAdmin($admin);
        $user = self::user($userId);
        $problem = self::deleteProblem($admin, $user);
        if ($problem !== null) {
            throw ValidationException::withMessage($problem);
        }

        $reason = trim($reason);
        $errors = [];
        if (mb_strlen($reason) < 5) {
            $errors['reason'] = ['Give the reason for deleting this account (at least 5 characters).'];
        }
        if (trim($confirmUsername) !== $user->username) {
            $errors['confirm_username'] = ['Type the username exactly as shown to confirm.'];
        }
        if ($errors !== []) {
            throw ValidationException::withErrors($errors);
        }

        Database::transaction(static function () use ($admin, $user, $reason): void {
            Database::update('users', [
                'status'                => User::STATUS_DELETED,
                'username'              => 'deleted-' . $user->id,
                'email'                 => 'deleted-' . $user->id . '@deleted.invalid',
                'deleted_at'            => date('Y-m-d H:i:s'),
                'deleted_by'            => $admin->id,
                'delete_reason'         => mb_substr($reason, 0, 255),
                'deleted_username'      => $user->username,
                'deleted_email'         => $user->email,
                'failed_login_attempts' => 0,
                'locked_until'          => null,
                'must_change_password'  => 0,
            ], ['id' => $user->id]);
            PasswordResetService::invalidateFor($user->id);
            Database::query('DELETE FROM sessions WHERE user_id = ?', [$user->id]);
            AuditService::log('user.deleted', 'administration', 'User', $user->id,
                ['status' => $user->status, 'username' => $user->username, 'role' => $user->role_name], ['status' => User::STATUS_DELETED],
                'SUCCESS', $reason, $admin->id, $admin->role_name);
        });
    }

    /** Bring a deleted account back, Disabled, with its original username and email if they are still free. */
    public static function restore(User $admin, int $userId): void
    {
        self::assertAdmin($admin);
        $user = self::user($userId);
        if ($user->status !== User::STATUS_DELETED) {
            throw ValidationException::withMessage('Only a deleted account can be restored.');
        }
        $row = Database::selectOne('SELECT deleted_username, deleted_email FROM users WHERE id = ?', [$user->id]);
        $username = (string) ($row['deleted_username'] ?? '');
        $email = (string) ($row['deleted_email'] ?? '');
        if ($username === '' || $email === '') {
            throw ValidationException::withMessage('The original sign-in details of this account were not kept, so it cannot be restored.');
        }
        foreach (['username' => $username, 'email' => $email] as $field => $value) {
            if (Database::scalar("SELECT id FROM users WHERE {$field} = ? AND id <> ?", [$value, $user->id]) !== null) {
                throw ValidationException::withMessage("The original {$field} now belongs to another account, so this account cannot be restored.");
            }
        }

        Database::transaction(static function () use ($admin, $user, $username, $email): void {
            Database::update('users', [
                'status' => User::STATUS_DISABLED, 'username' => $username, 'email' => $email,
                'deleted_at' => null, 'deleted_by' => null, 'delete_reason' => null, 'deleted_username' => null, 'deleted_email' => null,
            ], ['id' => $user->id]);
            AuditService::log('user.restored', 'administration', 'User', $user->id, ['status' => User::STATUS_DELETED],
                ['status' => User::STATUS_DISABLED, 'username' => $username], 'SUCCESS', null, $admin->id, $admin->role_name);
        });
    }

    /**
     * Erase a deleted account for good. Possible only when nothing refers to it: the database refuses otherwise, and the
     * account stays deleted.
     */
    public static function erase(User $admin, int $userId, string $confirmUsername): void
    {
        self::assertAdmin($admin);
        $user = self::user($userId);
        if ($user->status !== User::STATUS_DELETED) {
            throw ValidationException::withMessage('Delete the account first. Only a deleted account can be erased.');
        }
        $original = (string) Database::scalar('SELECT deleted_username FROM users WHERE id = ?', [$user->id]);
        if (trim($confirmUsername) !== $original) {
            throw ValidationException::withErrors(['confirm_username' => ['Type the original username exactly as shown to confirm.']]);
        }
        $refused = 'Records still refer to this account (payments, grades, enrollments, or logged activity), so it can only stay deleted.';
        if ((int) Database::scalar('SELECT COUNT(*) FROM audit_logs WHERE user_id = ?', [$user->id]) > 0) {
            throw ValidationException::withMessage($refused);
        }

        try {
            Database::transaction(static function () use ($admin, $user, $original): void {
                Database::query('DELETE FROM users WHERE id = ?', [$user->id]);
                AuditService::log('user.erased', 'administration', 'User', $user->id, ['username' => $original, 'role' => $user->role_name], null,
                    'SUCCESS', null, $admin->id, $admin->role_name);
            });
        } catch (\PDOException $e) {
            throw ValidationException::withMessage($refused);
        }
    }
    // -- Internals --------------------------------------------------------------------

    private static function neverSetUp(int $userId): bool
    {
        return Database::scalar("SELECT id FROM password_reset_tokens WHERE user_id = ? AND used_at IS NOT NULL LIMIT 1", [$userId]) === null
            && Database::scalar("SELECT id FROM password_reset_tokens WHERE user_id = ? AND purpose = 'SETUP' LIMIT 1", [$userId]) !== null;
    }

    /** @return array<string,string> */
    private static function normalize(array $input): array
    {
        return [
            'first_name'  => trim((string) ($input['first_name'] ?? '')),
            'middle_name' => trim((string) ($input['middle_name'] ?? '')),
            'last_name'   => trim((string) ($input['last_name'] ?? '')),
            'suffix'      => trim((string) ($input['suffix'] ?? '')),
            'username'    => trim((string) ($input['username'] ?? '')),
            'email'       => strtolower(trim((string) ($input['email'] ?? ''))),
            'role'        => trim((string) ($input['role'] ?? '')),
        ];
    }

    private static function roleId(string $name): int
    {
        return (int) Database::scalar('SELECT id FROM roles WHERE name = ?', [$name]);
    }

    private static function user(int $id): User
    {
        $user = User::find($id);
        if ($user === null) {
            throw HttpException::notFound('That account was not found.');
        }
        return $user;
    }

    private static function assertAdmin(User $admin): void
    {
        if (!$admin->isSuperAdmin()) {
            throw HttpException::forbidden('Only the Super Admin manages accounts.');
        }
    }
}
