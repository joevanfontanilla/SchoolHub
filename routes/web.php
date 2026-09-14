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
use App\Controllers\Cashier\AccountController as CashierAccounts;
use App\Controllers\Cashier\DashboardController as CashierDashboard;
use App\Controllers\Cashier\FeeController as CashierFees;
use App\Controllers\Cashier\PaymentController as CashierPayments;
use App\Controllers\FinanceDocumentController;
use App\Controllers\Student\AccountController as StudentAccount;
use App\Controllers\PublicController;
use App\Controllers\Registrar\DashboardController as RegistrarDashboard;
use App\Controllers\DocumentController;
use App\Controllers\RegisterController;
use App\Controllers\Registrar\ApplicationController as RegistrarApplications;
use App\Controllers\Registrar\CatalogController;
use App\Controllers\Registrar\ClassController as RegistrarClasses;
use App\Controllers\Registrar\ConfirmationController;
use App\Controllers\Registrar\GradingController;
use App\Controllers\Registrar\RoomController;
use App\Controllers\Registrar\StudentController as RegistrarStudents;
use App\Controllers\Student\AcademicController as StudentAcademic;
use App\Controllers\Student\DashboardController as StudentDashboard;
use App\Controllers\Student\EnrollmentController;
use App\Controllers\Student\ProfileController;
use App\Controllers\Student\RequirementController;
use App\Controllers\Teacher\ClassController as TeacherClasses;
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

// Student self-registration (docs/09 step 1).
$router->get('/register',  [RegisterController::class, 'show'],  ['guest'], 'register');
$router->post('/register', [RegisterController::class, 'store'], ['guest']);

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

// ---------------------------------------------------------------------------
// Student: profile, enrollment application, requirements, status (Phase 2)
//
// Role-gated here, and ownership-scoped again in the services: an application
// id that belongs to another student is a 404.
// Literal segments ('apply') are declared before the {id} wildcard.
// ---------------------------------------------------------------------------
$student = ['auth', 'role:' . User::ROLE_STUDENT];

$router->get('/student/profile',  [ProfileController::class, 'show'],   $student, 'student.profile');
$router->post('/student/profile', [ProfileController::class, 'update'], $student);

$router->get('/student/enrollment',        [EnrollmentController::class, 'index'],  $student, 'student.enrollment');
$router->get('/student/enrollment/apply',  [EnrollmentController::class, 'create'], $student, 'student.enrollment.apply');
$router->post('/student/enrollment/apply', [EnrollmentController::class, 'store'],  $student);
$router->get('/student/enrollment/{id}',   [EnrollmentController::class, 'show'],   $student, 'student.enrollment.show');
$router->post('/student/enrollment/{id}/update', [EnrollmentController::class, 'update'], $student);
$router->post('/student/enrollment/{id}/submit', [EnrollmentController::class, 'submit'], $student);
$router->post('/student/enrollment/{id}/cancel', [EnrollmentController::class, 'cancel'], $student);
$router->post('/student/enrollment/{id}/subjects', [EnrollmentController::class, 'saveSubjects'], [...$student, 'can:enrollment.apply']);
$router->post('/student/enrollment/{id}/requirements/{requirementId}/upload', [RequirementController::class, 'upload'], $student);

$router->get('/student/requirements', [EnrollmentController::class, 'requirements'], $student, 'student.requirements');
$router->get('/student/status',       [EnrollmentController::class, 'status'],       $student, 'student.status');

// Classes, grades, attendance, history (Phase 4). Always the signed-in student's own.
$router->get('/student/schedule',   [StudentAcademic::class, 'schedule'],   [...$student, 'can:schedule.view'],   'student.schedule');
$router->get('/student/grades',     [StudentAcademic::class, 'grades'],     [...$student, 'can:grades.view'],     'student.grades');
$router->get('/student/attendance', [StudentAcademic::class, 'attendance'], [...$student, 'can:attendance.view'], 'student.attendance');
$router->get('/student/history',    [StudentAcademic::class, 'history'],    [...$student, 'can:grades.view'],     'student.history');
$router->get('/student/schedule/print', [FinanceDocumentController::class, 'schedule'], [...$student, 'can:schedule.view'], 'student.schedule.print');

// Payments & Accounts (Phase 5): the student's own assessments, online payments, receipts.
$router->get('/student/account',                [StudentAccount::class, 'index'],         [...$student, 'can:finance.view_account'], 'student.account');
$router->post('/student/account/{id}/payments', [StudentAccount::class, 'submitPayment'], [...$student, 'can:finance.view_account']);

// ---------------------------------------------------------------------------
// Cashier (Phase 5)
//
// The Cashier role AND the permission for the action.
// ---------------------------------------------------------------------------
$cashier = static fn (string $permission): array => ['auth', 'role:' . User::ROLE_CASHIER, 'can:' . $permission];

$router->get('/cashier/payments',               [CashierPayments::class, 'index'],   $cashier('payments.record'), 'cashier.payments');
$router->get('/cashier/payments/{id}',          [CashierPayments::class, 'show'],    $cashier('payments.record'));
$router->post('/cashier/payments/{id}/approve', [CashierPayments::class, 'approve'], $cashier('payments.record'));
$router->post('/cashier/payments/{id}/reject',  [CashierPayments::class, 'reject'],  $cashier('payments.record'));

$router->get('/cashier/accounts',                [CashierAccounts::class, 'index'],         $cashier('finance.view_account'), 'cashier.accounts');
$router->get('/cashier/accounts/{id}',           [CashierAccounts::class, 'show'],          $cashier('finance.view_account'));
$router->post('/cashier/accounts/{id}/payments', [CashierAccounts::class, 'recordPayment'], $cashier('payments.record'));

$router->get('/cashier/fees',            [CashierFees::class, 'index'],  $cashier('fees.manage'), 'cashier.fees');
$router->get('/cashier/fees/create',     [CashierFees::class, 'create'], $cashier('fees.manage'));
$router->post('/cashier/fees/create',    [CashierFees::class, 'save'],   $cashier('fees.manage'));
$router->get('/cashier/fees/{id}/edit',  [CashierFees::class, 'edit'],   $cashier('fees.manage'));
$router->post('/cashier/fees/{id}/edit', [CashierFees::class, 'save'],   $cashier('fees.manage'));

// Receipts, statements of account, and payment proofs: signed-in users only; who may
// open which is decided in FinanceDocumentController (owner student, cashier, registrar).
$router->get('/receipts/{id}',       [FinanceDocumentController::class, 'receipt'],   ['auth'], 'receipts.show');
$router->get('/statements/{id}',     [FinanceDocumentController::class, 'statement'], ['auth'], 'statements.show');
$router->get('/payment-proofs/{id}', [FinanceDocumentController::class, 'proof'],     ['auth'], 'payment-proofs.show');

// ---------------------------------------------------------------------------
// Teacher (Phase 4)
//
// The Teacher role AND the permission for the action. Class ownership is then
// checked in GradingService::ownedClass(): another teacher's class is a 404.
// ---------------------------------------------------------------------------
$teacher = static fn (string $permission): array => ['auth', 'role:' . User::ROLE_TEACHER, 'can:' . $permission];

$router->get('/teacher/classes',                   [TeacherClasses::class, 'index'],           $teacher('classes.view'),      'teacher.classes');
$router->get('/teacher/attendance',                [TeacherClasses::class, 'attendanceIndex'], $teacher('attendance.record'), 'teacher.attendance');
$router->get('/teacher/grades',                    [TeacherClasses::class, 'gradesIndex'],     $teacher('grades.record'),     'teacher.grades');
$router->get('/teacher/schedule',                  [TeacherClasses::class, 'schedule'],        $teacher('schedule.view'),     'teacher.schedule');
$router->get('/teacher/classes/{id}',              [TeacherClasses::class, 'show'],            $teacher('classes.view'));
$router->get('/teacher/classes/{id}/attendance',   [TeacherClasses::class, 'attendance'],      $teacher('attendance.record'));
$router->post('/teacher/classes/{id}/attendance',  [TeacherClasses::class, 'saveAttendance'],  $teacher('attendance.record'));
$router->get('/teacher/classes/{id}/grades',       [TeacherClasses::class, 'grades'],          $teacher('grades.record'));
$router->post('/teacher/classes/{id}/grades',      [TeacherClasses::class, 'saveGrades'],      $teacher('grades.record'));

// ---------------------------------------------------------------------------
// Registrar (Phase 3)
//
// Every route: the Registrar role AND the specific permission for the action.
// Super Admin is deliberately not admitted here (docs/04, docs/26 section 7).
// ---------------------------------------------------------------------------
$registrar = static fn (string $permission): array => ['auth', 'role:' . User::ROLE_REGISTRAR, 'can:' . $permission];

$router->get('/registrar/applications',      [RegistrarApplications::class, 'index'], $registrar('enrollment.review'), 'registrar.applications');
$router->get('/registrar/applications/{id}', [RegistrarApplications::class, 'show'],  $registrar('enrollment.review'), 'registrar.applications.show');
$router->get('/registrar/applications/{id}/confirm',  [ConfirmationController::class, 'show'],  $registrar('enrollment.confirm'));
$router->post('/registrar/applications/{id}/confirm', [ConfirmationController::class, 'store'], $registrar('enrollment.confirm'));
$router->post('/registrar/applications/{id}/start-review',       [RegistrarApplications::class, 'startReview'],       $registrar('enrollment.review'));
$router->post('/registrar/applications/{id}/return-incomplete',  [RegistrarApplications::class, 'returnIncomplete'],  $registrar('enrollment.approve'));
$router->post('/registrar/applications/{id}/request-correction', [RegistrarApplications::class, 'requestCorrection'], $registrar('enrollment.approve'));
$router->post('/registrar/applications/{id}/approve',            [RegistrarApplications::class, 'approve'],           $registrar('enrollment.approve'));
$router->post('/registrar/applications/{id}/reject',             [RegistrarApplications::class, 'reject'],            $registrar('enrollment.approve'));
$router->post('/registrar/applications/{id}/cancel',             [RegistrarApplications::class, 'cancel'],            $registrar('enrollment.approve'));
$router->post('/registrar/requirements/{requirementId}/review',  [RegistrarApplications::class, 'reviewRequirement'], $registrar('requirements.verify'));

$router->get('/registrar/students',       [RegistrarStudents::class, 'index'],  $registrar('students.view'), 'registrar.students');
$router->get('/registrar/students/{id}',  [RegistrarStudents::class, 'show'],   $registrar('students.view'), 'registrar.students.show');
$router->post('/registrar/students/{id}', [RegistrarStudents::class, 'update'], $registrar('students.manage'));
$router->post('/registrar/students/{id}/enlistments', [RegistrarStudents::class, 'addClass'],  $registrar('academic.assign'));
$router->post('/registrar/enlistments/{id}/drop',     [RegistrarStudents::class, 'dropClass'], $registrar('academic.assign'));

$router->get('/registrar/programs',            [CatalogController::class, 'programs'],      $registrar('academic.manage'), 'registrar.programs');
$router->get('/registrar/programs/create',     [CatalogController::class, 'createProgram'], $registrar('academic.manage'));
$router->post('/registrar/programs/create',    [CatalogController::class, 'saveProgram'],   $registrar('academic.manage'));
$router->get('/registrar/programs/{id}/edit',  [CatalogController::class, 'editProgram'],   $registrar('academic.manage'));
$router->post('/registrar/programs/{id}/edit', [CatalogController::class, 'saveProgram'],   $registrar('academic.manage'));

$router->get('/registrar/curricula',            [CatalogController::class, 'curricula'],        $registrar('academic.manage'), 'registrar.curricula');
$router->get('/registrar/curricula/create',     [CatalogController::class, 'createCurriculum'], $registrar('academic.manage'));
$router->post('/registrar/curricula/create',    [CatalogController::class, 'saveCurriculum'],   $registrar('academic.manage'));
$router->get('/registrar/curricula/{id}',       [CatalogController::class, 'showCurriculum'],   $registrar('academic.manage'));
$router->get('/registrar/curricula/{id}/edit',  [CatalogController::class, 'editCurriculum'],   $registrar('academic.manage'));
$router->post('/registrar/curricula/{id}/edit', [CatalogController::class, 'saveCurriculum'],   $registrar('academic.manage'));

$router->get('/registrar/subjects',            [CatalogController::class, 'subjects'],      $registrar('academic.manage'), 'registrar.subjects');
$router->get('/registrar/subjects/create',     [CatalogController::class, 'createSubject'], $registrar('academic.manage'));
$router->post('/registrar/subjects/create',    [CatalogController::class, 'saveSubject'],   $registrar('academic.manage'));
$router->get('/registrar/subjects/{id}/edit',  [CatalogController::class, 'editSubject'],   $registrar('academic.manage'));
$router->post('/registrar/subjects/{id}/edit', [CatalogController::class, 'saveSubject'],   $registrar('academic.manage'));

$router->get('/registrar/sections',            [CatalogController::class, 'sections'],      $registrar('academic.manage'), 'registrar.sections');
$router->get('/registrar/sections/create',     [CatalogController::class, 'createSection'], $registrar('academic.manage'));
$router->post('/registrar/sections/create',    [CatalogController::class, 'saveSection'],   $registrar('academic.manage'));
$router->get('/registrar/sections/{id}/edit',  [CatalogController::class, 'editSection'],   $registrar('academic.manage'));
$router->post('/registrar/sections/{id}/edit', [CatalogController::class, 'saveSection'],   $registrar('academic.manage'));

$router->get('/registrar/sections/{id}/classes',  [RegistrarClasses::class, 'section'], $registrar('academic.assign'));
$router->post('/registrar/sections/{id}/classes', [RegistrarClasses::class, 'store'],   $registrar('academic.assign'));
$router->get('/registrar/classes/{id}/edit',      [RegistrarClasses::class, 'edit'],    $registrar('academic.assign'));
$router->post('/registrar/classes/{id}/edit',     [RegistrarClasses::class, 'update'],  $registrar('academic.assign'));
$router->post('/registrar/classes/{id}/close',    [RegistrarClasses::class, 'close'],   $registrar('academic.assign'));

$router->get('/registrar/rooms',            [RoomController::class, 'index'],  $registrar('academic.manage'), 'registrar.rooms');
$router->get('/registrar/rooms/create',     [RoomController::class, 'create'], $registrar('academic.manage'));
$router->post('/registrar/rooms/create',    [RoomController::class, 'save'],   $registrar('academic.manage'));
$router->get('/registrar/rooms/{id}/edit',  [RoomController::class, 'edit'],   $registrar('academic.manage'));
$router->post('/registrar/rooms/{id}/edit', [RoomController::class, 'save'],   $registrar('academic.manage'));

$router->get('/registrar/grading',                     [GradingController::class, 'index'],   $registrar('grade_periods.manage'), 'registrar.grading');
$router->post('/registrar/grading/periods/{id}/open',  [GradingController::class, 'open'],    $registrar('grade_periods.manage'));
$router->post('/registrar/grading/periods/{id}/close', [GradingController::class, 'close'],   $registrar('grade_periods.manage'));
$router->post('/registrar/grading/terms/{id}/weights', [GradingController::class, 'weights'], $registrar('grade_periods.manage'));

// Uploaded requirement documents. Signed-in users only; who may open which
// file is decided by DocumentAccessPolicy (docs/17 section 10).
$router->get('/documents/{fileId}', [DocumentController::class, 'show'], ['auth'], 'documents.show');

// Published document streaming. Declared after the literal '/downloads' route
// so the listing page is not swallowed by the wildcard.
$router->get('/downloads/{id}', [PublicController::class, 'download'], [], 'downloads.file');
