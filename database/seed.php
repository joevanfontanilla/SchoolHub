<?php
/**
 * Reference data seeder — roles, permissions, system settings, and the academic
 * lookups the application cannot run without.
 *
 *   php database/seed.php
 *
 * Idempotent: every insert is an upsert, so re-running is safe. This seeder
 * creates NO user accounts — use database/create_admin.php for that.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('This script may only be run from the command line.');
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Database;
use App\Models\User;

/** key => [module, description] */
const PERMISSIONS = [
    // Student
    'profile.manage'      => ['profile', 'Manage own profile'],
    'enrollment.apply'    => ['enrollment', 'Create and submit an enrollment application'],
    'enrollment.view'     => ['enrollment', 'View enrollment applications'],
    'requirements.upload' => ['requirements', 'Upload requirement documents'],
    'credentials.request' => ['requirements', 'Request official documents from the registrar'],
    'schedule.view'       => ['academic', 'View class schedule'],
    'grades.view'         => ['academic', 'View grades'],
    'attendance.view'     => ['academic', 'View attendance'],
    'payments.view'       => ['finance', 'View payment history and receipts'],
    'finance.view_account' => ['finance', 'View a student financial account'],
    'announcements.view'  => ['communication', 'Read announcements'],
    'notifications.view'  => ['communication', 'Receive notifications'],

    // Teacher
    'classes.view'        => ['academic', 'View assigned classes'],
    'attendance.record'   => ['academic', 'Record attendance for assigned classes'],
    'grades.record'       => ['academic', 'Record grades for assigned classes'],
    'reports.teacher'     => ['reports', 'Generate class reports'],
    'announcements.class' => ['communication', 'Post announcements to own classes'],

    // Registrar
    'students.view'       => ['students', 'View student records'],
    'students.manage'     => ['students', 'Create and update student records'],
    'enrollment.review'   => ['enrollment', 'Review enrollment applications'],
    'enrollment.approve'  => ['enrollment', 'Approve, reject, or request correction'],
    'enrollment.confirm'  => ['enrollment', 'Confirm enrollment and assign sections'],
    'enrollment.walkin'   => ['enrollment', 'Register and enroll walk-in applicants'],
    'requirements.verify' => ['requirements', 'Verify or reject submitted requirements'],
    'academic.manage'     => ['academic', 'Manage programs, subjects, sections, and the academic calendar'],
    'academic.assign'     => ['academic', 'Assign classes, teachers, and rooms'],
    'academic.credit'     => ['academic', 'Credit subjects to a student (transfer credit)'],
    'credentials.manage'  => ['students', 'Process student document requests (TOR, Good Moral, COE)'],
    'grade_periods.manage' => ['academic', 'Open and close grading periods'],
    'calendar.manage'     => ['academic', 'Manage the school academic calendar'],
    'announcements.manage' => ['communication', 'Create and edit announcements'],
    'announcements.publish' => ['communication', 'Publish announcements'],
    'reports.registrar'   => ['reports', 'Generate registrar reports'],

    // Cashier
    'fees.manage'         => ['finance', 'Assess and maintain fees'],
    'payments.record'     => ['finance', 'Record payments'],
    'disbursements.manage' => ['finance', 'Record cash disbursements (vouchers)'],
    'receipts.issue'      => ['finance', 'Issue receipts'],
    'reports.cashier'     => ['reports', 'Generate collection reports'],

    // Super Admin (holds everything implicitly; listed for completeness)
    'users.manage'        => ['administration', 'Manage user accounts'],
    'roles.manage'        => ['administration', 'Manage roles and permissions'],
    'settings.manage'     => ['administration', 'Manage system settings'],
    'audit.view'          => ['administration', 'View audit logs'],
    'backups.manage'      => ['administration', 'Create and restore backups'],
    'system.health'       => ['administration', 'View system health'],
    'reports.admin'       => ['reports', 'Generate administrative reports'],
];

const ROLE_PERMISSIONS = [
    User::ROLE_STUDENT => [
        'profile.manage', 'enrollment.apply', 'enrollment.view', 'requirements.upload',
        'credentials.request', 'schedule.view', 'grades.view', 'attendance.view', 'finance.view_account',
        'payments.view', 'announcements.view', 'notifications.view',
    ],
    User::ROLE_TEACHER => [
        'classes.view', 'attendance.record', 'grades.record', 'schedule.view',
        'reports.teacher', 'announcements.view', 'announcements.class', 'notifications.view',
    ],
    User::ROLE_REGISTRAR => [
        'students.view', 'students.manage', 'enrollment.view', 'enrollment.review',
        'enrollment.approve', 'enrollment.confirm', 'enrollment.walkin', 'requirements.verify',
        'academic.manage', 'academic.assign', 'academic.credit', 'grade_periods.manage',
        'calendar.manage', 'credentials.manage', 'announcements.view', 'announcements.manage', 'announcements.publish',
        'reports.registrar', 'notifications.view',
    ],
    User::ROLE_CASHIER => [
        'finance.view_account', 'fees.manage', 'payments.record', 'payments.view',
        'disbursements.manage', 'receipts.issue', 'reports.cashier', 'announcements.view', 'notifications.view',
    ],
    // Super Admin is intentionally empty — Auth::can() grants it everything.
    User::ROLE_SUPER_ADMIN => [],
];

const ROLES = [
    User::ROLE_STUDENT     => ['student', 'Enrolls, uploads requirements, and views their own records'],
    User::ROLE_TEACHER     => ['teacher', 'Records attendance and grades for assigned classes'],
    User::ROLE_REGISTRAR   => ['registrar', 'Processes enrollment and manages academic records'],
    User::ROLE_CASHIER     => ['cashier', 'Assesses fees, records payments, and issues receipts'],
    User::ROLE_SUPER_ADMIN => ['super-admin', 'Administers users, settings, backups, and audit logs'],
];

const SETTINGS = [
    ['school_name', 'Lyceum of the East Aurora', 'string', 'school', 'Name shown across the site', 1],
    ['school_tagline', 'Discipline • Integrity • Service', 'string', 'school', 'Tagline on the public home page', 1],
    ['school_address', 'Baler, Aurora, Philippines', 'string', 'school', 'Official address', 1],
    ['school_email', 'info@schoolhub.test', 'string', 'school', 'Public contact email', 1],
    ['school_phone', '(042) 000-0000', 'string', 'school', 'Public contact number', 1],
    ['office_hours', 'Monday to Friday, 8:00 AM - 5:00 PM', 'string', 'school', 'Office hours', 1],
    ['enrollment_payment_policy', 'MINIMUM_DOWNPAYMENT', 'string', 'enrollment', 'FULL | MINIMUM_DOWNPAYMENT | NONE (docs/26)', 0],
    ['minimum_downpayment_percent', '20', 'int', 'enrollment', 'Percent of assessment required to confirm enrollment', 0],
    ['max_units_per_term', '27', 'int', 'academic', 'Maximum units a student may enlist in per term', 0],
    ['registration_limit_per_hour', '5', 'int', 'security', 'Student sign-ups allowed from one IP address per hour', 0],
    ['reset_requests_per_hour', '5', 'int', 'security', '"Forgot password?" requests allowed from one IP address per hour', 0],
    ['payment_instructions', "GCash: 0917 000 0000 (Lyceum of the East Aurora)\nBank deposit: Landbank, Account No. 0000-0000-00, Lyceum of the East Aurora\nKeep your reference number and a screenshot or photo of the receipt.", 'string', 'finance', 'Where students send online payments. SAMPLE details: replace with the school\'s real accounts.', 0],
    ['enrollment_open_message', 'Enrollment for the coming semester is now open.', 'string', 'enrollment', 'Shown on the public enrollment page', 1],
    ['school_logo', '', 'string', 'school', 'Stored logo file (set from System Settings); empty uses the built-in seal', 1],
    ['president_name', 'Engr. Jaime S. Gose', 'string', 'school', 'The President named on the e-ID card', 0],
    ['president_title', 'President & CEO', 'string', 'school', 'The President\'s title on the e-ID card', 0],
    ['president_signature', '', 'string', 'school', 'Stored signature image of the President (set from System Settings)', 0],
    ['registrar_name', 'Jury Grace Dela Cruz-Nortez', 'string', 'school', 'The Registrar named on the Certificate of Registration and the other forms the registrar signs', 0],
    ['registrar_signature', '', 'string', 'school', 'Stored signature image of the Registrar (set from System Settings)', 0],
    // Security (docs/07). These take over from config security.* once seeded.
    ['max_login_attempts', '5', 'int', 'security', 'Failed sign-ins before an account is locked', 0],
    ['lockout_minutes', '15', 'int', 'security', 'How long a locked account stays locked', 0],
    ['ip_max_attempts', '20', 'int', 'security', 'Failed sign-ins from one IP address before it is blocked', 0],
    ['ip_window_minutes', '15', 'int', 'security', 'Window for counting failed sign-ins', 0],
    ['password_min_length', '8', 'int', 'security', 'Minimum password length', 0],
    ['reset_link_minutes', '60', 'int', 'security', 'How long a password reset link works', 0],
    ['setup_link_hours', '72', 'int', 'security', 'How long a new staff account\'s set-password link works', 0],
    // Backups (docs/20)
    ['backup_retention_count', '14', 'int', 'backups', 'Completed backups to keep; older ones are removed after each new backup', 0],
    ['backup_include_uploads', '1', 'bool', 'backups', 'Include uploaded files (requirements, payment proofs, images) in backups', 0],
];

const YEAR_LEVELS = [
    ['First Year', 1], ['Second Year', 2], ['Third Year', 3], ['Fourth Year', 4],
];

/** The programs Lyceum of the East Aurora offers; their curricula are loaded by import_curricula.php. */
const PROGRAMS = [
    ['BSA', 'Bachelor of Science in Accountancy', 'Financial accounting and reporting, auditing, taxation, and business law.', 'Bachelor', 4],
    ['BSAIS', 'Bachelor of Science in Accounting Information System', 'Accounting joined with information systems: analysis and design, security, data management, and ERP.', 'Bachelor', 4],
    ['BSCRIM', 'Bachelor of Science in Criminology', 'Criminal justice, law enforcement, criminal investigation, forensic science, and corrections.', 'Bachelor', 4],
    ['BSENTREP', 'Bachelor of Science in Entrepreneurship', 'Starting and running an enterprise, from opportunity seeking to a business plan put into practice.', 'Bachelor', 4],
    ['BPA', 'Bachelor of Public Administration', 'Public policy, local governance, fiscal administration, and ethics in public service.', 'Bachelor', 4],
    ['BSTM', 'Bachelor of Science in Tourism Management', 'Tour and travel management, sustainable tourism, events (MICE), and hospitality service.', 'Bachelor', 4],
];

/** code => [name, description, is_required, applies_to, sort_order] (docs/10) */
const REQUIREMENT_TYPES = [
    'birth_certificate'   => ['PSA Birth Certificate', 'Clear copy of your PSA-issued birth certificate.', 1, 'New,Transferee', 10],
    'report_card'         => ['Form 138 / Report Card', 'Senior high school report card showing your final grades.', 1, 'New', 20],
    'good_moral'          => ['Certificate of Good Moral Character', 'Issued by your previous school.', 1, 'New,Transferee', 30],
    'transfer_credential' => ['Transfer Credential (Honorable Dismissal)', 'Issued by the school you are transferring from.', 1, 'Transferee', 40],
    'transcript'          => ['Transcript of Records', 'Copy of your transcript from your previous school.', 1, 'Transferee', 50],
    'id_photo'            => ['2x2 ID Photograph', 'Recent photo with a plain white background.', 1, 'All', 60],
    'valid_id'            => ['Valid Government or School ID', 'Any valid ID showing your name and photo.', 0, 'All', 70],
    'medical_certificate' => ['Medical Certificate', 'Issued by a licensed physician within the last six months.', 0, 'New,Transferee', 80],
    // The sixth value limits a document to a civil status (docs/10): this one is asked of married students.
    'marriage_certificate' => ['PSA Marriage Certificate', 'Clear copy of your PSA-issued marriage certificate. Asked of married students.', 1, 'All', 25, 'Married'],
];

try {
    Database::transaction(static function (): void {
        seedRoles();
        seedRolePermissions(seedPermissions());
        seedSettings();
        seedYearLevels();
        seedPrograms();
        seedAcademicCalendar();
        seedRequirementTypes();
        seedEnrollmentWindows();
        seedGradePeriods();
        seedFeeTypes();
        echo "  ledger accounts ...... " . App\Services\LedgerAccountService::ensureAccounts() . " added\n";
    });

    echo "\n  Seeding complete.\n";
    echo "  Next: php database/create_admin.php\n\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\n  ERROR: " . $e->getMessage() . "\n\n");
    exit(1);
}

// ---------------------------------------------------------------------------

function seedRoles(): void
{
    foreach (ROLES as $name => [$slug, $description]) {
        Database::query(
            'INSERT INTO roles (name, slug, description) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE slug = VALUES(slug), description = VALUES(description)',
            [$name, $slug, $description]
        );
    }
    echo '  roles ................ ' . count(ROLES) . "\n";
}

/** @return array<int,string> the permission keys this run created */
function seedPermissions(): array
{
    $existing = array_column(Database::select('SELECT `key` FROM permissions'), 'key');
    foreach (PERMISSIONS as $key => [$module, $description]) {
        Database::query(
            'INSERT INTO permissions (`key`, module, description) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE module = VALUES(module), description = VALUES(description)',
            [$key, $module, $description]
        );
    }
    echo '  permissions .......... ' . count(PERMISSIONS) . "\n";
    return array_values(array_diff(array_keys(PERMISSIONS), $existing));
}

/**
 * Default grants, without undoing changes the Super Admin made in the roles screen:
 * see PermissionService::seedDefaults().
 *
 * @param array<int,string> $newKeys
 */
function seedRolePermissions(array $newKeys): void
{
    $granted = App\Services\PermissionService::seedDefaults(ROLE_PERMISSIONS, $newKeys);
    echo "  role permissions ..... {$granted} added\n";
}

function seedSettings(): void
{
    foreach (SETTINGS as [$key, $value, $type, $group, $description, $isPublic]) {
        // Only insert — never overwrite a value the school has already changed.
        Database::query(
            'INSERT INTO system_settings (`key`, `value`, `type`, `group`, description, is_public)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE description = VALUES(description), `group` = VALUES(`group`)',
            [$key, $value, $type, $group, $description, $isPublic]
        );
    }
    echo '  system settings ...... ' . count(SETTINGS) . "\n";
}

function seedYearLevels(): void
{
    foreach (YEAR_LEVELS as [$name, $sequence]) {
        Database::query(
            'INSERT INTO year_levels (name, sequence) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name)',
            [$name, $sequence]
        );
    }
    echo '  year levels .......... ' . count(YEAR_LEVELS) . "\n";
}

function seedPrograms(): void
{
    foreach (PROGRAMS as [$code, $name, $description, $level, $years]) {
        Database::query(
            'INSERT INTO programs (code, name, description, degree_level, years_duration, is_active)
             VALUES (?, ?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)',
            [$code, $name, $description, $level, $years]
        );
    }
    echo '  programs ............. ' . count(PROGRAMS) . "\n";
}

function seedAcademicCalendar(): void
{
    $year = (int) date('Y');
    $code = $year . '-' . ($year + 1);

    Database::query(
        'INSERT INTO academic_years (code, start_date, end_date, is_active) VALUES (?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE start_date = VALUES(start_date), end_date = VALUES(end_date)',
        [$code, "{$year}-06-01", ($year + 1) . '-05-31']
    );

    $academicYearId = (int) Database::scalar('SELECT id FROM academic_years WHERE code = ?', [$code]);

    // Every curriculum has a summer term after the second year (docs/28).
    $terms = [
        ['1st Semester', 1, "{$year}-06-01", "{$year}-10-31"],
        ['2nd Semester', 2, "{$year}-11-01", ($year + 1) . '-03-31'],
        ['Summer', 3, ($year + 1) . '-04-01', ($year + 1) . '-05-31'],
    ];

    foreach ($terms as [$name, $sequence, $start, $end]) {
        Database::query(
            'INSERT INTO terms (academic_year_id, name, sequence, start_date, end_date, is_active)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), start_date = VALUES(start_date), end_date = VALUES(end_date)',
            [$academicYearId, $name, $sequence, $start, $end, $sequence === 1 ? 1 : 0]
        );
    }

    echo "  academic calendar .... {$code}\n";
}

function seedRequirementTypes(): void
{
    foreach (REQUIREMENT_TYPES as $code => $entry) {
        [$name, $description, $required, $appliesTo, $sort, $civilStatus] = array_pad($entry, 6, null);
        // Keyed on code. Only the wording is refreshed on re-run; whether a
        // document is required and who it applies to belong to the registrar
        // once the system is in use, so they are never overwritten here.
        Database::query(
            'INSERT INTO requirement_types (code, name, description, is_required, applies_to, applies_to_civil_status, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)',
            [$code, $name, $description, $required, $appliesTo, $civilStatus, $sort]
        );
    }
    echo '  requirement types .... ' . count(REQUIREMENT_TYPES) . "\n";
}

/**
 * Give each term an enrollment window if it has none: opens 60 days before the
 * term starts and closes 7 days after. Written only while the columns are NULL,
 * so a window the registrar has set is never overwritten.
 */
function seedEnrollmentWindows(): void
{
    $updated = Database::query(
        "UPDATE terms
            SET enrollment_opens_at  = DATE_SUB(start_date, INTERVAL 60 DAY),
                enrollment_closes_at = DATE_ADD(start_date, INTERVAL 7 DAY) + INTERVAL 23 HOUR + INTERVAL 59 MINUTE
          WHERE enrollment_opens_at IS NULL AND enrollment_closes_at IS NULL"
    )->rowCount();

    $open = Database::select(
        "SELECT t.name, a.code FROM terms t JOIN academic_years a ON a.id = t.academic_year_id
          WHERE NOW() BETWEEN t.enrollment_opens_at AND t.enrollment_closes_at"
    );
    $openLabel = $open === [] ? 'none open today' : implode(', ', array_map(static fn ($t) => "{$t['name']} {$t['code']} open", $open));

    echo "  enrollment windows ... {$updated} set ({$openLabel})\n";
}

/**
 * Prelim, Midterm, Semi-Finals, Finals (20/20/20/40) for every term that has none (docs/27).
 * Inserted closed; the registrar opens each one when grading starts. Terms that
 * already have periods are left exactly as the registrar set them.
 */
function seedGradePeriods(): void
{
    $added = 0;
    foreach (Database::select('SELECT id FROM terms') as $term) {
        $added += App\Services\GradingService::seedPeriods((int) $term['id']);
    }

    echo "  grade periods ........ {$added} added\n";
}

/**
 * The school's fee schedule (docs/14), from its A.Y. 2026-2027 1st Semester ledger (Public
 * Administration, 3rd year). These are school-wide DEFAULTS to verify: Laboratory, Computer Lab,
 * and NSTP in particular probably vary by program or year, and the cashier scopes them on the Fee
 * Schedule page. Re-runnable: a fee the cashier has changed is never overwritten, and the
 * starter samples are only replaced while they are still untouched.
 */
function seedFeeTypes(): void
{
    $source = 'From the A.Y. 2026-2027 1st Sem ledger (PUB AD 3). Verify against the school\'s fee schedule.';
    $fees = [
        // code, name, basis, amount, sort
        ['TUITION', 'Tuition fee', 'PER_UNIT', '319.98', 10],
        ['REGISTRATION', 'Registration fee', 'FIXED', '1027.13', 20],
        ['GUIDANCE', 'Guidance counseling', 'FIXED', '213.17', 30],
        ['MEDICAL', 'Medical/Dental', 'FIXED', '319.81', 40],
        ['LAB', 'Laboratory', 'FIXED', '835.38', 50],
        ['STUDENT_ID', 'Student ID', 'FIXED', '157.96', 60],
        ['LIBRARY', 'Library fee', 'FIXED', '520.75', 70],
        ['AUDIO_VISUAL', 'Audio visual', 'FIXED', '208.85', 80],
        ['STUDENT_ORG', 'Student org. & RLA', 'FIXED', '746.30', 90],
        ['INSURANCE', 'Insurance', 'FIXED', '106.61', 100],
        ['COMPUTER_LAB', 'Computer lab fee/internet/LMS', 'FIXED', '1705.84', 110],
        ['PUBLICATION', 'School publication', 'FIXED', '154.07', 120],
        ['CULTURAL', 'Cultural fee', 'FIXED', '104.42', 130],
        ['ATHLETIC', 'Athletic fee', 'FIXED', '313.27', 140],
    ];

    $added = $updated = $retired = 0;
    foreach ($fees as [$code, $name, $basis, $amount, $sort]) {
        $existing = Database::selectOne('SELECT id, description FROM fee_types WHERE code = ?', [$code]);
        if ($existing === null) {
            $added += Database::query(
                'INSERT INTO fee_types (code, fee_key, name, description, basis, amount, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)',
                [$code, $code, $name, $source, $basis, $amount, $sort]
            )->rowCount();
        } elseif (str_starts_with((string) $existing['description'], 'Sample amount')) {
            $updated += Database::query(
                'UPDATE fee_types SET name = ?, description = ?, basis = ?, amount = ?, sort_order = ? WHERE id = ?',
                [$name, $source, $basis, $amount, $sort, $existing['id']]
            )->rowCount();
        }
    }
    // The old starter lines the school's ledger does not have, while still untouched samples.
    $retired += Database::query(
        "UPDATE fee_types SET is_active = 0 WHERE code IN ('TECHNOLOGY', 'MISC') AND is_active = 1 AND description LIKE 'Sample amount%'"
    )->rowCount();
    echo "  fee types ............ {$added} added, {$updated} sample(s) replaced, {$retired} retired\n";
}
