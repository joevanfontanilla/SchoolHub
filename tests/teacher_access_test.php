<?php
/**
 * Teacher and student academic routes (docs/04, docs/22).
 *
 * Reads the REAL route table, so a route added later without its gate fails here.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\HttpException;
use App\Core\Router;
use App\Models\User;

/** @return array<int,array{method:string,pattern:string,middleware:array<int,string>}> */
function academicRoutes(string $prefix, array $except = []): array
{
    $router = new Router();
    require BASE_PATH . '/routes/web.php';

    return array_values(array_filter(
        $router->routes(),
        static fn (array $route) => str_starts_with($route['pattern'], $prefix) && !in_array($route['pattern'], $except, true)
    ));
}

function academicAccessStatus(Router $router, array $route, ?User $user): ?int
{
    actingAs($user);
    try {
        $router->authorize($route['middleware'], makeRequest($route['method'], str_replace('{id}', '1', $route['pattern'])));
    } catch (HttpException $e) {
        return $e->status();
    }
    return null;
}

const STUDENT_ACADEMIC_ROUTES = ['/student/schedule', '/student/grades', '/student/attendance', '/student/history'];

return [
    'every teacher route requires the Teacher role and a permission' => function (TestRunner $t): void {
        $routes = academicRoutes('/teacher/', ['/teacher/dashboard']);
        $t->assertTrue(count($routes) >= 9, 'Found the teacher routes (' . count($routes) . ')');

        foreach ($routes as $route) {
            $label = "{$route['method']} {$route['pattern']}";
            $t->assertTrue(in_array('auth', $route['middleware'], true), "{$label} requires sign-in");
            $t->assertTrue(in_array('role:' . User::ROLE_TEACHER, $route['middleware'], true), "{$label} is gated to the Teacher role");
            $t->assertTrue(array_filter($route['middleware'], static fn ($m) => str_starts_with($m, 'can:')) !== [], "{$label} names a permission");
        }
    },

    'no other role, including Super Admin, passes any teacher route' => function (TestRunner $t): void {
        try {
            $router = new Router();
            $teacher = makeUser(User::ROLE_TEACHER);
            $others = [makeUser(User::ROLE_STUDENT), makeUser(User::ROLE_REGISTRAR), makeUser(User::ROLE_CASHIER), makeUser(User::ROLE_SUPER_ADMIN)];

            foreach (academicRoutes('/teacher/') as $route) {
                $label = "{$route['method']} {$route['pattern']}";
                $t->assertNull(academicAccessStatus($router, $route, $teacher), "A teacher may reach {$label}");
                foreach ($others as $other) {
                    $t->assertEquals(403, academicAccessStatus($router, $route, $other), "{$other->role_name} must be refused {$label}");
                }
            }
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the student academic pages are the student\'s own: Student only, and no id in the URL' => function (TestRunner $t): void {
        try {
            $router = new Router();
            $student = makeUser(User::ROLE_STUDENT);
            $others = [makeUser(User::ROLE_TEACHER), makeUser(User::ROLE_REGISTRAR), makeUser(User::ROLE_CASHIER), makeUser(User::ROLE_SUPER_ADMIN)];

            $routes = array_values(array_filter(academicRoutes('/student/'), static fn ($r) => in_array($r['pattern'], STUDENT_ACADEMIC_ROUTES, true)));
            $t->assertEquals(4, count($routes));

            foreach ($routes as $route) {
                $label = "{$route['method']} {$route['pattern']}";
                $t->assertFalse(str_contains($route['pattern'], '{'), "{$label} takes no record id, so it can only show the signed-in student's data");
                $t->assertNull(academicAccessStatus($router, $route, $student), "A student may reach {$label}");
                foreach ($others as $other) {
                    $t->assertEquals(403, academicAccessStatus($router, $route, $other), "{$other->role_name} must be refused {$label}");
                }
            }
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a signed-out visitor is refused teacher and student academic routes' => function (TestRunner $t): void {
        $router = new Router();
        foreach ([...academicRoutes('/teacher/'), ...array_filter(academicRoutes('/student/'), static fn ($r) => in_array($r['pattern'], STUDENT_ACADEMIC_ROUTES, true))] as $route) {
            actingAs(null);
            $t->assertTrue(in_array('auth', $route['middleware'], true), "{$route['pattern']} requires sign-in");
            // RequireAuth redirects browsers (and exits); the role gate behind it answers 401.
            $gates = ['middleware' => array_values(array_diff($route['middleware'], ['auth']))] + $route;
            $t->assertEquals(401, academicAccessStatus($router, $gates, null), "{$route['method']} {$route['pattern']} must refuse a signed-out visitor");
        }
    },
];
