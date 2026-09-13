<?php
/**
 * Authentication: sign-in, account states, lockout, password change (docs/07, docs/22).
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Config;
use App\Core\Database;
use App\Models\User;
use App\Services\AuthService;

return [
    'correct credentials sign a user in' => function (TestRunner $t): void {
        try {
            $user = makeUser(User::ROLE_STUDENT, 'GoodPass123');
            $result = AuthService::attempt($user->username, 'GoodPass123', '127.0.0.1', 'test');

            $t->assertTrue($result['ok'], 'Sign-in should succeed');
            $t->assertNotNull($result['user']);
            $t->assertEquals($user->id, $result['user']->id);
        } finally {
            deleteTestUsers();
            deleteTestLoginLogs();
        }
    },

    'a wrong password is rejected' => function (TestRunner $t): void {
        try {
            $user = makeUser(User::ROLE_STUDENT, 'GoodPass123');
            $result = AuthService::attempt($user->username, 'WrongPass123', '127.0.0.1', 'test');

            $t->assertFalse($result['ok'], 'Sign-in should fail');
            $t->assertEquals(AuthService::INVALID_CREDENTIALS, $result['message']);
        } finally {
            deleteTestUsers();
            deleteTestLoginLogs();
        }
    },

    'an unknown account gives the same message as a wrong password' => function (TestRunner $t): void {
        try {
            $result = AuthService::attempt(TEST_PREFIX . 'nobody', 'whatever123', '127.0.0.1', 'test');

            $t->assertFalse($result['ok']);
            // Identical messages are what stops account enumeration.
            $t->assertEquals(AuthService::INVALID_CREDENTIALS, $result['message']);
        } finally {
            deleteTestLoginLogs();
        }
    },

    'a disabled account cannot sign in' => function (TestRunner $t): void {
        try {
            $user = makeUser(User::ROLE_TEACHER, 'GoodPass123', User::STATUS_DISABLED);
            $result = AuthService::attempt($user->username, 'GoodPass123', '127.0.0.1', 'test');

            $t->assertFalse($result['ok'], 'A disabled account must be refused');
            $t->assertContains('disabled', $result['message']);
        } finally {
            deleteTestUsers();
            deleteTestLoginLogs();
        }
    },

    'a locked account cannot sign in even with the right password' => function (TestRunner $t): void {
        try {
            $user = makeUser(User::ROLE_CASHIER, 'GoodPass123');
            Database::update('users', [
                'status'       => User::STATUS_LOCKED,
                'locked_until' => date('Y-m-d H:i:s', time() + 600),
            ], ['id' => $user->id]);

            $result = AuthService::attempt($user->username, 'GoodPass123', '127.0.0.1', 'test');

            $t->assertFalse($result['ok'], 'A locked account must be refused');
            $t->assertContains('locked', $result['message']);
        } finally {
            deleteTestUsers();
            deleteTestLoginLogs();
        }
    },

    'an expired lockout lets the user back in' => function (TestRunner $t): void {
        try {
            $user = makeUser(User::ROLE_STUDENT, 'GoodPass123');
            Database::update('users', [
                'status'       => User::STATUS_LOCKED,
                'locked_until' => date('Y-m-d H:i:s', time() - 60),
            ], ['id' => $user->id]);

            $reloaded = User::find($user->id);
            $t->assertFalse($reloaded->isLocked(), 'A lockout whose expiry has passed is no longer a lockout');
        } finally {
            deleteTestUsers();
            deleteTestLoginLogs();
        }
    },

    'repeated failures lock the account' => function (TestRunner $t): void {
        try {
            $max = (int) Config::get('security.max_login_attempts', 5);
            $user = makeUser(User::ROLE_STUDENT, 'GoodPass123');

            for ($i = 0; $i < $max; $i++) {
                AuthService::attempt($user->username, 'BadPass000', '10.0.0.99', 'test');
            }

            $reloaded = User::find($user->id);
            $t->assertEquals(User::STATUS_LOCKED, $reloaded->status, "Account should lock after {$max} failures");
            $t->assertTrue($reloaded->isLocked());
        } finally {
            deleteTestUsers();
            deleteTestLoginLogs();
        }
    },

    'a failed sign-in is written to the login log' => function (TestRunner $t): void {
        try {
            $user = makeUser(User::ROLE_STUDENT, 'GoodPass123');
            AuthService::attempt($user->username, 'BadPass000', '127.0.0.1', 'test');

            $count = (int) Database::scalar(
                "SELECT COUNT(*) FROM login_logs WHERE username_attempted = ? AND result = 'FAILED'",
                [$user->username]
            );
            $t->assertEquals(1, $count, 'The failed attempt should be logged');
        } finally {
            deleteTestUsers();
            deleteTestLoginLogs();
        }
    },

    'a successful sign-in is written to the audit log' => function (TestRunner $t): void {
        try {
            $user = makeUser(User::ROLE_REGISTRAR, 'GoodPass123');
            AuthService::attempt($user->username, 'GoodPass123', '127.0.0.1', 'test');

            $count = (int) Database::scalar(
                "SELECT COUNT(*) FROM audit_logs WHERE user_id = ? AND action = 'login.success'",
                [$user->id]
            );
            $t->assertEquals(1, $count, 'A successful sign-in must be audited');
        } finally {
            deleteTestUsers();
            deleteTestLoginLogs();
        }
    },

    'the password is never stored in plaintext' => function (TestRunner $t): void {
        try {
            $user = makeUser(User::ROLE_STUDENT, 'PlainSecret123');
            $stored = (string) Database::scalar('SELECT password_hash FROM users WHERE id = ?', [$user->id]);

            $t->assertFalse(
                str_contains($stored, 'PlainSecret123'),
                'The stored value must not contain the plaintext password'
            );
            $t->assertTrue(password_verify('PlainSecret123', $stored), 'The hash must verify');
        } finally {
            deleteTestUsers();
        }
    },

    'a password change requires the current password' => function (TestRunner $t): void {
        try {
            $user = makeUser(User::ROLE_STUDENT, 'GoodPass123');
            $result = AuthService::changePassword($user, 'NotTheCurrent1', 'NewPass456');

            $t->assertFalse($result['ok'], 'A wrong current password must be rejected');
        } finally {
            deleteTestUsers();
        }
    },

    'the password policy rejects weak passwords' => function (TestRunner $t): void {
        $t->assertNotNull(AuthService::passwordPolicyProblem('short1'), 'Too short must be rejected');
        $t->assertNotNull(AuthService::passwordPolicyProblem('alphabetsonly'), 'No digit must be rejected');
        $t->assertNotNull(AuthService::passwordPolicyProblem('12345678'), 'No letter must be rejected');
        $t->assertNull(AuthService::passwordPolicyProblem('Reasonable1'), 'A valid password must pass');
    },

    'a changed password takes effect and clears must_change_password' => function (TestRunner $t): void {
        try {
            $user = makeUser(User::ROLE_TEACHER, 'GoodPass123', User::STATUS_ACTIVE, true);
            $result = AuthService::changePassword($user, 'GoodPass123', 'BrandNew456');

            $t->assertTrue($result['ok'], $result['message']);

            $reloaded = User::find($user->id);
            $t->assertFalse($reloaded->must_change_password, 'The forced-change flag should be cleared');
            $t->assertTrue(password_verify('BrandNew456', $reloaded->password_hash));
        } finally {
            deleteTestUsers();
        }
    },
];
