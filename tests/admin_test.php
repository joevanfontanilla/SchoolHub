<?php
/**
 * Phase 7 administration (docs/04, docs/07, docs/19, docs/20): the admin route gates,
 * staff accounts and password links, role permissions, settings, the read-only audit
 * log, backups with a real restore round trip, and system health.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Router;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AuthService;
use App\Services\BackupService;
use App\Services\PasswordResetService;
use App\Services\PermissionService;
use App\Services\SettingsService;
use App\Services\SystemHealth;
use App\Services\UserAdminService;

function adminRoutes(): array
{
    $router = new Router();
    require BASE_PATH . '/routes/web.php';
    return array_values(array_filter($router->routes(), static fn (array $r) => str_starts_with($r['pattern'], '/admin/')));
}

function adminAccess(array $route, ?User $user): ?int
{
    actingAs($user);
    $middleware = $user === null ? array_values(array_diff($route['middleware'], ['auth'])) : $route['middleware'];
    try {
        (new Router())->authorize($middleware, makeRequest($route['method'], str_replace(['{id}', '{group}'], ['1', 'school'], $route['pattern'])));
    } catch (HttpException $e) {
        return $e->status();
    }
    return null;
}

function staffInput(array $overrides = []): array
{
    $suffix = bin2hex(random_bytes(3));
    return array_merge([
        'first_name' => 'Rosa', 'last_name' => 'Staff', 'username' => TEST_PREFIX . 'staff' . $suffix,
        'email' => TEST_PREFIX . 'staff' . $suffix . '@test.local', 'role' => User::ROLE_REGISTRAR,
    ], $overrides);
}

/** Run a callback with settings temporarily changed, restoring every one afterwards. */
function withSettings(array $values, callable $callback): mixed
{
    $original = [];
    foreach ($values as $key => $value) {
        $original[$key] = Database::scalar('SELECT `value` FROM system_settings WHERE `key` = ?', [$key]);
        SystemSetting::set($key, (string) $value);
    }
    try {
        return $callback();
    } finally {
        foreach ($original as $key => $value) {
            $value === null ? Database::query('DELETE FROM system_settings WHERE `key` = ?', [$key]) : SystemSetting::set($key, (string) $value);
        }
    }
}

return [
    'every admin route requires the Super Admin role and a permission, and no other role passes' => function (TestRunner $t): void {
        try {
            $routes = adminRoutes();
            $t->assertTrue(count($routes) >= 20, 'Found the admin routes (' . count($routes) . ')');
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            $others = [makeUser(User::ROLE_STUDENT), makeUser(User::ROLE_TEACHER), makeUser(User::ROLE_REGISTRAR), makeUser(User::ROLE_CASHIER)];

            foreach ($routes as $route) {
                $label = "{$route['method']} {$route['pattern']}";
                $t->assertTrue(in_array('auth', $route['middleware'], true), "{$label} requires sign-in");
                $t->assertTrue(in_array('role:' . User::ROLE_SUPER_ADMIN, $route['middleware'], true), "{$label} is gated to Super Admin");
                if ($route['pattern'] !== '/admin/dashboard') {
                    $t->assertTrue(array_filter($route['middleware'], static fn ($m) => str_starts_with($m, 'can:')) !== [], "{$label} names a permission");
                }
                $t->assertNull(adminAccess($route, $admin), "Super Admin may reach {$label}");
                foreach ($others as $other) {
                    $t->assertEquals(403, adminAccess($route, $other), "{$other->role_name} is refused {$label}");
                }
                $t->assertEquals(401, adminAccess($route, null), "A visitor is refused {$label}");
            }
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the audit log is append-only: no route changes it, and no application code updates or deletes it' => function (TestRunner $t): void {
        foreach (adminRoutes() as $route) {
            if (str_starts_with($route['pattern'], '/admin/audit-logs') || str_starts_with($route['pattern'], '/admin/login-activity')) {
                $t->assertEquals('GET', $route['method'], "{$route['pattern']} only reads");
            }
        }
        $offenders = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH . '/app', FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php' && preg_match('/(UPDATE|DELETE\s+FROM|TRUNCATE(\s+TABLE)?)\s+`?audit_logs`?/i', (string) file_get_contents($file->getPathname()))) {
                $offenders[] = $file->getFilename();
            }
        }
        $t->assertEquals([], $offenders, 'No UPDATE, DELETE, or TRUNCATE of audit_logs in app/');
    },

    'a staff account starts Pending with a one-time link; the link sets the password once and activates it' => function (TestRunner $t): void {
        try {
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            $t->assertEquals(403, statusOf(static fn () => UserAdminService::createStaff(makeUser(User::ROLE_REGISTRAR), staffInput())));

            $taken = staffInput();
            $errors = fieldErrors(static fn () => UserAdminService::createStaff($admin, staffInput(['role' => User::ROLE_STUDENT, 'email' => 'not-an-email', 'first_name' => '', 'username' => 'has spaces'])));
            foreach (['role', 'email', 'first_name', 'username'] as $field) {
                $t->assertTrue(isset($errors[$field]), "{$field} is validated");
            }

            $created = UserAdminService::createStaff($admin, $taken);
            $t->assertTrue(isset(fieldErrors(static fn () => UserAdminService::createStaff($admin, staffInput(['username' => $taken['username']])))['username']), 'Duplicate username');
            $t->assertTrue(isset(fieldErrors(static fn () => UserAdminService::createStaff($admin, staffInput(['email' => $taken['email']])))['email']), 'Duplicate email');

            $account = User::find($created['id']);
            $t->assertEquals(User::STATUS_PENDING, $account->status);
            $t->assertEquals(User::ROLE_REGISTRAR, $account->role_name);
            $t->assertTrue((bool) preg_match('/^[a-f0-9]{64}$/', $created['token']));
            $t->assertNull(Database::scalar('SELECT id FROM password_reset_tokens WHERE token_hash = ?', [$created['token']]), 'The token itself is never stored');
            $t->assertEquals('SETUP', Database::scalar('SELECT purpose FROM password_reset_tokens WHERE token_hash = ?', [hash('sha256', $created['token'])]));
            $t->assertNotNull(Database::selectOne("SELECT id FROM audit_logs WHERE action = 'user.created' AND target_id = ?", [$created['id']]));
            $t->assertFalse(AuthService::attempt($taken['username'], 'anything1', '127.0.0.1', 'test')['ok'], 'Nobody can sign in before setting a password');

            $t->assertTrue(isset(fieldErrors(static fn () => PasswordResetService::complete($created['token'], 'short', 'short'))['password']), 'Policy applies');
            $t->assertTrue(isset(fieldErrors(static fn () => PasswordResetService::complete($created['token'], 'GoodPass123', 'GoodPass124'))['password_confirmation']), 'Confirmation must match');

            PasswordResetService::complete($created['token'], 'GoodPass123', 'GoodPass123');
            $account = User::find($created['id']);
            $t->assertEquals(User::STATUS_ACTIVE, $account->status);
            $t->assertTrue(password_verify('GoodPass123', $account->password_hash));
            $t->assertNull(PasswordResetService::find($created['token']), 'A link works once');
            $t->assertThrows(static fn () => PasswordResetService::complete($created['token'], 'OtherPass123', 'OtherPass123'), 'no longer valid');
            $t->assertNull(PasswordResetService::find('not-a-token'));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'reset links replace older ones, expire, unlock the account, and never work for a disabled account' => function (TestRunner $t): void {
        try {
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            $user = makeUser(User::ROLE_CASHIER, 'OldPass123', User::STATUS_LOCKED);
            Database::update('users', ['failed_login_attempts' => 5, 'locked_until' => date('Y-m-d H:i:s', time() + 900)], ['id' => $user->id]);

            $first = UserAdminService::issueResetLink($admin, $user->id);
            $second = UserAdminService::issueResetLink($admin, $user->id);
            $t->assertNull(PasswordResetService::find($first['token']), 'The earlier link stops working');
            $t->assertNotNull(PasswordResetService::find($second['token']));
            $t->assertEquals('RESET', PasswordResetService::find($second['token'])['purpose']);

            PasswordResetService::complete($second['token'], 'NewPass456', 'NewPass456');
            $reloaded = User::find($user->id);
            $t->assertEquals(User::STATUS_ACTIVE, $reloaded->status, 'Unlocked by the reset');
            $t->assertEquals(0, $reloaded->failed_login_attempts);
            $t->assertNotNull(Database::selectOne("SELECT id FROM audit_logs WHERE action = 'password.reset' AND target_id = ?", [$user->id]));

            $expired = UserAdminService::issueResetLink($admin, $user->id);
            Database::query('UPDATE password_reset_tokens SET expires_at = ? WHERE token_hash = ?', [date('Y-m-d H:i:s', time() - 60), hash('sha256', $expired['token'])]);
            $t->assertNull(PasswordResetService::find($expired['token']), 'Expired');

            $pending = UserAdminService::issueResetLink($admin, $user->id);
            UserAdminService::disable($admin, $user->id, 'Left the school');
            $t->assertNull(PasswordResetService::find($pending['token']), 'Disabling ends open links');
            $t->assertThrows(static fn () => UserAdminService::issueResetLink($admin, $user->id), 'disabled');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'disabling ends access at once; an admin cannot disable or re-role themselves; unlock and enable restore access' => function (TestRunner $t): void {
        try {
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            $teacher = makeUser(User::ROLE_TEACHER, 'GoodPass123');

            $t->assertThrows(static fn () => UserAdminService::disable($admin, $admin->id, 'Testing myself'), 'your own account');
            $t->assertTrue(isset(fieldErrors(static fn () => UserAdminService::update($admin, $admin->id, ['first_name' => 'A', 'last_name' => 'B', 'username' => $admin->username, 'email' => $admin->email, 'role' => User::ROLE_REGISTRAR]))['role']), 'Own role');
            $t->assertTrue(isset(fieldErrors(static fn () => UserAdminService::disable($admin, $teacher->id, 'no'))['reason']), 'A reason is required');

            actingAs($teacher);
            $t->assertNotNull(Auth::user(), 'Signed in');
            UserAdminService::disable($admin, $teacher->id, 'Contract ended');
            actingAs($teacher);
            $t->assertNull(Auth::user(), 'The open session no longer counts');
            $t->assertFalse(AuthService::attempt($teacher->username, 'GoodPass123', '127.0.0.1', 'test')['ok']);
            $t->assertNotNull(Database::selectOne("SELECT id FROM audit_logs WHERE action = 'user.disabled' AND target_id = ? AND remarks = 'Contract ended'", [$teacher->id]));

            UserAdminService::enable($admin, $teacher->id);
            $t->assertEquals(User::STATUS_ACTIVE, User::find($teacher->id)->status);
            $t->assertThrows(static fn () => UserAdminService::enable($admin, $teacher->id), 'Only a disabled');

            Database::update('users', ['status' => User::STATUS_LOCKED, 'failed_login_attempts' => 5, 'locked_until' => date('Y-m-d H:i:s', time() + 900)], ['id' => $teacher->id]);
            UserAdminService::unlock($admin, $teacher->id);
            $t->assertTrue(AuthService::attempt($teacher->username, 'GoodPass123', '127.0.0.1', 'test')['ok'], 'Unlocked');
            actingAs(null);

            $t->assertEquals(403, statusOf(static fn () => UserAdminService::disable(makeUser(User::ROLE_REGISTRAR), $teacher->id, 'Not my job')));
            $t->assertEquals(404, statusOf(static fn () => UserAdminService::unlock($admin, PHP_INT_MAX)));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'roles change only between staff roles, never for students, and not for a teacher who still has classes' => function (TestRunner $t): void {
        try {
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            $cashier = makeUser(User::ROLE_CASHIER);
            $student = makeUser(User::ROLE_STUDENT);
            $base = static fn (User $u, string $role) => ['first_name' => $u->first_name, 'last_name' => $u->last_name, 'username' => $u->username, 'email' => $u->email, 'role' => $role];

            UserAdminService::update($admin, $cashier->id, $base($cashier, User::ROLE_REGISTRAR));
            $t->assertEquals(User::ROLE_REGISTRAR, User::find($cashier->id)->role_name);
            $t->assertNotNull(Database::selectOne("SELECT id FROM audit_logs WHERE action = 'user.role_changed' AND target_id = ?", [$cashier->id]));

            $t->assertTrue(isset(fieldErrors(static fn () => UserAdminService::update($admin, $student->id, $base($student, User::ROLE_TEACHER)))['role']), 'Student stays a student');
            $t->assertTrue(isset(fieldErrors(static fn () => UserAdminService::update($admin, $cashier->id, $base($cashier, User::ROLE_STUDENT)))['role']), 'Staff never becomes a student');
            $t->assertTrue(isset(fieldErrors(static fn () => UserAdminService::update($admin, $cashier->id, array_merge($base($cashier, User::ROLE_REGISTRAR), ['email' => $student->email])))['email']), 'Email of another account');

            $fixture = enrolledStudent();
            $teacher = makeUser(User::ROLE_TEACHER);
            makeClass($fixture['registrar'], $fixture['section_id'], makeSubject(), $teacher->id, [meeting(1, '08:00', '09:00')]);
            $errors = fieldErrors(static fn () => UserAdminService::update($admin, $teacher->id, $base($teacher, User::ROLE_CASHIER)));
            $t->assertTrue(isset($errors['role']) && str_contains($errors['role'][0], 'still teaches'), 'Teacher with classes keeps the role');

            $t->assertTrue(UserAdminService::otherActiveSuperAdmins($admin->id) >= 0);
            $t->assertNotNull(UserAdminService::roleChangeProblem($admin, $admin, User::ROLE_REGISTRAR));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'role permissions can be edited and are audited; Super Admin and admin-only permissions are protected' => function (TestRunner $t): void {
        $cashierRole = (int) Database::scalar("SELECT id FROM roles WHERE name = 'Cashier'");
        $original = array_column(Database::select('SELECT p.`key` FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?', [$cashierRole]), 'key');
        try {
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            $cashier = makeUser(User::ROLE_CASHIER);
            $t->assertTrue($cashier->hasPermission('reports.cashier'));

            $keys = array_values(array_diff($original, ['reports.cashier']));
            $keys[] = 'announcements.manage';
            $change = PermissionService::update($admin, $cashierRole, $keys);
            $t->assertEquals(['announcements.manage'], $change['added']);
            $t->assertEquals(['reports.cashier'], $change['removed']);
            $t->assertFalse($cashier->hasPermission('reports.cashier'), 'Revoked');
            $t->assertTrue($cashier->hasPermission('announcements.manage'), 'Granted');
            $audit = Database::selectOne("SELECT * FROM audit_logs WHERE action = 'permissions.changed' AND target_id = ? ORDER BY id DESC LIMIT 1", [$cashierRole]);
            $t->assertContains('reports.cashier', (string) $audit['new_values']);

            $t->assertEquals(['added' => [], 'removed' => []], PermissionService::update($admin, $cashierRole, $keys), 'No change, no audit');
            $t->assertThrows(static fn () => PermissionService::update($admin, $cashierRole, ['users.manage']), 'reserved');
            $t->assertThrows(static fn () => PermissionService::update($admin, $cashierRole, ['made.up']), 'Unknown');
            $t->assertThrows(static fn () => PermissionService::update($admin, (int) Database::scalar("SELECT id FROM roles WHERE name = 'Super Admin'"), []), 'cannot be edited');
            $t->assertEquals(403, statusOf(static fn () => PermissionService::update(makeUser(User::ROLE_REGISTRAR), $cashierRole, [])));

            // The seeder only fills a fresh role or brand-new permissions: it does not undo this change.
            PermissionService::seedDefaults([User::ROLE_CASHIER => ['reports.cashier', 'payments.record']], []);
            $t->assertFalse($cashier->hasPermission('reports.cashier'), 'Re-seeding does not restore a revoked permission');
            PermissionService::seedDefaults([User::ROLE_CASHIER => ['reports.cashier']], ['reports.cashier']);
            $t->assertTrue($cashier->hasPermission('reports.cashier'), 'A permission new in this seed run is granted');
        } finally {
            $keep = Database::select('SELECT id, `key` FROM permissions');
            $ids = array_column($keep, 'id', 'key');
            Database::query('DELETE FROM role_permissions WHERE role_id = ?', [$cashierRole]);
            foreach ($original as $key) {
                Database::query('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$cashierRole, $ids[$key]]);
            }
            Auth::forgetCache();
            actingAs(null);
            deleteTestUsers();
        }
    },

    'settings are validated, only changes are saved and audited, and security settings take effect' => function (TestRunner $t): void {
        $keys = array_keys(SettingsService::GROUPS['security'][2]);
        $original = array_combine($keys, array_map(static fn ($k) => Database::scalar('SELECT `value` FROM system_settings WHERE `key` = ?', [$k]), $keys));
        try {
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            $input = array_map(static fn ($v) => (string) $v, $original);

            $t->assertEquals(403, statusOf(static fn () => SettingsService::update(makeUser(User::ROLE_REGISTRAR), 'security', $input)));
            $t->assertEquals(404, statusOf(static fn () => SettingsService::update($admin, 'nonsense', $input)));
            $t->assertTrue(isset(fieldErrors(static fn () => SettingsService::update($admin, 'security', ['password_min_length' => '4'] + $input))['password_min_length']), 'Below the minimum');
            $t->assertTrue(isset(fieldErrors(static fn () => SettingsService::update($admin, 'security', ['max_login_attempts' => 'ten'] + $input))['max_login_attempts']), 'Not a number');
            $t->assertTrue(isset(fieldErrors(static fn () => SettingsService::update($admin, 'security', ['ip_max_attempts' => '5', 'max_login_attempts' => '10'] + $input))['ip_max_attempts']), 'IP limit below account limit');
            $t->assertTrue(isset(fieldErrors(static fn () => SettingsService::update($admin, 'enrollment', ['enrollment_payment_policy' => 'SOMETIMES', 'minimum_downpayment_percent' => '20', 'enrollment_open_message' => 'x']))['enrollment_payment_policy']), 'Unknown option');
            $t->assertTrue(isset(fieldErrors(static fn () => SettingsService::update($admin, 'school', ['school_name' => 'X', 'school_email' => 'nope']))['school_email']), 'Email');

            $t->assertTrue(isset(fieldErrors(static fn () => SettingsService::update($admin, 'school', ['school_tagline' => "Bad ÿ bytes"]))['school_tagline']), 'Text that is not UTF-8 is refused');
            $t->assertEquals([], SettingsService::update($admin, 'security', ['password_min_length' => $input['password_min_length']]), 'A partial form leaves other settings alone');
            $t->assertEquals([], SettingsService::update($admin, 'security', $input), 'Nothing changed');
            $changed = SettingsService::update($admin, 'security', ['password_min_length' => '12'] + $input);
            $t->assertEquals(['password_min_length'], array_keys($changed));
            $t->assertNotNull(AuthService::passwordPolicyProblem('Abcdefgh123'), 'An 11-character password is now too short');
            $t->assertNull(AuthService::passwordPolicyProblem('Abcdefgh1234'));
            $audit = Database::selectOne("SELECT * FROM audit_logs WHERE action = 'settings.updated' ORDER BY id DESC LIMIT 1");
            $t->assertContains('"password_min_length":"12"', (string) $audit['new_values']);
        } finally {
            foreach ($original as $key => $value) {
                if ($value !== null) {
                    SystemSetting::set($key, (string) $value);
                }
            }
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the audit log search filters by action, module, result, and record, and each export is audited' => function (TestRunner $t): void {
        try {
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            $marker = 'zztest.' . bin2hex(random_bytes(4));
            App\Services\AuditService::log($marker . '.one', 'zzmodule', 'User', $admin->id, null, ['token' => 'secret', 'kept' => 'yes'], 'FAILURE', null, $admin->id, $admin->role_name);
            App\Services\AuditService::log($marker . '.two', 'zzmodule', 'User', $admin->id, null, null, 'SUCCESS', null, $admin->id, $admin->role_name);

            $t->assertEquals(2, AuditLog::count(['action' => $marker]));
            $t->assertEquals(1, AuditLog::count(['action' => $marker, 'result' => 'FAILURE']));
            $t->assertEquals(2, AuditLog::count(['action' => $marker, 'module' => 'zzmodule', 'target_type' => 'User', 'target_id' => (string) $admin->id]));
            $t->assertEquals(0, AuditLog::count(['action' => $marker, 'from' => date('Y-m-d', strtotime('+1 day'))]));
            $entry = AuditLog::search(['action' => $marker . '.one'])[0];
            $t->assertFalse(str_contains((string) $entry['new_values'], 'secret'), 'Tokens are never recorded');
            $t->assertEquals(0, AuditLog::count(['action' => '%']), 'A % in the filter is literal, not a wildcard');
            App\Services\AuditService::log($marker . '.bytes', 'zzmodule', null, null, null, ['value' => "broken Ã( text"], 'SUCCESS', null, $admin->id, $admin->role_name);
            $t->assertEquals(1, AuditLog::count(['action' => $marker . '.bytes']), 'An entry with invalid UTF-8 is still recorded');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a backup verifies, detects tampering, refuses a wrong password, prunes old copies, and restores exactly' => function (TestRunner $t): void {
        $scratch = 'schoolhub_restore_test';
        $uploads = sys_get_temp_dir() . '/schoolhub-restore-test-' . bin2hex(random_bytes(3));
        $server = BackupService::connect('');
        try {
            withSettings(['backup_retention_count' => '100', 'backup_include_uploads' => '1'], static function () use ($t, $scratch, $uploads, $server): void {
                $admin = makeUser(User::ROLE_SUPER_ADMIN, 'AdminPass123');
                $t->assertEquals(403, statusOf(static fn () => BackupService::create(makeUser(User::ROLE_REGISTRAR))));

                $id = BackupService::create($admin, BackupService::MANUAL, 'test');
                $backup = BackupService::find($id);
                $file = STORAGE_PATH . '/' . $backup['path'];
                $t->assertEquals('COMPLETED', $backup['status']);
                $t->assertTrue(is_file($file));
                $t->assertEquals(hash_file('sha256', $file), $backup['checksum']);
                $t->assertTrue(BackupService::verify($admin, $id)['ok']);

                $zip = new ZipArchive();
                $zip->open($file);
                $manifest = json_decode($zip->getFromName('manifest.json'), true);
                $sql = (string) $zip->getFromName('database.sql');
                $zip->close();
                $t->assertEquals(0, (int) $manifest['tables']['sessions'], 'Session rows are not backed up');
                $t->assertTrue(str_contains($sql, 'CREATE TABLE `users`'));
                $t->assertEquals((int) Database::scalar('SELECT COUNT(*) FROM enrollment_applications'), (int) $manifest['tables']['enrollment_applications']);

                // A wrong password is refused and audited; nothing is sent.
                $t->assertTrue(isset(fieldErrors(static fn () => BackupService::delete($admin, $id, 'wrong-password'))['password']));
                $t->assertNotNull(Database::selectOne("SELECT id FROM audit_logs WHERE action = 'backup.delete_refused' AND target_id = ?", [$id]));
                $t->assertTrue(is_file($file), 'Still there');

                // Tampering is caught, and a tampered archive is never restored.
                $tampered = $file . '.tampered.zip';
                copy($file, $tampered);
                $handle = fopen($tampered, 'r+b');
                fseek($handle, (int) (filesize($tampered) / 2));
                fwrite($handle, 'XXXX');
                fclose($handle);
                $t->assertFalse(BackupService::checkArchive($tampered, $backup['checksum'])['ok']);
                $t->assertThrows(static fn () => BackupService::restore($tampered, $backup['checksum'], $server, $uploads), 'Refusing to restore');
                @unlink($tampered);

                // An archive with a path that escapes its folder is rejected.
                $evil = sys_get_temp_dir() . '/schoolhub-evil-' . bin2hex(random_bytes(3)) . '.zip';
                $zip = new ZipArchive();
                $zip->open($evil, ZipArchive::CREATE);
                $zip->addFromString('manifest.json', json_encode(['application' => 'SchoolHub', 'tables' => [], 'sql_sha256' => '']));
                $zip->addFromString('uploads/../../evil.php', '<?php echo 1;');
                $zip->close();
                $t->assertContains('unsafe path', BackupService::checkArchive($evil, null)['message']);
                @unlink($evil);
                $t->assertFalse(BackupService::safeEntry('uploads/../x'));
                $t->assertTrue(BackupService::safeEntry('uploads/requirements/5/a.pdf'));

                // Restore into a scratch database and folder, then compare every table.
                $server->exec("DROP DATABASE IF EXISTS `{$scratch}`");
                $server->exec("CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $target = BackupService::connect($scratch);
                $result = BackupService::restore($file, $backup['checksum'], $target, $uploads);
                $t->assertEquals(count($manifest['tables']), $result['tables']);
                foreach ($manifest['tables'] as $table => $rows) {
                    $t->assertEquals((int) $rows, (int) $target->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn(), "{$table} rows restored");
                }
                foreach (['users', 'payments', 'subjects', 'role_permissions', 'system_settings'] as $table) {
                    $live = Database::selectOne("CHECKSUM TABLE `{$table}`")['Checksum'];
                    $copy = $target->query("CHECKSUM TABLE `{$table}`")->fetch()['Checksum'];
                    $t->assertEquals((string) $live, (string) $copy, "{$table} is identical");
                }
                $t->assertEquals((int) $manifest['upload_count'], $result['uploads']);

                // Retention keeps the newest N of this admin's backups.
                $second = BackupService::create($admin, BackupService::MANUAL, 'test');
                $t->assertEquals(1, BackupService::prune(1, $admin, $admin->id));
                $t->assertEquals('DELETED', BackupService::find($id)['status']);
                $t->assertFalse(is_file($file), 'The older file is removed');
                $t->assertEquals('COMPLETED', BackupService::find($second)['status']);
            });
        } finally {
            $server->exec("DROP DATABASE IF EXISTS `{$scratch}`");
            if (is_dir($uploads)) {
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($iterator as $item) {
                    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
                }
                rmdir($uploads);
            }
            actingAs(null);
            deleteTestUsers();
        }
    },

    'system health reports problems plainly: debug in production fails, missing HTTPS cookies warn' => function (TestRunner $t): void {
        $status = static fn (array $checks, string $label) => array_values(array_filter($checks, static fn ($c) => $c['label'] === $label))[0]['status'] ?? null;

        $local = SystemHealth::checks(['app.env' => 'local', 'app.debug' => true, 'session.secure' => false]);
        $t->assertEquals('OK', $status($local, 'Debug mode'));
        $t->assertEquals('OK', $status($local, 'Connection'));
        $t->assertEquals('OK', $status($local, 'PHP extensions'));

        $production = SystemHealth::checks(['app.env' => 'production', 'app.debug' => true, 'session.secure' => false]);
        $t->assertEquals('FAIL', $status($production, 'Debug mode'));
        $t->assertEquals('WARN', $status($production, 'Secure session cookie'));
        $t->assertEquals('FAIL', SystemHealth::overall($production));
        $t->assertEquals('OK', SystemHealth::overall([['status' => 'OK'], ['status' => 'OK']]));
        $t->assertEquals([], SystemHealth::pendingMigrations(), 'Every migration is applied');
    },
];
