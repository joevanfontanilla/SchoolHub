<?php
/**
 * Route table.
 *
 * Literal segments are declared BEFORE wildcard routes that could also match
 * them — '/announcements' must come before '/announcements/{slug}'.
 *
 * Every protected route names its middleware here. That is the backend
 * authorization docs/00 requires; the sidebar only mirrors it.
 *
 * @var App\Core\Router $router
 */

declare(strict_types=1);

use App\Controllers\Admin\DashboardController as AdminDashboard;
use App\Controllers\AuthController;
use App\Controllers\Cashier\DashboardController as CashierDashboard;
use App\Controllers\PublicController;
use App\Controllers\Registrar\DashboardController as RegistrarDashboard;
use App\Controllers\Student\DashboardController as StudentDashboard;
use App\Controllers\Teacher\DashboardController as TeacherDashboard;
use App\Models\User;

// ---------------------------------------------------------------------------
// Public website (docs/06)
// ---------------------------------------------------------------------------
$router->get('/',              [PublicController::class, 'home'],          [], 'home');
$router->get('/about',         [PublicController::class, 'about'],         [], 'about');
$router->get('/programs',      [PublicController::class, 'programs'],      [], 'programs');
$router->get('/enrollment',    [PublicController::class, 'enrollment'],    [], 'enrollment');
$router->get('/requirements',  [PublicController::class, 'requirements'],  [], 'requirements');
$router->get('/announcements', [PublicController::class, 'announcements'], [], 'announcements');
$router->get('/announcements/{slug}', [PublicController::class, 'announcement'], [], 'announcement');
$router->get('/downloads',     [PublicController::class, 'downloads'],     [], 'downloads');
$router->get('/contact',       [PublicController::class, 'contact'],       [], 'contact');

// ---------------------------------------------------------------------------
// Authentication (docs/07)
// ---------------------------------------------------------------------------
$router->get('/login',  [AuthController::class, 'showLogin'], ['guest'], 'login');
$router->post('/login', [AuthController::class, 'login'],     ['guest']);
$router->post('/logout', [AuthController::class, 'logout'],   ['auth'], 'logout');

$router->get('/password/change',  [AuthController::class, 'showChangePassword'], ['auth'], 'password.change');
$router->post('/password/change', [AuthController::class, 'changePassword'],     ['auth']);

// ---------------------------------------------------------------------------
// Role dashboards
//
// Each is gated by its own role. A teacher requesting /cashier/dashboard gets a
// 403 from RequireRole — not a redirect, and not a hidden menu item (docs/22).
// ---------------------------------------------------------------------------
$router->get('/student/dashboard',   [StudentDashboard::class, 'index'],   ['auth', 'role:' . User::ROLE_STUDENT],     'student.dashboard');
$router->get('/teacher/dashboard',   [TeacherDashboard::class, 'index'],   ['auth', 'role:' . User::ROLE_TEACHER],     'teacher.dashboard');
$router->get('/registrar/dashboard', [RegistrarDashboard::class, 'index'], ['auth', 'role:' . User::ROLE_REGISTRAR],   'registrar.dashboard');
$router->get('/cashier/dashboard',   [CashierDashboard::class, 'index'],   ['auth', 'role:' . User::ROLE_CASHIER],     'cashier.dashboard');
$router->get('/admin/dashboard',     [AdminDashboard::class, 'index'],     ['auth', 'role:' . User::ROLE_SUPER_ADMIN], 'admin.dashboard');

// Published document streaming. Declared after the literal '/downloads' route
// so the listing page is not swallowed by the wildcard.
$router->get('/downloads/{id}', [PublicController::class, 'download'], [], 'downloads.file');
