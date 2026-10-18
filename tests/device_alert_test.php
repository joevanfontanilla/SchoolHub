<?php
/**
 * One device at a time, and the sign-in alert (docs/07): a correct password while the account is open elsewhere waits for the owner.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\User;
use App\Services\AuthService;
use App\Services\DeviceSessionService;

/** Sign a fixture user in from a fresh browser (own PHP session) and report the outcome. @return array{pending:bool,result:array} */
function signInFrom(User $user, string $ip = '10.0.0.1', string $ua = 'Mozilla/5.0 (Windows NT 10.0) Chrome/120 Safari/537'): array
{
    $_SESSION = [];
    Auth::forgetCache();
    $result = AuthService::attempt($user->username, 'GoodPass123', $ip, $ua);

    return ['pending' => !empty($result['pending']), 'result' => $result];
}

return [
    'the first sign-in goes straight through, and records the device' => function (TestRunner $t): void {
        try {
            $user = makeUser(User::ROLE_STUDENT, 'GoodPass123');
            $in = signInFrom($user);
            $t->assertTrue($in['result']['ok']);
            $t->assertFalse($in['pending'], 'Nothing else is open, so no approval is needed');
            $t->assertTrue(Auth::check(), 'Signed in');
            $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM user_sessions WHERE user_id = ? AND status = 'ACTIVE'", [$user->id]));
            $t->assertEquals('Chrome on Windows', Database::scalar('SELECT device_label FROM user_sessions WHERE user_id = ?', [$user->id]));
        } finally {
            $_SESSION = [];
            deleteTestUsers();
            deleteTestLoginLogs();
        }
    },

    'a second device with the right password waits, is not signed in, and the owner is notified' => function (TestRunner $t): void {
        try {
            $user = makeUser(User::ROLE_TEACHER, 'GoodPass123');
            signInFrom($user, '10.0.0.1');
            $second = signInFrom($user, '10.0.0.2', 'Mozilla/5.0 (Android) Firefox/120');

            $t->assertTrue($second['pending'], 'The new device must wait');
            $t->assertFalse(Auth::check(), 'It is not signed in');
            $t->assertNotNull(DeviceSessionService::pendingToken());
            $pending = DeviceSessionService::pendingFor($user->id);
            $t->assertEquals(1, count($pending));
            $t->assertEquals('Firefox on Android', $pending[0]['device_label']);
            $t->assertEquals('10.0.0.2', $pending[0]['ip_address']);
            $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'security'", [$user->id]), 'A bell notification');
            $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'login.device_pending' AND user_id = ?", [$user->id]));
            $t->assertEquals('waiting', DeviceSessionService::resolvePending(DeviceSessionService::pendingToken())['state']);
        } finally {
            $_SESSION = [];
            deleteTestUsers();
            deleteTestLoginLogs();
        }
    },

    'approving lets the waiting device in, and denying refuses it; only the owner can answer' => function (TestRunner $t): void {
        try {
            $user = makeUser(User::ROLE_REGISTRAR, 'GoodPass123');
            $stranger = makeUser(User::ROLE_REGISTRAR, 'GoodPass123');
            signInFrom($user);
            signInFrom($user, '10.0.0.2');
            $waitingToken = DeviceSessionService::pendingToken();
            $id = (int) DeviceSessionService::pendingFor($user->id)[0]['id'];

            $t->assertThrows(static fn () => DeviceSessionService::approve($stranger, $id), 'no longer waiting');
            $t->assertThrows(static fn () => DeviceSessionService::deny($stranger, $id), 'no longer waiting');
            $t->assertEquals('waiting', DeviceSessionService::resolvePending($waitingToken)['state'], 'Still waiting after a stranger tried');

            DeviceSessionService::approve($user, $id);
            $state = DeviceSessionService::resolvePending($waitingToken);
            $t->assertEquals('approved', $state['state']);
            $t->assertEquals($user->id, $state['user']->id);

            // A third device, denied.
            signInFrom($user, '10.0.0.3');
            $deniedToken = DeviceSessionService::pendingToken();
            DeviceSessionService::deny($user, (int) DeviceSessionService::pendingFor($user->id)[0]['id']);
            $t->assertEquals('denied', DeviceSessionService::resolvePending($deniedToken)['state']);
            $t->assertEquals([], DeviceSessionService::pendingFor($user->id), 'Nothing left waiting');
        } finally {
            $_SESSION = [];
            deleteTestUsers();
            deleteTestLoginLogs();
        }
    },

    'when the device in use has gone idle or signed out, the waiting device is let in by itself' => function (TestRunner $t): void {
        try {
            $user = makeUser(User::ROLE_CASHIER, 'GoodPass123');
            signInFrom($user);
            signInFrom($user, '10.0.0.2');
            $token = DeviceSessionService::pendingToken();
            $t->assertEquals('waiting', DeviceSessionService::resolvePending($token)['state']);

            Database::query("UPDATE user_sessions SET last_seen_at = DATE_SUB(NOW(), INTERVAL 31 MINUTE) WHERE user_id = ? AND status = 'ACTIVE'", [$user->id]);
            $t->assertEquals('approved', DeviceSessionService::resolvePending($token)['state'], 'Idle for 30 minutes: nobody left to ask');
            $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'login.device_auto_allowed' AND user_id = ?", [$user->id]));
        } finally {
            $_SESSION = [];
            deleteTestUsers();
            deleteTestLoginLogs();
        }
    },

    'a request nobody answers expires after 10 minutes' => function (TestRunner $t): void {
        try {
            $user = makeUser(User::ROLE_STUDENT, 'GoodPass123');
            signInFrom($user);
            signInFrom($user, '10.0.0.2');
            $token = DeviceSessionService::pendingToken();
            Database::query("UPDATE user_sessions SET created_at = DATE_SUB(NOW(), INTERVAL 11 MINUTE) WHERE status = 'PENDING' AND user_id = ?", [$user->id]);
            $t->assertEquals([], DeviceSessionService::pendingFor($user->id), 'No longer offered to the owner');
            $t->assertEquals('expired', DeviceSessionService::resolvePending($token)['state']);
        } finally {
            $_SESSION = [];
            deleteTestUsers();
            deleteTestLoginLogs();
        }
    },

    'a device signed out from another one is signed out on its next request, and the owner lists their devices' => function (TestRunner $t): void {
        try {
            $user = makeUser(User::ROLE_TEACHER, 'GoodPass123');
            signInFrom($user, '10.0.0.1');
            $firstSession = $_SESSION;
            // The owner approves a second device, so both are signed in.
            signInFrom($user, '10.0.0.2');
            DeviceSessionService::approve($user, (int) DeviceSessionService::pendingFor($user->id)[0]['id']);
            $secondToken = DeviceSessionService::pendingToken();
            $_SESSION = [];
            Auth::login(User::find($user->id));
            DeviceSessionService::rememberActive((string) $secondToken);
            $second = $_SESSION;

            $t->assertEquals(2, count(DeviceSessionService::devicesFor($user->id)), 'Both devices are listed');
            $current = array_values(array_filter(DeviceSessionService::devicesFor($user->id), static fn ($d) => $d['current']));
            $t->assertEquals(1, count($current), 'This device is marked');
            $t->assertThrows(static fn () => DeviceSessionService::signOutDevice(User::find($user->id), (int) $current[0]['id']), 'device you are using');

            $other = array_values(array_filter(DeviceSessionService::devicesFor($user->id), static fn ($d) => !$d['current']));
            DeviceSessionService::signOutDevice(User::find($user->id), (int) $other[0]['id']);

            $_SESSION = $firstSession;
            Auth::forgetCache();
            $t->assertFalse(DeviceSessionService::touch(User::find($user->id)), 'The first device is signed out on its next request');
            $_SESSION = $second;
            Auth::forgetCache();
            $t->assertTrue(DeviceSessionService::touch(User::find($user->id)), 'The device that stayed is untouched');
        } finally {
            $_SESSION = [];
            deleteTestUsers();
            deleteTestLoginLogs();
        }
    },

    'the administrator releases an account, and nobody else can' => function (TestRunner $t): void {
        try {
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            $user = makeUser(User::ROLE_STUDENT, 'GoodPass123');
            signInFrom($user);
            signInFrom($user, '10.0.0.2');
            $t->assertEquals(403, statusOf(static fn () => DeviceSessionService::releaseAll(makeUser(User::ROLE_REGISTRAR), $user->id)));
            $t->assertEquals(2, DeviceSessionService::releaseAll($admin, $user->id), 'The device in use and the waiting one');
            $t->assertEquals(0, (int) Database::scalar("SELECT COUNT(*) FROM user_sessions WHERE user_id = ? AND status IN ('ACTIVE','PENDING')", [$user->id]));
            $t->assertFalse(signInFrom($user, '10.0.0.9')['pending'], 'The account signs in freely again');
            $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'session.released'"), 'Audited');
        } finally {
            $_SESSION = [];
            deleteTestUsers();
            deleteTestLoginLogs();
        }
    },

    'a locked-out account tells its owner once, but a single wrong password does not' => function (TestRunner $t): void {
        try {
            $user = makeUser(User::ROLE_STUDENT, 'GoodPass123');
            AuthService::attempt($user->username, 'Wrong-1', '10.0.0.5', 'test');
            $t->assertEquals(0, (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'security'", [$user->id]), 'One guess is not announced');
            for ($i = 0; $i < 6; $i++) {
                AuthService::attempt($user->username, 'Wrong-' . $i, '10.0.0.5', 'test');
            }
            $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'security'", [$user->id]), 'The lockout is announced once');
        } finally {
            $_SESSION = [];
            deleteTestUsers();
            deleteTestLoginLogs();
        }
    },

    'a browser signed in before this feature is adopted quietly, with no alert' => function (TestRunner $t): void {
        try {
            $user = makeUser(User::ROLE_STUDENT, 'GoodPass123');
            signInFrom($user);
            // An older session with no device token at all.
            $_SESSION = ['_auth_user_id' => $user->id];
            Auth::forgetCache();
            $t->assertTrue(DeviceSessionService::touch(User::find($user->id)));
            $t->assertNotNull(DeviceSessionService::currentToken(), 'It now has a device row');
            $t->assertEquals([], DeviceSessionService::pendingFor($user->id), 'No alert for it');
            $t->assertEquals(0, (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'security'", [$user->id]));
        } finally {
            $_SESSION = [];
            deleteTestUsers();
            deleteTestLoginLogs();
        }
    },

    'the routes, banner, waiting page and admin button exist, and only a hash of the token is stored' => function (TestRunner $t): void {
        $routes = (string) file_get_contents(BASE_PATH . '/routes/web.php');
        foreach (['/login/waiting', '/security/alerts', '/security/devices/{id}/approve', '/security/devices/{id}/deny', '/security/devices/{id}/sign-out', '/admin/users/{id}/release-devices'] as $path) {
            $t->assertContains($path, $routes);
        }
        $t->assertContains('device-alerts', (string) file_get_contents(BASE_PATH . '/app/Views/layouts/app.php'));
        $t->assertContains('Release signed-in devices', (string) file_get_contents(BASE_PATH . '/app/Views/admin/users/show.php'));
        $t->assertContains('Where you&rsquo;re signed in', (string) file_get_contents(BASE_PATH . '/app/Views/account/index.php'));
        $t->assertContains('X-Background-Poll', (string) file_get_contents(BASE_PATH . '/public/assets/app.js'));
        $t->assertContains('One device at a time', (string) file_get_contents(BASE_PATH . '/docs/07_AUTHENTICATION_AND_SECURITY.md'));

        $user = null;
        try {
            $user = makeUser(User::ROLE_STUDENT, 'GoodPass123');
            signInFrom($user);
            $token = DeviceSessionService::currentToken();
            $stored = (string) Database::scalar('SELECT token_hash FROM user_sessions WHERE user_id = ?', [$user->id]);
            $t->assertTrue($token !== null && $stored !== $token && $stored === hash('sha256', (string) $token), 'The raw token is never stored');
        } finally {
            $_SESSION = [];
            deleteTestUsers();
            deleteTestLoginLogs();
        }
    },
];
