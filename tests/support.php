<?php
/**
 * Test helpers.
 *
 * Tests run against the configured database. They create their own fixtures
 * with a distinctive prefix and remove them afterwards.
 */

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Models\User;

const TEST_PREFIX = '__test_';

/**
 * Create a user for a test and return it.
 * Always pair with deleteTestUsers() in a finally block.
 */
function makeUser(
    string $roleName,
    string $password = 'TestPass123',
    string $status = User::STATUS_ACTIVE,
    bool $mustChange = false
): User {
    $roleId = (int) Database::scalar('SELECT id FROM roles WHERE name = ?', [$roleName]);
    if ($roleId === 0) {
        throw new RuntimeException("Role '{$roleName}' is not seeded. Run: php database/seed.php");
    }

    $suffix = bin2hex(random_bytes(4));
    $username = TEST_PREFIX . $suffix;

    $id = Database::insert('users', [
        'role_id'              => $roleId,
        'username'             => $username,
        'email'                => $username . '@test.local',
        'password_hash'        => password_hash($password, PASSWORD_DEFAULT),
        'first_name'           => 'Test',
        'last_name'            => ucfirst(strtolower(str_replace(' ', '', $roleName))),
        'status'               => $status,
        'must_change_password' => $mustChange ? 1 : 0,
    ]);

    $user = User::find($id);
    if ($user === null) {
        throw new RuntimeException('Failed to create the test user.');
    }

    return $user;
}

/** Remove every fixture this suite created. */
/**
 * LIKE pattern for fixture usernames. The underscores in the prefix are escaped:
 * unescaped, "_" is a single-character wildcard, and '__test_%' would also
 * match real usernames such as "abtest1" — and delete them.
 */
function testPrefixPattern(): string
{
    return str_replace('_', '\\_', TEST_PREFIX) . '%';
}

function deleteTestUsers(): void
{
    // Catalog fixtures first: they may reference fixture users (section advisers)
    // and must go even when a test created no users.
    deleteTestCatalog();

    $ids = array_column(
        Database::select('SELECT id FROM users WHERE username LIKE ?', [testPrefixPattern()]),
        'id'
    );

    if ($ids === []) {
        return;
    }

    $in = implode(',', array_fill(0, count($ids), '?'));

    // Notifications addressed to fixture users go with them (FK cascade), but
    // "new application" notices also reach REAL registrars; remove those by the
    // fixture application they point at.
    $fixtureApplicationIds = array_column(Database::select(
        "SELECT ea.id FROM enrollment_applications ea JOIN students s ON s.id = ea.student_id WHERE s.user_id IN ({$in})",
        $ids
    ), 'id');
    foreach ($fixtureApplicationIds as $applicationId) {
        Database::query(
            'DELETE FROM notifications WHERE link_url IN (?, ?)',
            ["/registrar/applications/{$applicationId}", "/student/enrollment/{$applicationId}"]
        );
    }

    // Student data, child tables first (the foreign keys are RESTRICT).
    $studentIds = array_column(Database::select("SELECT id FROM students WHERE user_id IN ({$in})", $ids), 'id');
    if ($studentIds !== []) {
        $sin = implode(',', array_fill(0, count($studentIds), '?'));
        $applicationIds = array_column(
            Database::select("SELECT id FROM enrollment_applications WHERE student_id IN ({$sin})", $studentIds),
            'id'
        );

        if ($applicationIds !== []) {
            $ain = implode(',', array_fill(0, count($applicationIds), '?'));

            foreach (Database::select(
                "SELECT f.storage_path FROM enrollment_requirement_files f
                   JOIN enrollment_requirements er ON er.id = f.enrollment_requirement_id
                  WHERE er.application_id IN ({$ain})",
                $applicationIds
            ) as $file) {
                @unlink(STORAGE_PATH . '/' . $file['storage_path']);
            }
            foreach ($applicationIds as $applicationId) {
                @rmdir(STORAGE_PATH . '/uploads/requirements/' . $applicationId);
            }

            Database::query(
                "DELETE f FROM enrollment_requirement_files f
                   JOIN enrollment_requirements er ON er.id = f.enrollment_requirement_id
                  WHERE er.application_id IN ({$ain})",
                $applicationIds
            );
            Database::query("DELETE FROM enrollments WHERE application_id IN ({$ain})", $applicationIds);
            Database::query("DELETE FROM enrollment_requirements WHERE application_id IN ({$ain})", $applicationIds);
            Database::query("DELETE FROM enrollment_status_history WHERE application_id IN ({$ain})", $applicationIds);
            Database::query("DELETE FROM enrollment_applications WHERE id IN ({$ain})", $applicationIds);
        }

        Database::query("DELETE FROM guardians WHERE student_id IN ({$sin})", $studentIds);
        Database::query("DELETE FROM student_profiles WHERE student_id IN ({$sin})", $studentIds);
        Database::query("DELETE FROM students WHERE id IN ({$sin})", $studentIds);

        resetApplicationSequence();
        resetStudentSequence();
    }

    // Again, now that fixture applications no longer hold fixture programs.
    deleteTestCatalog();

    Database::query("DELETE FROM login_logs WHERE user_id IN ({$in})", $ids);
    Database::query("DELETE FROM audit_logs WHERE user_id IN ({$in})", $ids);
    Database::query("DELETE FROM users WHERE id IN ({$in})", $ids);
}

/** Fixture programs, subjects, and sections use codes starting with this. */
const TEST_CODE = 'ZZT';

/** Remove fixture catalog rows (codes starting ZZT), children first. */
function deleteTestCatalog(): void
{
    $like = TEST_CODE . '%';

    Database::query(
        'DELETE e FROM enrollments e JOIN sections sec ON sec.id = e.section_id WHERE sec.code LIKE ?',
        [$like]
    );
    Database::query('DELETE FROM sections WHERE code LIKE ?', [$like]);
    Database::query(
        'DELETE sp FROM subject_prerequisites sp JOIN subjects s ON s.id = sp.subject_id WHERE s.code LIKE ?',
        [$like]
    );
    Database::query(
        'DELETE sp FROM subject_prerequisites sp JOIN subjects s ON s.id = sp.prerequisite_subject_id WHERE s.code LIKE ?',
        [$like]
    );
    Database::query('DELETE FROM subjects WHERE code LIKE ?', [$like]);
    Database::query(
        'DELETE FROM programs WHERE code LIKE ? AND id NOT IN (SELECT program_id FROM enrollment_applications)',
        [$like]
    );
}

/** Rewind the student-number counter to the highest number still assigned. */
function resetStudentSequence(): void
{
    $period = date('Y');
    $highest = (string) Database::scalar(
        'SELECT MAX(student_number) FROM students WHERE student_number LIKE ?',
        ["{$period}-%"]
    );
    $number = $highest === '' ? 0 : (int) substr($highest, strrpos($highest, '-') + 1);

    Database::query(
        "UPDATE document_sequences SET current_number = ? WHERE `key` = 'student' AND period = ?",
        [$number, $period]
    );
}

/**
 * Rewind the application-number counter to the highest number still in use, so
 * fixture applications do not leave gaps in the real numbering.
 */
function resetApplicationSequence(): void
{
    $period = date('Y');
    $highest = (string) Database::scalar(
        'SELECT MAX(application_number) FROM enrollment_applications WHERE application_number LIKE ?',
        ["APP-{$period}-%"]
    );
    $number = $highest === '' ? 0 : (int) substr($highest, strrpos($highest, '-') + 1);

    Database::query(
        "UPDATE document_sequences SET current_number = ? WHERE `key` = 'application' AND period = ?",
        [$number, $period]
    );
}

/** Also clear login_logs rows keyed only by the attempted username. */
function deleteTestLoginLogs(): void
{
    Database::query('DELETE FROM login_logs WHERE username_attempted LIKE ?', [testPrefixPattern()]);
}

// -- Phase 2 fixtures -----------------------------------------------------------

/** A Student user with a complete profile and an emergency contact. */
function makeStudent(): User
{
    $user = makeUser(User::ROLE_STUDENT);
    actingAs($user);

    App\Services\StudentProfileService::update($user, completeProfileInput());

    return User::find($user->id);
}

/** @return array<string,mixed> */
function completeProfileInput(): array
{
    return [
        'first_name'        => 'Test',
        'last_name'         => 'Student',
        'birthdate'         => '2006-05-14',
        'sex'               => 'Female',
        'civil_status'      => 'Single',
        'mobile'            => '09171234567',
        'barangay'          => 'Poblacion',
        'city_municipality' => 'Baler',
        'province'          => 'Aurora',
        'nationality'       => 'Filipino',
        'guardians'         => [[
            'full_name'            => 'Guardian Test',
            'relationship'         => 'Mother',
            'mobile'               => '09181234567',
            'is_emergency_contact' => '1',
        ]],
    ];
}

function openTermId(): int
{
    $open = App\Models\Term::openForEnrollment();
    if ($open === []) {
        throw new RuntimeException('No term is open for enrollment. Run: php database/seed.php');
    }
    return (int) $open[0]['id'];
}

function closedTermId(): int
{
    foreach (Database::select('SELECT * FROM terms') as $term) {
        if (!App\Models\Term::isOpen($term)) {
            return (int) $term['id'];
        }
    }
    throw new RuntimeException('Every term is open; cannot test a closed window.');
}

/** @return array<string,mixed> application input for the open term */
function applicationInput(string $studentType = 'New'): array
{
    return [
        'term_id'         => openTermId(),
        'program_id'      => (int) Database::scalar('SELECT id FROM programs WHERE is_active = 1 ORDER BY id LIMIT 1'),
        'year_level_id'   => (int) Database::scalar('SELECT id FROM year_levels ORDER BY sequence LIMIT 1'),
        'student_type'    => $studentType,
        'previous_school' => $studentType === 'Transferee' ? 'Aurora State College' : '',
    ];
}

/** Upload a valid PDF to every required document of an application. */
function uploadAllRequired(User $student, int $applicationId): void
{
    foreach (App\Models\EnrollmentApplication::requirements($applicationId) as $requirement) {
        if ((int) $requirement['is_required'] === 1) {
            App\Services\DocumentUploadService::upload(
                $student,
                $applicationId,
                (int) $requirement['id'],
                App\Core\UploadedFile::forTesting(fixtureFile('pdf'), 'scan.pdf')
            );
        }
    }
}

/**
 * A temporary file of a given kind. Each call returns a fresh copy, because the
 * upload service copies from it.
 *
 *   pdf        a genuine (minimal) PDF
 *   png        a genuine 1x1 PNG
 *   fake_pdf   plain text pretending to be a PDF
 *   big_pdf    a genuine PDF over 5 MB
 *   empty      a zero-byte file
 */
function fixtureFile(string $kind): string
{
    $path = tempnam(sys_get_temp_dir(), 'shx');

    $content = match ($kind) {
        'pdf'      => "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n",
        'png'      => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='),
        'fake_pdf' => "This is just a text file with a .pdf name.\n",
        'big_pdf'  => "%PDF-1.4\n" . str_repeat('0', 5 * 1024 * 1024 + 10) . "\n%%EOF\n",
        'empty'    => '',
        default    => throw new InvalidArgumentException("Unknown fixture kind: {$kind}"),
    };

    file_put_contents($path, $content);
    register_shutdown_function(static fn () => @unlink($path));

    return $path;
}

/** Sign a user in for the duration of a test, without a real HTTP session. */
function actingAs(?User $user): void
{
    $_SESSION = $_SESSION ?? [];
    Auth::forgetCache();

    if ($user === null) {
        unset($_SESSION['_auth_user_id']);
        return;
    }

    $_SESSION['_auth_user_id'] = $user->id;
}

/** A fresh Request for middleware tests. */
function makeRequest(string $method = 'GET', string $path = '/', array $post = []): App\Core\Request
{
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = $path;
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'test-runner';
    $_POST = $post;
    $_GET = [];

    return new App\Core\Request();
}

/** The HTTP status an HttpException carries, or null if none was thrown. */
function statusOf(callable $callback): ?int
{
    try {
        $callback();
    } catch (App\Core\HttpException $e) {
        return $e->status();
    }
    return null;
}

// -- Phase 3 fixtures -----------------------------------------------------------

/** Run a callback with a system setting temporarily changed, always restoring it. */
function withSetting(string $key, string $value, callable $callback): mixed
{
    $original = (string) Database::scalar('SELECT `value` FROM system_settings WHERE `key` = ?', [$key]);
    App\Models\SystemSetting::set($key, $value);
    try {
        return $callback();
    } finally {
        App\Models\SystemSetting::set($key, $original);
    }
}

/** A fixture section matching an application's program, year level, and term. */
function makeSectionFor(int $applicationId, int $capacity = 40): int
{
    $application = Database::selectOne('SELECT * FROM enrollment_applications WHERE id = ?', [$applicationId]);

    return Database::insert('sections', [
        'code'          => TEST_CODE . strtoupper(bin2hex(random_bytes(3))),
        'program_id'    => (int) $application['program_id'],
        'year_level_id' => (int) $application['year_level_id'],
        'term_id'       => (int) $application['term_id'],
        'capacity'      => $capacity,
        'is_active'     => 1,
    ]);
}

/** A submitted application whose required documents are all uploaded. @return array{0:User,1:int} */
function submittedApplication(string $studentType = 'New'): array
{
    $student = makeStudent();
    $id = App\Services\EnrollmentApplicationService::createDraft($student, applicationInput($studentType));
    uploadAllRequired($student, $id);
    App\Services\EnrollmentApplicationService::submit($student, $id);
    return [$student, $id];
}

/**
 * An application taken all the way to APPROVED by a registrar through the real
 * services. @return array{0:User,1:User,2:int} student, registrar, application id
 */
function approvedApplication(): array
{
    [$student, $id] = submittedApplication();
    $registrar = makeUser(User::ROLE_REGISTRAR);

    App\Services\EnrollmentStatusService::transition($id, 'UNDER_REVIEW', $registrar);
    foreach (App\Models\EnrollmentApplication::requirements($id) as $requirement) {
        if ((int) $requirement['is_required'] === 1) {
            App\Services\RequirementReviewService::review($registrar, (int) $requirement['id'], 'VERIFIED', null);
        }
    }
    App\Services\EnrollmentStatusService::transition($id, 'APPROVED', $registrar);

    return [$student, $registrar, $id];
}
