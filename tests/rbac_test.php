<?php
/**
 * Role-based access control (docs/04, docs/22).
 *
 * docs/22 requires that every role be tried against every protected area, and
 * that the wrong role be denied by the BACKEND. That is the matrix below.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Auth;
use App\Middleware\RequireAuth;
use App\Middleware\RequirePermission;
use App\Middleware\RequireRole;
use App\Models\User;

/** The role each dashboard belongs to. */
const PROTECTED_AREAS = [
    '/student/dashboard'   => User::ROLE_STUDENT,
    '/teacher/dashboard'   => User::ROLE_TEACHER,
    '/registrar/dashboard' => User::ROLE_REGISTRAR,
    '/cashier/dashboard'   => User::ROLE_CASHIER,
    '/admin/dashboard'     => User::ROLE_SUPER_ADMIN,
];

const ALL_ROLES = [
    User::ROLE_STUDENT,
    User::ROLE_TEACHER,
    User::ROLE_REGISTRAR,
    User::ROLE_CASHIER,
    User::ROLE_SUPER_ADMIN,
];

return [
    'every role reaches its own dashboard and is refused every other one' => function (TestRunner $t): void {
        try {
            $users = [];
            foreach (ALL_ROLES as $role) {
                $users[$role] = makeUser($role);
            }

            foreach (ALL_ROLES as $role) {
                actingAs($users[$role]);

                foreach (PROTECTED_AREAS as $path => $ownerRole) {
                    $status = statusOf(static function () use ($path, $ownerRole): void {
                        (new RequireAuth())->handle(makeRequest('GET', $path));
                        (new RequireRole())->handle(makeRequest('GET', $path), $ownerRole);
                    });

                    if ($role === $ownerRole) {
                        $t->assertNull($status, "{$role} should reach {$path}");
                    } else {
                        $t->assertEquals(403, $status, "{$role} must be refused {$path}");
                    }
                }
            }
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a signed-out visitor is refused a protected area' => function (TestRunner $t): void {
        actingAs(null);

        $status = statusOf(static function (): void {
            (new RequireRole())->handle(makeRequest('GET', '/admin/dashboard'), User::ROLE_SUPER_ADMIN);
        });

        $t->assertEquals(401, $status, 'An unauthenticated request must not pass the role gate');
    },

    'Super Admin implicitly holds every permission' => function (TestRunner $t): void {
        try {
            actingAs(makeUser(User::ROLE_SUPER_ADMIN));

            $t->assertTrue(Auth::can('enrollment.approve'));
            $t->assertTrue(Auth::can('payments.record'));
            // Even a permission that does not exist in the table.
            $t->assertTrue(Auth::can('some.permission.that.does.not.exist'));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a student does not hold registrar or cashier permissions' => function (TestRunner $t): void {
        try {
            actingAs(makeUser(User::ROLE_STUDENT));

            $t->assertTrue(Auth::can('enrollment.apply'), 'A student may apply');
            $t->assertFalse(Auth::can('enrollment.approve'), 'A student must not approve enrollment');
            $t->assertFalse(Auth::can('payments.record'), 'A student must not record payments');
            $t->assertFalse(Auth::can('users.manage'), 'A student must not manage users');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a teacher cannot record payments and a cashier cannot record grades' => function (TestRunner $t): void {
        try {
            actingAs(makeUser(User::ROLE_TEACHER));
            $t->assertTrue(Auth::can('grades.record'));
            $t->assertFalse(Auth::can('payments.record'), 'A teacher must not record payments');

            actingAs(null);
            deleteTestUsers();

            actingAs(makeUser(User::ROLE_CASHIER));
            $t->assertTrue(Auth::can('payments.record'));
            $t->assertFalse(Auth::can('grades.record'), 'A cashier must not record grades');
            $t->assertFalse(Auth::can('enrollment.approve'), 'A cashier must not approve enrollment');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the permission gate refuses a role that lacks the permission' => function (TestRunner $t): void {
        try {
            actingAs(makeUser(User::ROLE_TEACHER));

            $status = statusOf(static function (): void {
                (new RequirePermission())->handle(makeRequest('POST', '/registrar/applications/1/approve'), 'enrollment.approve');
            });

            $t->assertEquals(403, $status, 'A teacher must be refused enrollment.approve');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a disabled account loses its session immediately' => function (TestRunner $t): void {
        try {
            $user = makeUser(User::ROLE_REGISTRAR);
            actingAs($user);
            $t->assertTrue(Auth::check(), 'The user should start out signed in');

            App\Core\Database::update('users', ['status' => User::STATUS_DISABLED], ['id' => $user->id]);
            Auth::forgetCache();

            $t->assertFalse(Auth::check(), 'A disabled account must not keep a valid session');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },
];
