<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Models\User;

/**
 * Which permissions each role holds (docs/04).
 *
 * Super Admin holds every permission implicitly and is not editable. The other roles
 * are edited by the Super Admin in the roles screen, and every change is audited.
 * Routes also require the right role, so a permission granted to the wrong role does
 * not open another role's screens; the matrix mainly narrows what a role may do.
 * The `administration` permissions stay Super Admin only.
 */
final class PermissionService
{
    /** Permission modules that no role other than Super Admin may hold. */
    public const ADMIN_ONLY_MODULES = ['administration'];

    /**
     * The matrix for the roles screen.
     *
     * @return array{roles:array<int,array<string,mixed>>,modules:array<string,array<int,array<string,mixed>>>,granted:array<int,array<int,string>>}
     */
    public static function matrix(): array
    {
        $roles = Database::select('SELECT id, name, description FROM roles ORDER BY id');
        $modules = [];
        foreach (Database::select('SELECT `key`, module, description FROM permissions ORDER BY module, `key`') as $permission) {
            $modules[$permission['module']][] = $permission;
        }
        $granted = [];
        foreach (Database::select('SELECT rp.role_id, p.`key` FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id') as $row) {
            $granted[(int) $row['role_id']][] = $row['key'];
        }
        return ['roles' => $roles, 'modules' => $modules, 'granted' => $granted];
    }

    /**
     * Replace a role's permissions with exactly $keys.
     *
     * @param array<int,string> $keys
     * @return array{added:array<int,string>,removed:array<int,string>}
     */
    public static function update(User $admin, int $roleId, array $keys): array
    {
        if (!$admin->isSuperAdmin()) {
            throw HttpException::forbidden('Only the Super Admin changes role permissions.');
        }
        $role = Database::selectOne('SELECT * FROM roles WHERE id = ?', [$roleId]);
        if ($role === null) {
            throw HttpException::notFound('That role was not found.');
        }
        if ($role['name'] === User::ROLE_SUPER_ADMIN) {
            throw ValidationException::withMessage('Super Admin always holds every permission; it cannot be edited.');
        }

        $keys = array_values(array_unique(array_filter(array_map('strval', $keys), static fn ($k) => $k !== '')));
        $known = array_column(Database::select('SELECT id, `key`, module FROM permissions'), null, 'key');
        foreach ($keys as $key) {
            if (!isset($known[$key])) {
                throw ValidationException::withMessage("Unknown permission: {$key}.");
            }
            if (in_array($known[$key]['module'], self::ADMIN_ONLY_MODULES, true)) {
                throw ValidationException::withMessage("{$key} is reserved for the Super Admin.");
            }
        }

        return Database::transaction(static function () use ($admin, $role, $keys, $known): array {
            $current = array_column(Database::select(
                'SELECT p.`key` FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? FOR UPDATE',
                [$role['id']]
            ), 'key');
            $added = array_values(array_diff($keys, $current));
            $removed = array_values(array_diff($current, $keys));
            if ($added === [] && $removed === []) {
                return ['added' => [], 'removed' => []];
            }

            foreach ($removed as $key) {
                Database::query('DELETE FROM role_permissions WHERE role_id = ? AND permission_id = ?', [$role['id'], $known[$key]['id']]);
            }
            foreach ($added as $key) {
                Database::query('INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$role['id'], $known[$key]['id']]);
            }
            sort($added);
            sort($removed);

            AuditService::log('permissions.changed', 'administration', 'Role', (int) $role['id'],
                ['permissions' => $current], ['role' => $role['name'], 'added' => $added, 'removed' => $removed],
                'SUCCESS', null, $admin->id, $admin->role_name);
            Auth::forgetCache();

            return ['added' => $added, 'removed' => $removed];
        });
    }

    /**
     * Seeder support: give each role its default permissions without undoing changes an
     * administrator made. A role with no permissions yet (a fresh install) gets all its
     * defaults; an existing role only gets defaults for permissions that did not exist
     * before this seed run. Nothing is ever removed here.
     *
     * @param array<string,array<int,string>> $defaults role name => permission keys
     * @param array<int,string>               $newKeys  permission keys created by this run
     * @return int grants inserted
     */
    public static function seedDefaults(array $defaults, array $newKeys): int
    {
        $roleIds = array_column(Database::select('SELECT id, name FROM roles'), 'id', 'name');
        $permIds = array_column(Database::select('SELECT id, `key` FROM permissions'), 'id', 'key');
        $inserted = 0;

        foreach ($defaults as $roleName => $keys) {
            $roleId = $roleIds[$roleName] ?? null;
            if ($roleId === null) {
                continue;
            }
            $fresh = (int) Database::scalar('SELECT COUNT(*) FROM role_permissions WHERE role_id = ?', [$roleId]) === 0;
            foreach ($keys as $key) {
                if (!isset($permIds[$key])) {
                    throw new \RuntimeException("Unknown permission key in the defaults: {$key}");
                }
                if (!$fresh && !in_array($key, $newKeys, true)) {
                    continue;
                }
                $inserted += Database::query('INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$roleId, $permIds[$key]])->rowCount();
            }
        }
        return $inserted;
    }
}
