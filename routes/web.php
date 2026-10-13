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

use App\Controllers\Admin\AuditLogController as AdminAudit;
use App\Controllers\Admin\BackupController as AdminBackups;
use App\Controllers\Admin\DashboardController as AdminDashboard;
use App\Controllers\Admin\HealthController as AdminHealth;
use App\Controllers\Admin\ReportController as AdminReports;
use App\Controllers\Admin\RoleController as AdminRoles;
use App\Controllers\Admin\SettingController as AdminSettings;
use App\Controllers\Admin\UserController as AdminUsers;
use App\Controllers\PasswordResetController;
use App\Controllers\AccountController;
use App\Controllers\AuthController;
use App\Controllers\Cashier\AccountController as CashierAccounts;
use App\Controllers\Cashier\DashboardController as CashierDashboard;
use App\Controllers\Cashier\FeeController as CashierFees;
use App\Controllers\Cashier\PaymentController as CashierPayments;
use App\Controllers\Cashier\WalkInPaymentController as CashierWalkInPayments;
use App\Controllers\Cashier\ReportController as CashierReports;
use App\Controllers\Cashier\DisbursementController as CashierDisbursements;
use App\Controllers\FinanceDocumentController;
use App\Controllers\Manage\AnnouncementController as ManageAnnouncements;
use App\Controllers\Portal\AnnouncementController as PortalAnnouncements;
use App\Controllers\Portal\NotificationController as PortalNotifications;
use App\Controllers\Student\AccountController as StudentAccount;
use App\Controllers\PublicController;
use App\Controllers\CalendarController;
use App\Controllers\Manage\CalendarController as ManageCalendar;
use App\Controllers\Registrar\DashboardController as RegistrarDashboard;
use App\Controllers\DocumentController;
use App\Controllers\RegisterController;
use App\Controllers\Registrar\AcademicCalendarController;
use App\Controllers\Registrar\CredentialController as RegistrarCredentials;
use App\Controllers\Registrar\ApplicationController as RegistrarApplications;
use App\Controllers\Registrar\CatalogController;
use App\Controllers\Registrar\ClassController as RegistrarClasses;
use App\Controllers\Registrar\ConfirmationController;
use App\Controllers\Registrar\GradingController;
use App\Controllers\Registrar\ReportController as RegistrarReports;
use App\Controllers\Registrar\RoomController;
use App\Controllers\Registrar\StudentController as RegistrarStudents;
use App\Controllers\Registrar\WalkInController;
use App\Controllers\Student\AcademicController as StudentAcademic;
use App\Controllers\Student\CredentialController as StudentCredentials;
use App\Controllers\Student\DashboardController as StudentDashboard;
use App\Controllers\Student\DocumentsController as StudentDocuments;
use App\Controllers\Student\EnrollmentController;
use App\Controllers\Student\ProfileController;
use App\Controllers\Student\RequirementController;
use App\Controllers\Teacher\AnnouncementController as TeacherAnnouncements;
use App\Controllers\Teacher\ClassController as TeacherClasses;
use App\Controllers\Teacher\DashboardController as TeacherDashboard;
use App\Controllers\Teacher\ReportController as TeacherReports;
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
$router->get('/calendar',      [CalendarController::class, 'show'],        [], 'calendar');

// ---------------------------------------------------------------------------
// Authentication (docs/07)
// ---------------------------------------------------------------------------
$router->get('/login',  [AuthController::class, 'showLogin'], ['guest'], 'login');
$router->post('/login', [AuthController::class, 'login'],     ['guest']);
$router->post('/logout', [AuthController::class, 'logout'],   ['auth'], 'logout');

$router->get('/password/change',  [AuthController::class, 'showChangePassword'], ['auth'], 'password.change');
$router->post('/password/change', [AuthController::class, 'changePassword'],     ['auth']);

// My Account: profile photo and password, for every signed-in role.
$router->get('/portal/calendar', [CalendarController::class, 'portal'],  ['auth'], 'portal.calendar');
$router->get('/account',        [AccountController::class, 'show'],      ['auth'], 'account');
$router->post('/account/photo', [AccountController::class, 'savePhoto'], ['auth']);
$router->get('/account/photo',  [AccountController::class, 'photo'],     ['auth'], 'account.photo');

// "Forgot password?" by email, and one-time password links (docs/07). Signed-out visitors only.
$router->get('/password/forgot',  [PasswordResetController::class, 'showForgot'], ['guest'], 'password.forgot');
$router->post('/password/forgot', [PasswordResetController::class, 'sendForgot'], ['guest']);
$router->get('/password/reset/{token}',  [PasswordResetController::class, 'show'],   ['guest'], 'password.reset');
$router->post('/password/reset/{token}', [PasswordResetController::class, 'update'], ['guest']);

// The school logo set in System Settings (the built-in seal when none is uploaded).
$router->get('/branding/logo', [AdminSettings::class, 'logo'], [], 'branding.logo');

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
// Administration (Phase 7)
//
// The Super Admin role AND the permission for the area. The audit log has no
// route that changes or removes an entry (docs/19); restores run only from the
// command line (database/restore.php).
// ---------------------------------------------------------------------------
$admin = static fn (string $permission): array => ['auth', 'role:' . User::ROLE_SUPER_ADMIN, 'can:' . $permission];

$router->get('/admin/users',                  [AdminUsers::class, 'index'],     $admin('users.manage'), 'admin.users');
$router->get('/admin/users/create',           [AdminUsers::class, 'create'],    $admin('users.manage'));
$router->post('/admin/users/create',          [AdminUsers::class, 'store'],     $admin('users.manage'));
$router->get('/admin/users/{id}',             [AdminUsers::class, 'show'],      $admin('users.manage'));
$router->get('/admin/users/{id}/edit',        [AdminUsers::class, 'edit'],      $admin('users.manage'));
$router->post('/admin/users/{id}/edit',       [AdminUsers::class, 'update'],    $admin('users.manage'));
$router->post('/admin/users/{id}/disable',    [AdminUsers::class, 'disable'],   $admin('users.manage'));
$router->post('/admin/users/{id}/enable',     [AdminUsers::class, 'enable'],    $admin('users.manage'));
$router->post('/admin/users/{id}/unlock',     [AdminUsers::class, 'unlock'],    $admin('users.manage'));
$router->post('/admin/users/{id}/reset-link', [AdminUsers::class, 'resetLink'], $admin('users.manage'));
$router->post('/admin/users/{id}/email-link', [AdminUsers::class, 'emailLink'], $admin('users.manage'));

$router->get('/admin/roles',                   [AdminRoles::class, 'index'],  $admin('roles.manage'), 'admin.roles');
$router->post('/admin/roles/{id}/permissions', [AdminRoles::class, 'update'], $admin('roles.manage'));

$router->get('/admin/settings',          [AdminSettings::class, 'index'],  $admin('settings.manage'), 'admin.settings');
$router->post('/admin/settings/{group}', [AdminSettings::class, 'update'], $admin('settings.manage'));

$router->get('/admin/audit-logs',        [AdminAudit::class, 'index'],  $admin('audit.view'), 'admin.audit');
$router->get('/admin/audit-logs/export', [AdminAudit::class, 'export'], $admin('audit.view'));
$router->get('/admin/audit-logs/{id}',   [AdminAudit::class, 'show'],   $admin('audit.view'));
$router->get('/admin/login-activity',    [AdminAudit::class, 'logins'], $admin('audit.view'), 'admin.logins');

$router->get('/admin/backups',                [AdminBackups::class, 'index'],    $admin('backups.manage'), 'admin.backups');
$router->post('/admin/backups/create',        [AdminBackups::class, 'store'],    $admin('backups.manage'));
$router->post('/admin/backups/{id}/verify',   [AdminBackups::class, 'verify'],   $admin('backups.manage'));
$router->post('/admin/backups/{id}/download', [AdminBackups::class, 'download'], $admin('backups.manage'));
$router->post('/admin/backups/{id}/delete',   [AdminBackups::class, 'destroy'],  $admin('backups.manage'));

$router->get('/admin/health', [AdminHealth::class, 'index'], $admin('system.health'), 'admin.health');

// Administrative reports (docs/04): on screen, printable, and an audited CSV export.
$router->get('/admin/reports',                 [AdminReports::class, 'index'],          $admin('reports.admin'), 'admin.reports');
$router->get('/admin/reports/accounts',        [AdminReports::class, 'accounts'],       $admin('reports.admin'));
$router->get('/admin/reports/accounts/print',  [AdminReports::class, 'accountsPrint'],  $admin('reports.admin'));
$router->get('/admin/reports/accounts/export', [AdminReports::class, 'accountsExport'], $admin('reports.admin'));
$router->get('/admin/reports/sign-ins',        [AdminReports::class, 'signIns'],        $admin('reports.admin'));
$router->get('/admin/reports/sign-ins/print',  [AdminReports::class, 'signInsPrint'],   $admin('reports.admin'));
$router->get('/admin/reports/sign-ins/export', [AdminReports::class, 'signInsExport'],  $admin('reports.admin'));
$router->get('/admin/reports/activity',        [AdminReports::class, 'activity'],       $admin('reports.admin'));
$router->get('/admin/reports/activity/print',  [AdminReports::class, 'activityPrint'],  $admin('reports.admin'));
$router->get('/admin/reports/activity/export', [AdminReports::class, 'activityExport'], $admin('reports.admin'));
$router->get('/admin/reports/usage',           [AdminReports::class, 'usage'],          $admin('reports.admin'));
$router->get('/admin/reports/usage/print',     [AdminReports::class, 'usagePrint'],     $admin('reports.admin'));
$router->get('/admin/reports/usage/export',    [AdminReports::class, 'usageExport'],    $admin('reports.admin'));

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
$router->get('/student/photo',    [ProfileController::class, 'photo'],  $student, 'student.photo');

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
$router->get('/student/prospectus',  [StudentAcademic::class, 'prospectus'], [...$student, 'can:grades.view'],     'student.prospectus');
$router->get('/student/schedule/print', [FinanceDocumentController::class, 'schedule'], [...$student, 'can:schedule.view'], 'student.schedule.print');
$router->get('/student/cor', [FinanceDocumentController::class, 'cor'], [...$student, 'can:enrollment.view'], 'student.cor');

$router->get('/student/credentials',  [StudentCredentials::class, 'index'],  [...$student, 'can:credentials.request'], 'student.credentials');
$router->post('/student/credentials', [StudentCredentials::class, 'store'],  [...$student, 'can:credentials.request']);
$router->post('/student/credentials/{id}/cancel', [StudentCredentials::class, 'cancel'], [...$student, 'can:credentials.request']);

// My Documents: uploads, receipts, statements, schedules, and proofs, each previewable.
$router->get('/student/documents', [StudentDocuments::class, 'index'], $student, 'student.documents');

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

// Payments received in person at the window (docs/14).
$router->get('/cashier/walk-in-payments',        [CashierWalkInPayments::class, 'index'], $cashier('payments.record'), 'cashier.walkin-payments');
$router->post('/cashier/walk-in-payments/{id}',  [CashierWalkInPayments::class, 'store'], $cashier('payments.record'));

$router->get('/cashier/accounts',                [CashierAccounts::class, 'index'],         $cashier('finance.view_account'), 'cashier.accounts');
$router->get('/cashier/accounts/{id}',           [CashierAccounts::class, 'show'],          $cashier('finance.view_account'));
$router->post('/cashier/accounts/{id}/payments', [CashierAccounts::class, 'recordPayment'], $cashier('payments.record'));
$router->post('/cashier/accounts/{id}/payments/{payment}/reverse', [CashierAccounts::class, 'reverse'], $cashier('payments.record'));
$router->post('/cashier/accounts/{id}/adjustments', [CashierAccounts::class, 'adjust'], $cashier('fees.manage'));

$router->get('/cashier/reports',                     [CashierReports::class, 'index'],       $cashier('reports.cashier'), 'cashier.reports');
$router->get('/cashier/reports/collections',         [CashierReports::class, 'collections'], $cashier('reports.cashier'));
$router->get('/cashier/reports/collections/print',   [CashierReports::class, 'collectionsPrint'], $cashier('reports.cashier'));
$router->get('/cashier/reports/collections/export',  [CashierReports::class, 'collectionsExport'], $cashier('reports.cashier'));
$router->get('/cashier/reports/outstanding',         [CashierReports::class, 'outstanding'], $cashier('reports.cashier'));
$router->get('/cashier/reports/outstanding/print',   [CashierReports::class, 'outstandingPrint'], $cashier('reports.cashier'));
$router->get('/cashier/reports/outstanding/export',  [CashierReports::class, 'outstandingExport'], $cashier('reports.cashier'));
$router->get('/cashier/reports/receipts-journal',        [CashierReports::class, 'receiptsJournal'],      $cashier('reports.cashier'), 'cashier.crj');
$router->get('/cashier/reports/receipts-journal/print',  [CashierReports::class, 'receiptsJournalPrint'], $cashier('reports.cashier'));
$router->get('/cashier/reports/receipts-journal/export', [CashierReports::class, 'receiptsJournalExport'], $cashier('reports.cashier'));
$router->get('/cashier/reports/disbursements-journal',        [CashierReports::class, 'disbursementsJournal'],      $cashier('reports.cashier'), 'cashier.cdj');
$router->get('/cashier/reports/disbursements-journal/print',  [CashierReports::class, 'disbursementsJournalPrint'], $cashier('reports.cashier'));
$router->get('/cashier/reports/disbursements-journal/export', [CashierReports::class, 'disbursementsJournalExport'], $cashier('reports.cashier'));

// Cash disbursements: the cashier records money paid out on vouchers (docs/14).
$router->get('/cashier/disbursements',               [CashierDisbursements::class, 'index'],   $cashier('disbursements.manage'), 'cashier.disbursements');
$router->get('/cashier/disbursements/create',        [CashierDisbursements::class, 'create'],  $cashier('disbursements.manage'));
$router->post('/cashier/disbursements',              [CashierDisbursements::class, 'store'],   $cashier('disbursements.manage'));
$router->get('/cashier/disbursements/{id}/voucher',  [CashierDisbursements::class, 'voucher'], $cashier('disbursements.manage'));

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
// Communication (Phase 6)
//
// Reading: any signed-in role with the permission, and only what its audience
// includes (filtered in the query). Writing: any role holding announcements.manage
// (Registrar, Super Admin); publishing and archiving need announcements.publish.
// ---------------------------------------------------------------------------
$router->get('/portal/announcements',               [PortalAnnouncements::class, 'index'],   ['auth', 'can:announcements.view'], 'portal.announcements');
$router->get('/portal/announcements/{id}',          [PortalAnnouncements::class, 'show'],    ['auth', 'can:announcements.view']);
$router->post('/portal/announcements/{id}/dismiss', [PortalAnnouncements::class, 'dismiss'], ['auth', 'can:announcements.view']);
// Public announcements' images are open to visitors; the controller checks each one.
$router->get('/announcement-images/{id}',           [PortalAnnouncements::class, 'image'],   [], 'announcement-images.show');

$router->get('/portal/notifications',                [PortalNotifications::class, 'index'],       ['auth', 'can:notifications.view'], 'portal.notifications');
$router->post('/portal/notifications/read-all',      [PortalNotifications::class, 'markAllRead'], ['auth', 'can:notifications.view']);
$router->post('/portal/notifications/{id}/open',     [PortalNotifications::class, 'open'],        ['auth', 'can:notifications.view']);
$router->post('/portal/notifications/{id}/archive',  [PortalNotifications::class, 'archive'],     ['auth', 'can:notifications.view']);
$router->post('/portal/notifications/{id}/restore',  [PortalNotifications::class, 'restore'],     ['auth', 'can:notifications.view']);

$router->get('/manage/announcements',              [ManageAnnouncements::class, 'index'],   ['auth', 'can:announcements.manage'], 'manage.announcements');
$router->get('/manage/announcements/create',       [ManageAnnouncements::class, 'create'],  ['auth', 'can:announcements.manage']);
$router->post('/manage/announcements/create',      [ManageAnnouncements::class, 'store'],   ['auth', 'can:announcements.manage']);
$router->get('/manage/announcements/{id}/edit',    [ManageAnnouncements::class, 'edit'],    ['auth', 'can:announcements.manage']);
$router->post('/manage/announcements/{id}/edit',   [ManageAnnouncements::class, 'update'],  ['auth', 'can:announcements.manage']);
$router->post('/manage/announcements/{id}/archive', [ManageAnnouncements::class, 'archive'], ['auth', 'can:announcements.publish']);
$router->post('/manage/announcements/{id}/restore', [ManageAnnouncements::class, 'restore'], ['auth', 'can:announcements.manage']);
$router->post('/manage/announcements/{id}/delete', [ManageAnnouncements::class, 'destroy'], ['auth', 'can:announcements.manage']);

// School academic calendar: maintained by the registrar and the Super Admin (docs/09).
$router->get('/manage/calendar',             [ManageCalendar::class, 'index'],   ['auth', 'can:calendar.manage'], 'manage.calendar');
$router->post('/manage/calendar',            [ManageCalendar::class, 'store'],   ['auth', 'can:calendar.manage']);
$router->post('/manage/calendar/{id}',       [ManageCalendar::class, 'update'],  ['auth', 'can:calendar.manage']);
$router->post('/manage/calendar/{id}/delete', [ManageCalendar::class, 'destroy'], ['auth', 'can:calendar.manage']);

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

// Announcements to the students of the teacher's own classes (docs/16).
$router->get('/teacher/announcements',               [TeacherAnnouncements::class, 'index'],   $teacher('announcements.class'), 'teacher.announcements');
$router->get('/teacher/announcements/create',        [TeacherAnnouncements::class, 'create'],  $teacher('announcements.class'));
$router->post('/teacher/announcements/create',       [TeacherAnnouncements::class, 'store'],   $teacher('announcements.class'));
$router->get('/teacher/announcements/{id}/edit',     [TeacherAnnouncements::class, 'edit'],    $teacher('announcements.class'));
$router->post('/teacher/announcements/{id}/edit',    [TeacherAnnouncements::class, 'update'],  $teacher('announcements.class'));
$router->post('/teacher/announcements/{id}/archive', [TeacherAnnouncements::class, 'archive'], $teacher('announcements.class'));
$router->post('/teacher/announcements/{id}/restore', [TeacherAnnouncements::class, 'restore'], $teacher('announcements.class'));
$router->post('/teacher/announcements/{id}/delete',  [TeacherAnnouncements::class, 'destroy'], $teacher('announcements.class'));

// Class reports (docs/15): printable, and an audited CSV export. Own classes only.
$router->get('/teacher/reports',                              [TeacherReports::class, 'index'],            $teacher('reports.teacher'), 'teacher.reports');
$router->get('/teacher/classes/{id}/reports/class-list',        [TeacherReports::class, 'classList'],        $teacher('reports.teacher'));
$router->get('/teacher/classes/{id}/reports/class-list/export', [TeacherReports::class, 'classListExport'],  $teacher('reports.teacher'));
$router->get('/teacher/classes/{id}/reports/attendance',        [TeacherReports::class, 'attendance'],       $teacher('reports.teacher'));
$router->get('/teacher/classes/{id}/reports/attendance/export', [TeacherReports::class, 'attendanceExport'], $teacher('reports.teacher'));
$router->get('/teacher/classes/{id}/reports/grades',            [TeacherReports::class, 'grades'],           $teacher('reports.teacher'));
$router->get('/teacher/classes/{id}/reports/grades/export',     [TeacherReports::class, 'gradesExport'],     $teacher('reports.teacher'));

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

// Walk-in enrollment at the counter (docs/09).
$router->get('/registrar/walk-in',                  [WalkInController::class, 'create'],        $registrar('enrollment.walkin'), 'registrar.walkin');
$router->post('/registrar/walk-in',                 [WalkInController::class, 'store'],         $registrar('enrollment.walkin'));
$router->get('/registrar/walk-in/{id}',             [WalkInController::class, 'documents'],     $registrar('enrollment.walkin'));
$router->post('/registrar/walk-in/{id}',            [WalkInController::class, 'saveDocuments'], $registrar('enrollment.walkin'));
$router->post('/registrar/walk-in/{id}/link',       [WalkInController::class, 'reissueLink'],   $registrar('enrollment.walkin'));
$router->post('/registrar/walk-in/{id}/email-link', [WalkInController::class, 'emailLink'],     $registrar('enrollment.walkin'));
$router->post('/registrar/walk-in/{id}/cancel',     [WalkInController::class, 'cancel'],        $registrar('enrollment.walkin'));

$router->get('/registrar/students',       [RegistrarStudents::class, 'index'],  $registrar('students.view'), 'registrar.students');
$router->get('/registrar/students/{id}',  [RegistrarStudents::class, 'show'],   $registrar('students.view'), 'registrar.students.show');
$router->get('/registrar/students/{id}/photo', [RegistrarStudents::class, 'photo'], $registrar('students.view'), 'registrar.students.photo');
$router->get('/registrar/students/{id}/cor',   [RegistrarStudents::class, 'cor'],   $registrar('students.view'), 'registrar.students.cor');
$router->get('/registrar/students/{id}/prospectus', [RegistrarStudents::class, 'prospectus'], $registrar('students.view'), 'registrar.students.prospectus');
$router->post('/registrar/students/{id}', [RegistrarStudents::class, 'update'], $registrar('students.manage'));
$router->post('/registrar/students/{id}/enlistments', [RegistrarStudents::class, 'addClass'],  $registrar('academic.assign'));
$router->post('/registrar/students/{id}/credits', [RegistrarStudents::class, 'creditSubject'], $registrar('academic.credit'));
$router->post('/registrar/students/{id}/credits/{creditId}/delete', [RegistrarStudents::class, 'removeCredit'], $registrar('academic.credit'));
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

$router->get('/registrar/credentials',        [RegistrarCredentials::class, 'index'],   $registrar('credentials.manage'), 'registrar.credentials');
$router->post('/registrar/credentials/{id}',  [RegistrarCredentials::class, 'process'], $registrar('credentials.manage'));

$router->get('/registrar/calendar',              [AcademicCalendarController::class, 'index'],    $registrar('academic.manage'), 'registrar.calendar');
$router->post('/registrar/calendar/terms/{id}',  [AcademicCalendarController::class, 'saveTerm'],  $registrar('academic.manage'));
$router->post('/registrar/calendar/years',       [AcademicCalendarController::class, 'addYear'],   $registrar('academic.manage'));

$router->get('/registrar/grading',                     [GradingController::class, 'index'],   $registrar('grade_periods.manage'), 'registrar.grading');
$router->post('/registrar/grading/periods/{id}/open',  [GradingController::class, 'open'],    $registrar('grade_periods.manage'));
$router->post('/registrar/grading/periods/{id}/close', [GradingController::class, 'close'],   $registrar('grade_periods.manage'));
$router->post('/registrar/grading/terms/{id}/weights', [GradingController::class, 'weights'], $registrar('grade_periods.manage'));

// Reports (docs/15): on screen, printable, and an audited CSV export.
$router->get('/registrar/reports',                     [RegistrarReports::class, 'index'],              $registrar('reports.registrar'), 'registrar.reports');
$router->get('/registrar/reports/enrollment',          [RegistrarReports::class, 'enrollment'],         $registrar('reports.registrar'));
$router->get('/registrar/reports/enrollment/print',    [RegistrarReports::class, 'enrollmentPrint'],    $registrar('reports.registrar'));
$router->get('/registrar/reports/enrollment/export',   [RegistrarReports::class, 'enrollmentExport'],   $registrar('reports.registrar'));
$router->get('/registrar/reports/applications',        [RegistrarReports::class, 'applications'],       $registrar('reports.registrar'));
$router->get('/registrar/reports/applications/print',  [RegistrarReports::class, 'applicationsPrint'],  $registrar('reports.registrar'));
$router->get('/registrar/reports/applications/export', [RegistrarReports::class, 'applicationsExport'], $registrar('reports.registrar'));
$router->get('/registrar/reports/requirements',        [RegistrarReports::class, 'requirements'],       $registrar('reports.registrar'));
$router->get('/registrar/reports/requirements/print',  [RegistrarReports::class, 'requirementsPrint'],  $registrar('reports.registrar'));
$router->get('/registrar/reports/requirements/export', [RegistrarReports::class, 'requirementsExport'], $registrar('reports.registrar'));
$router->get('/registrar/reports/history',             [RegistrarReports::class, 'history'],            $registrar('reports.registrar'));
$router->get('/registrar/reports/history/print',       [RegistrarReports::class, 'historyPrint'],       $registrar('reports.registrar'));
$router->get('/registrar/reports/history/export',      [RegistrarReports::class, 'historyExport'],      $registrar('reports.registrar'));

// Uploaded requirement documents. Signed-in users only; who may open which
// file is decided by DocumentAccessPolicy (docs/17 section 10).
$router->get('/documents/{fileId}', [DocumentController::class, 'show'], ['auth'], 'documents.show');

// Published document streaming. Declared after the literal '/downloads' route
// so the listing page is not swallowed by the wildcard.
$router->get('/downloads/{id}', [PublicController::class, 'download'], [], 'downloads.file');
