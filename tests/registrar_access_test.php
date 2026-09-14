<?php
/**
 * Registrar routes and record corrections (docs/04, docs/22).
 *
 * The route checks read the REAL route table, so a registrar route added later
 * without its role gate fails here automatically.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Router;
use App\Models\User;
use App\Services\StudentRecordService;

/** @return array<int,array{method:string,pattern:string,middleware:array<int,string>}> */
function registrarRoutes(): array
{
    $router = new Router();
    require BASE_PATH . '/routes/web.php';

    return array_values(array_filter(
        $router->routes(),
        static fn (array $route) => str_starts_with($route['pattern'], '/registrar/') && $route['pattern'] !== '/registrar/dashboard'
    ));
}

function accessStatus(Router $router, array $route, ?User $user): ?int
{
    actingAs($user);
    try {
        $router->authorize($route['middleware'], makeRequest($route['method'], str_replace(['{id}', '{requirementId}'], '1', $route['pattern'])));
    } catch (HttpException $e) {
        return $e->status();
    }
    return null;
}

return [
    'every registrar route requires the Registrar role and a permission' => function (TestRunner $t): void {
        $routes = registrarRoutes();
        $t->assertTrue(count($routes) >= 25, 'Found the registrar routes (' . count($routes) . ')');

        foreach ($routes as $route) {
            $label = "{$route['method']} {$route['pattern']}";
            $t->assertTrue(in_array('auth', $route['middleware'], true), "{$label} requires sign-in");
            $t->assertTrue(in_array('role:' . User::ROLE_REGISTRAR, $route['middleware'], true), "{$label} is gated to the Registrar role");
            $hasPermission = array_filter($route['middleware'], static fn ($m) => str_starts_with($m, 'can:')) !== [];
            $t->assertTrue($hasPermission, "{$label} names a specific permission");
        }
    },

    'no other role, including Super Admin, passes any registrar route' => function (TestRunner $t): void {
        try {
            $router = new Router();
            $routes = registrarRoutes();
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $others = [
                makeUser(User::ROLE_STUDENT), makeUser(User::ROLE_TEACHER),
                makeUser(User::ROLE_CASHIER), makeUser(User::ROLE_SUPER_ADMIN),
            ];

            foreach ($routes as $route) {
                $label = "{$route['method']} {$route['pattern']}";
                $t->assertNull(accessStatus($router, $route, $registrar), "Registrar may reach {$label}");

                foreach ($others as $other) {
                    $t->assertEquals(403, accessStatus($router, $route, $other), "{$other->role_name} must be refused {$label}");
                }
            }
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a signed-out visitor is refused registrar routes' => function (TestRunner $t): void {
        $router = new Router();
        foreach (registrarRoutes() as $route) {
            actingAs(null);
            $status = null;
            try {
                // RequireAuth redirects browsers; the role gate behind it answers 401.
                $router->authorize(array_values(array_diff($route['middleware'], ['auth'])), makeRequest($route['method'], '/x'));
            } catch (HttpException $e) {
                $status = $e->status();
            }
            $t->assertEquals(401, $status, "{$route['method']} {$route['pattern']} must refuse a signed-out visitor");
        }
    },

    'the registrar can correct a locked name, and the audit lists fields but not values' => function (TestRunner $t): void {
        try {
            [$student, $id] = submittedApplication();
            $studentId = (int) Database::scalar('SELECT id FROM students WHERE user_id = ?', [$student->id]);
            $registrar = makeUser(User::ROLE_REGISTRAR);

            $input = completeProfileInput();
            $input['first_name'] = 'Corrected';
            $input['mobile'] = '09999999999';
            StudentRecordService::update($registrar, $studentId, $input);

            $t->assertEquals('Corrected', Database::scalar('SELECT first_name FROM users WHERE id = ?', [$student->id]), 'The student\'s name changed');
            $t->assertEquals('Test', Database::scalar('SELECT first_name FROM users WHERE id = ?', [$registrar->id]), 'The registrar\'s own name did not change');

            $entry = Database::selectOne(
                "SELECT * FROM audit_logs WHERE action = 'student.record_corrected' AND target_id = ? ORDER BY id DESC LIMIT 1",
                [$studentId]
            );
            $t->assertNotNull($entry, 'The correction is audited');
            $t->assertEquals($registrar->id, (int) $entry['user_id']);
            $t->assertContains('"name"', (string) $entry['new_values']);
            $t->assertContains('"mobile"', (string) $entry['new_values']);
            $t->assertFalse(str_contains((string) $entry['new_values'], 'Corrected'), 'The new name is not copied into the audit log');
            $t->assertFalse(str_contains((string) $entry['new_values'], '09999999999'), 'The new number is not copied into the audit log');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'only the registrar may correct a student record' => function (TestRunner $t): void {
        try {
            $student = makeStudent();
            $studentId = (int) Database::scalar('SELECT id FROM students WHERE user_id = ?', [$student->id]);

            $status = null;
            try {
                StudentRecordService::update(makeUser(User::ROLE_SUPER_ADMIN), $studentId, completeProfileInput());
            } catch (HttpException $e) {
                $status = $e->status();
            }
            $t->assertEquals(403, $status);
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },
];
