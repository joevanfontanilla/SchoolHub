<?php
/**
 * Administrative reports (docs/04, docs/19, docs/20).
 *
 * Sign-in and audit fixtures are written inside a transaction that is rolled back, so the
 * append-only audit log never keeps test rows. They are dated in January 2000, where no
 * real activity exists, so the counts can be exact.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\Router;
use App\Models\AdminReport;
use App\Models\User;
use App\Services\ReportRange;

/** Run a callback with rows that are rolled back afterwards, whatever happens. */
function withRolledBack(callable $callback): void
{
    $pdo = Database::connection();
    $pdo->beginTransaction();
    try {
        $callback();
    } finally {
        $pdo->rollBack();
    }
}

function accountFlags(string $username): ?array
{
    foreach (AdminReport::accounts()['rows'] as $row) {
        if ($row['username'] === $username) {
            return $row['flags'];
        }
    }
    return null;
}

return [
    'every administrative report route is a GET for the Super Admin with reports.admin' => function (TestRunner $t): void {
        $router = new Router();
        require BASE_PATH . '/routes/web.php';
        $routes = array_filter($router->routes(), static fn ($r) => str_starts_with($r['pattern'], '/admin/reports'));
        $t->assertEquals(13, count($routes));
        foreach ($routes as $route) {
            $t->assertEquals('GET', $route['method'], "{$route['pattern']} changes nothing");
            $t->assertTrue(in_array('role:' . User::ROLE_SUPER_ADMIN, $route['middleware'], true), "{$route['pattern']} is Super Admin only");
            $t->assertTrue(in_array('can:reports.admin', $route['middleware'], true), "{$route['pattern']} needs reports.admin");
        }
    },

    'a report date range falls back to the default when invalid, reversed, or longer than a year' => function (TestRunner $t): void {
        $default = ['2026-09-01', '2026-09-15'];
        $t->assertEquals(['2026-01-01', '2026-01-31', null], ReportRange::parse('2026-01-01', '2026-01-31', ...[...$default, 'x']));
        $t->assertEquals([...$default, null], ReportRange::parse(null, '', ...[...$default, 'x']), 'Nothing given');
        foreach ([['2026-02-30', '2026-03-01'], ['01/02/2026', '2026-03-01'], ["2026-01-01' OR 1=1", '2026-03-01']] as [$from, $to]) {
            $t->assertContains('YYYY-MM-DD', (string) ReportRange::parse($from, $to, ...[...$default, 'x'])[2], "{$from} is not a date");
        }
        $t->assertContains('after the end date', (string) ReportRange::parse('2026-03-01', '2026-02-01', ...[...$default, 'x'])[2]);
        $t->assertContains('at most one year', (string) ReportRange::parse('2025-01-01', '2026-01-02', ...[...$default, 'x'])[2]);
        $t->assertNull(ReportRange::parse('2025-01-01', '2026-01-01', ...[...$default, 'x'])[2], 'A full year, 365 days apart, is allowed');
    },

    'the account review flags unused, inactive, locked, and unfinished accounts, and never a disabled one' => function (TestRunner $t): void {
        try {
            $recent = makeUser(User::ROLE_TEACHER);
            Database::update('users', ['last_login_at' => date('Y-m-d H:i:s', time() - 86400)], ['id' => $recent->id]);
            $inactive = makeUser(User::ROLE_CASHIER);
            Database::update('users', ['last_login_at' => date('Y-m-d H:i:s', time() - 100 * 86400)], ['id' => $inactive->id]);
            $never = makeUser(User::ROLE_REGISTRAR);
            $locked = makeUser(User::ROLE_STUDENT, 'TestPass123', User::STATUS_LOCKED);
            Database::update('users', ['last_login_at' => date('Y-m-d H:i:s')], ['id' => $locked->id]);
            $disabled = makeUser(User::ROLE_TEACHER, 'TestPass123', User::STATUS_DISABLED);
            $pendingExpired = makeUser(User::ROLE_TEACHER, 'TestPass123', User::STATUS_PENDING);
            Database::insert('password_reset_tokens', ['user_id' => $pendingExpired->id, 'token_hash' => hash('sha256', 'expired' . $pendingExpired->id), 'purpose' => 'SETUP', 'expires_at' => date('Y-m-d H:i:s', time() - 3600)]);
            $pendingOpen = makeUser(User::ROLE_TEACHER, 'TestPass123', User::STATUS_PENDING);
            Database::insert('password_reset_tokens', ['user_id' => $pendingOpen->id, 'token_hash' => hash('sha256', 'open' . $pendingOpen->id), 'purpose' => 'SETUP', 'expires_at' => date('Y-m-d H:i:s', time() + 3600)]);

            $t->assertEquals([], accountFlags($recent->username), 'Signed in yesterday');
            $t->assertEquals([AdminReport::FLAG_INACTIVE], accountFlags($inactive->username));
            $t->assertEquals([AdminReport::FLAG_NEVER_SIGNED_IN], accountFlags($never->username));
            $t->assertEquals([AdminReport::FLAG_LOCKED], accountFlags($locked->username));
            $t->assertEquals([], accountFlags($disabled->username), 'Disabled is already out of use');
            $t->assertEquals([AdminReport::FLAG_SETUP_EXPIRED], accountFlags($pendingExpired->username));
            $t->assertEquals([], accountFlags($pendingOpen->username), 'A set-up link still valid');

            $report = AdminReport::accounts(User::ROLE_TEACHER, null, true);
            $names = array_column($report['rows'], 'username');
            $t->assertTrue(in_array($pendingExpired->username, $names, true), 'Flagged teacher listed');
            $t->assertFalse(in_array($recent->username, $names, true), 'Unflagged teacher left out');
            $t->assertFalse(in_array($never->username, $names, true), 'Another role left out');
            $t->assertEquals((int) Database::scalar('SELECT COUNT(*) FROM users'), $report['total'], 'Counts cover every account');
            $t->assertEquals($report['total'], array_sum(array_map('array_sum', $report['matrix'])));
        } finally {
            deleteTestUsers();
        }
    },

    'the sign-in summary counts attempts, failures, and where they come from' => function (TestRunner $t): void {
        withRolledBack(static function () use ($t): void {
            $log = static fn (string $login, string $ip, string $result, string $at, ?int $userId = null) => Database::insert('login_logs', [
                'user_id' => $userId, 'username_attempted' => $login, 'ip_address' => $ip, 'user_agent' => 'test', 'result' => $result, 'attempted_at' => $at,
            ]);
            $userId = (int) Database::scalar('SELECT id FROM users ORDER BY id LIMIT 1');
            $log('__test_guess', '203.0.113.9', 'FAILED', '2000-01-01 08:00:00');
            $log('__test_guess', '203.0.113.9', 'FAILED', '2000-01-01 08:01:00');
            $log('__test_other', '203.0.113.9', 'FAILED', '2000-01-02 09:00:00');
            $log('__test_real', '198.51.100.4', 'SUCCESS', '2000-01-02 10:00:00', $userId);
            $log('__test_late', '198.51.100.4', 'FAILED', '2000-01-04 10:00:00');

            $report = AdminReport::signIns('2000-01-01', '2000-01-03');
            $t->assertEquals([4, 1, 3, 1, 2], [$report['total'], $report['success'], $report['failed'], $report['accounts'], $report['ips']], 'Totals within the range only');
            $t->assertEquals(3, count($report['days']), 'Every day of the range, with or without attempts');
            $t->assertEquals(['day' => '2000-01-03', 'success' => 0, 'failed' => 0], $report['days'][2]);
            $t->assertEquals(['203.0.113.9', 3, 2], [$report['ip_failures'][0]['ip_address'], (int) $report['ip_failures'][0]['failed'], (int) $report['ip_failures'][0]['logins']]);
            $t->assertEquals(1, count($report['ip_failures']), 'An address with no failures is not listed');
            $t->assertEquals(['__test_guess', 2], [$report['login_failures'][0]['username_attempted'], (int) $report['login_failures'][0]['failed']]);
        });
    },

    'the activity summary counts audit entries by module, role, person, and action' => function (TestRunner $t): void {
        withRolledBack(static function () use ($t): void {
            $userId = (int) Database::scalar('SELECT id FROM users ORDER BY id LIMIT 1');
            $entry = static fn (string $action, string $module, string $role, string $result, ?int $user = null) => Database::insert('audit_logs', [
                'user_id' => $user, 'actor_role' => $role, 'action' => $action, 'module' => $module, 'result' => $result, 'created_at' => '2000-01-01 12:00:00',
            ]);
            $entry('grades.recorded', 'grades', 'Teacher', 'SUCCESS', $userId);
            $entry('grades.recorded', 'grades', 'Teacher', 'SUCCESS', $userId);
            $entry('login.failed', 'auth', 'Guest', 'FAILURE');

            $report = AdminReport::activity('2000-01-01', '2000-01-01');
            $t->assertEquals([3, 1], [$report['total'], $report['failures']]);
            $modules = array_column($report['modules'], 'entries', 'module');
            $t->assertEquals(['grades' => 2, 'auth' => 1], array_map('intval', $modules));
            $t->assertEquals(['grades.recorded', 2], [$report['actions'][0]['action'], (int) $report['actions'][0]['entries']], 'Most frequent first');
            $t->assertEquals(1, count($report['actors']), 'Entries with no signed-in person are not attributed');
            $t->assertEquals([$userId, 2], [(int) $report['actors'][0]['user_id'], (int) $report['actors'][0]['entries']]);
            $t->assertEquals([['day' => '2000-01-01', 'entries' => 3]], $report['days']);
        });
    },

    'system usage counts every account and reads storage from the disk' => function (TestRunner $t): void {
        $before = AdminReport::usage();
        $t->assertEquals((int) Database::scalar('SELECT COUNT(*) FROM users'), array_sum($before['roles']), 'Every account is in a role');
        $t->assertTrue($before['records']['database_bytes'] > 0, 'Database size is read');

        $file = STORAGE_PATH . '/logs/__test_usage_probe.log';
        file_put_contents($file, str_repeat('x', 1234));
        try {
            $after = AdminReport::storage();
            $t->assertEquals($before['storage']['logs']['files'] + 1, $after['logs']['files']);
            $t->assertEquals($before['storage']['logs']['bytes'] + 1234, $after['logs']['bytes']);
        } finally {
            @unlink($file);
        }
    },
];
