<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\User;

/**
 * Session-backed authentication and permission checks.
 *
 * Authorization is enforced here and in middleware, AND repeated in the query
 * layer for ownership-scoped data. A role check alone is not enough — a student
 * must only ever read their own records (docs/04).
 */
final class Auth
{
    private const SESSION_KEY = '_auth_user_id';

    private static ?User $cachedUser = null;
    private static ?array $cachedPermissions = null;

    public static function login(User $user): void
    {
        // Regenerate before storing the id so a fixated session id is useless.
        Session::regenerate();
        Session::put(self::SESSION_KEY, $user->id);
        Csrf::rotate();

        self::$cachedUser = $user;
        self::$cachedPermissions = null;
    }

    public static function logout(): void
    {
        Session::destroy();
        self::$cachedUser = null;
        self::$cachedPermissions = null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        $id = Session::get(self::SESSION_KEY);
        return is_int($id) ? $id : (is_numeric($id) ? (int) $id : null);
    }

    public static function user(): ?User
    {
        if (self::$cachedUser !== null) {
            return self::$cachedUser;
        }

        $id = self::id();
        if ($id === null) {
            return null;
        }

        $user = User::find($id);

        // A session outliving the account it belongs to must not stay valid.
        if ($user === null || !$user->canSignIn()) {
            self::logout();
            return null;
        }

        return self::$cachedUser = $user;
    }

    public static function roleName(): ?string
    {
        return self::user()?->role_name;
    }

    public static function hasRole(string ...$roles): bool
    {
        $current = self::roleName();
        return $current !== null && in_array($current, $roles, true);
    }

    /** Super Admin implicitly holds every permission (docs/04). */
    public static function can(string $permission): bool
    {
        $user = self::user();
        if ($user === null) {
            return false;
        }
        if ($user->isSuperAdmin()) {
            return true;
        }

        return in_array($permission, self::permissions(), true);
    }

    /** @return array<int,string> */
    public static function permissions(): array
    {
        if (self::$cachedPermissions !== null) {
            return self::$cachedPermissions;
        }

        $user = self::user();
        if ($user === null) {
            return self::$cachedPermissions = [];
        }

        $rows = Database::select(
            'SELECT p.`key`
               FROM permissions p
               JOIN role_permissions rp ON rp.permission_id = p.id
              WHERE rp.role_id = ?',
            [$user->role_id]
        );

        return self::$cachedPermissions = array_column($rows, 'key');
    }

    /** The landing route for the signed-in user's role. */
    public static function homePath(): string
    {
        return match (self::roleName()) {
            User::ROLE_STUDENT     => '/student/dashboard',
            User::ROLE_TEACHER     => '/teacher/dashboard',
            User::ROLE_REGISTRAR   => '/registrar/dashboard',
            User::ROLE_CASHIER     => '/cashier/dashboard',
            User::ROLE_SUPER_ADMIN => '/admin/dashboard',
            default                => '/login',
        };
    }

    /** Test support. */
    public static function forgetCache(): void
    {
        self::$cachedUser = null;
        self::$cachedPermissions = null;
    }
}
