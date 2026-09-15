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

    // Announcements written by fixture users, with their images and the notifications
    // they sent to real accounts (the author key would otherwise just be set to NULL).
    foreach (Database::select("SELECT id, image_path FROM announcements WHERE author_user_id IN ({$in})", $ids) as $announcement) {
        if ($announcement['image_path'] !== null) {
            @unlink(STORAGE_PATH . '/' . $announcement['image_path']);
        }
        Database::query('DELETE FROM notifications WHERE link_url = ?', ['/portal/announcements/' . $announcement['id']]);
        Database::query('DELETE FROM announcements WHERE id = ?', [$announcement['id']]);
    }

    // Backups made by fixture users, with their archives.
    foreach (Database::select("SELECT id, path FROM backups WHERE created_by IN ({$in})", $ids) as $backup) {
        @unlink(STORAGE_PATH . '/' . $backup['path']);
        Database::query('DELETE FROM backups WHERE id = ?', [$backup['id']]);
    }

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
            // Anything recorded against a fixture enrollment, even in a real class.
            $enlistments = "SELECT ce.id FROM class_enlistments ce JOIN enrollments en ON en.id = ce.enrollment_id WHERE en.application_id IN ({$ain})";
            Database::query("DELETE FROM attendance WHERE class_enlistment_id IN ({$enlistments})", $applicationIds);
            Database::query("DELETE FROM grades WHERE class_enlistment_id IN ({$enlistments})", $applicationIds);
            Database::query("DELETE ce FROM class_enlistments ce JOIN enrollments en ON en.id = ce.enrollment_id WHERE en.application_id IN ({$ain})", $applicationIds);
            Database::query("DELETE FROM enrollments WHERE application_id IN ({$ain})", $applicationIds);

            // Finance: payments, proofs (and their files), fee lines, assessments.
            $assessments = "SELECT id FROM fee_assessments WHERE application_id IN ({$ain})";
            foreach (Database::select("SELECT id, storage_path, fee_assessment_id FROM payment_submissions WHERE fee_assessment_id IN ({$assessments})", $applicationIds) as $proof) {
                @unlink(STORAGE_PATH . '/' . $proof['storage_path']);
                @rmdir(STORAGE_PATH . '/uploads/payments/' . $proof['fee_assessment_id']);
                Database::query('DELETE FROM notifications WHERE link_url = ?', ['/cashier/payments/' . $proof['id']]);
            }
            // "Below payment policy" notices reach real cashiers too, linked to the account.
            foreach (Database::select($assessments, $applicationIds) as $assessment) {
                Database::query('DELETE FROM notifications WHERE link_url = ?', ['/cashier/accounts/' . $assessment['id']]);
            }
            // Reversals point at the payment they cancel, so they go first.
            Database::query("DELETE FROM payments WHERE reversed_payment_id IS NOT NULL AND fee_assessment_id IN ({$assessments})", $applicationIds);
            Database::query("DELETE FROM payments WHERE fee_assessment_id IN ({$assessments})", $applicationIds);
            Database::query("DELETE FROM financial_adjustments WHERE fee_assessment_id IN ({$assessments})", $applicationIds);
            Database::query("DELETE FROM payment_submissions WHERE fee_assessment_id IN ({$assessments})", $applicationIds);
            Database::query("DELETE FROM fee_items WHERE fee_assessment_id IN ({$assessments})", $applicationIds);
            Database::query("DELETE FROM fee_assessments WHERE application_id IN ({$ain})", $applicationIds);
            Database::query("DELETE FROM application_subjects WHERE application_id IN ({$ain})", $applicationIds);
            resetSequence('assessment', 'ASM-', 'fee_assessments', 'assessment_number');
            resetSequence('payment', 'PAY-', 'payments', 'transaction_number');
            resetSequence('receipt', 'OR-', 'payments', 'receipt_number');
            resetSequence('adjustment', 'ADJ-', 'financial_adjustments', 'adjustment_number');
            Database::query("DELETE FROM enrollment_requirements WHERE application_id IN ({$ain})", $applicationIds);
            Database::query("DELETE FROM enrollment_status_history WHERE application_id IN ({$ain})", $applicationIds);
            Database::query("DELETE FROM enrollment_applications WHERE id IN ({$ain})", $applicationIds);
        }

        foreach (Database::select("SELECT photo_path FROM student_profiles WHERE student_id IN ({$sin}) AND photo_path IS NOT NULL", $studentIds) as $row) {
            @unlink(STORAGE_PATH . '/' . $row['photo_path']);
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

/**
 * Remove fixture catalog rows, children first. Fixture sections, rooms, programs,
 * and curricula have codes starting ZZT; fixture subjects either have a ZZT code
 * or belong to a ZZT curriculum (so a test can use real-looking codes like CORE 1).
 */
function deleteTestCatalog(): void
{
    $like = TEST_CODE . '%';

    // Classes of fixture sections, or of fixture subjects, with everything recorded on them.
    $fixtureClasses = 'SELECT cs.id FROM class_schedules cs
                         JOIN sections sec ON sec.id = cs.section_id
                         JOIN subjects sub ON sub.id = cs.subject_id
                    LEFT JOIN curricula cur ON cur.id = sub.curriculum_id
                        WHERE sec.code LIKE ? OR sub.code LIKE ? OR cur.code LIKE ?';
    $classIds = array_column(Database::select($fixtureClasses, [$like, $like, $like]), 'id');
    if ($classIds !== []) {
        $in = implode(',', array_fill(0, count($classIds), '?'));
        Database::query("DELETE a FROM attendance a JOIN class_enlistments ce ON ce.id = a.class_enlistment_id WHERE ce.class_schedule_id IN ({$in})", $classIds);
        Database::query("DELETE g FROM grades g JOIN class_enlistments ce ON ce.id = g.class_enlistment_id WHERE ce.class_schedule_id IN ({$in})", $classIds);
        Database::query("DELETE FROM class_enlistments WHERE class_schedule_id IN ({$in})", $classIds);
        Database::query("DELETE FROM class_meetings WHERE class_schedule_id IN ({$in})", $classIds);
        Database::query("DELETE FROM class_schedules WHERE id IN ({$in})", $classIds);
    }
    Database::query('DELETE FROM class_meetings WHERE room_id IN (SELECT id FROM rooms WHERE code LIKE ?)', [$like]);
    Database::query('DELETE FROM rooms WHERE code LIKE ?', [$like]);

    Database::query(
        'DELETE e FROM enrollments e JOIN sections sec ON sec.id = e.section_id WHERE sec.code LIKE ?',
        [$like]
    );
    Database::query('UPDATE enrollment_applications SET section_id = NULL WHERE section_id IN (SELECT id FROM sections WHERE code LIKE ?)', [$like]);
    Database::query('DELETE FROM sections WHERE code LIKE ?', [$like]);

    $fixtureSubjects = 'SELECT s.id FROM subjects s LEFT JOIN curricula c ON c.id = s.curriculum_id WHERE s.code LIKE ? OR c.code LIKE ?';
    $subjectIds = array_column(Database::select($fixtureSubjects, [$like, $like]), 'id');
    if ($subjectIds !== []) {
        $in = implode(',', array_fill(0, count($subjectIds), '?'));
        Database::query("DELETE FROM application_subjects WHERE subject_id IN ({$in})", $subjectIds);
        Database::query("DELETE FROM subject_prerequisites WHERE subject_id IN ({$in}) OR prerequisite_subject_id IN ({$in})", array_merge($subjectIds, $subjectIds));
        Database::query("DELETE FROM subjects WHERE id IN ({$in})", $subjectIds);
    }

    Database::query('UPDATE students SET curriculum_id = NULL WHERE curriculum_id IN (SELECT id FROM curricula WHERE code LIKE ?)', [$like]);
    Database::query('DELETE FROM curricula WHERE code LIKE ?', [$like]);
    Database::query(
        'DELETE FROM programs WHERE code LIKE ? AND id NOT IN (SELECT program_id FROM enrollment_applications)',
        [$like]
    );
}

/** Rewind a PREFIX-YYYY-NNNNN counter to the highest number still in use. */
function resetSequence(string $key, string $prefix, string $table, string $column): void
{
    $period = date('Y');
    $highest = (string) Database::scalar("SELECT MAX(`{$column}`) FROM `{$table}` WHERE `{$column}` LIKE ?", ["{$prefix}{$period}-%"]);
    $number = $highest === '' ? 0 : (int) substr($highest, strrpos($highest, '-') + 1);
    Database::query('UPDATE document_sequences SET current_number = ? WHERE `key` = ? AND period = ?', [$number, $key, $period]);
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

/** Fixture setup: give an application a matching section, as the registrar does when approving. */
function assignSectionForTest(int $applicationId): int
{
    $sectionId = makeSectionFor($applicationId);
    Database::update('enrollment_applications', ['section_id' => $sectionId], ['id' => $applicationId]);
    return $sectionId;
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
    App\Services\EnrollmentApplicationService::approve($registrar, $id, makeSectionFor($id));

    return [$student, $registrar, $id];
}

// -- Catalog fixtures -----------------------------------------------------------

/** A unique fixture code: ZZT + suffix + random hex. */
function testCode(string $suffix): string
{
    return TEST_CODE . strtoupper($suffix) . strtoupper(bin2hex(random_bytes(2)));
}

/** @return array<string,array<int,string>> the field errors a callback produced */
function fieldErrors(callable $callback): array
{
    try {
        $callback();
    } catch (App\Core\ValidationException $e) {
        return $e->errors();
    }
    return [];
}

// -- Phase 4 fixtures -----------------------------------------------------------

/** The term that contains today. Attendance can only be taken in a term that has started. */
function currentTermId(): int
{
    $id = Database::scalar('SELECT id FROM terms WHERE CURDATE() BETWEEN start_date AND end_date ORDER BY start_date LIMIT 1');
    if ($id === null) {
        throw new RuntimeException('No term contains today. Run: php database/seed.php');
    }
    return (int) $id;
}

/**
 * A student confirmed into a section, through the real services. With no section
 * given, a fresh fixture section is made; a given section's program, year level,
 * and term are copied onto the application first (fixture setup).
 *
 * @return array{student:User,registrar:User,application_id:int,section_id:int,enrollment_id:int,student_id:int}
 */
function enrolledStudent(?int $termId = null, ?int $sectionId = null): array
{
    [$student, $registrar, $applicationId] = approvedApplication();

    if ($sectionId !== null) {
        $section = Database::selectOne('SELECT * FROM sections WHERE id = ?', [$sectionId]);
        Database::update('enrollment_applications', [
            'program_id' => $section['program_id'], 'year_level_id' => $section['year_level_id'], 'term_id' => $section['term_id'],
        ], ['id' => $applicationId]);
    } else {
        if ($termId !== null) {
            Database::update('enrollment_applications', ['term_id' => $termId], ['id' => $applicationId]);
        }
        $sectionId = makeSectionFor($applicationId);
    }

    withSetting('enrollment_payment_policy', 'NONE', static fn () => App\Services\EnrollmentConfirmationService::confirm($registrar, $applicationId, $sectionId));

    $enrollment = Database::selectOne('SELECT * FROM enrollments WHERE application_id = ?', [$applicationId]);

    return [
        'student'        => $student,
        'registrar'      => $registrar,
        'application_id' => $applicationId,
        'section_id'     => $sectionId,
        'enrollment_id'  => (int) $enrollment['id'],
        'student_id'     => (int) $enrollment['student_id'],
    ];
}

/** Another fixture section in the same program, year level, and term as $sectionId. */
function siblingSection(int $sectionId): int
{
    $section = Database::selectOne('SELECT * FROM sections WHERE id = ?', [$sectionId]);
    return Database::insert('sections', [
        'code' => testCode('X'), 'program_id' => $section['program_id'], 'year_level_id' => $section['year_level_id'],
        'term_id' => $section['term_id'], 'capacity' => 40, 'is_active' => 1,
    ]);
}

/** A general fixture subject. @param array<int,int> $prerequisites */
function makeSubject(float $units = 3, array $prerequisites = [], array $extra = []): int
{
    $id = Database::insert('subjects', array_merge([
        'code' => testCode('S'), 'title' => 'Fixture Subject', 'units' => number_format($units, 1, '.', ''), 'is_active' => 1,
    ], $extra));
    foreach ($prerequisites as $prerequisite) {
        Database::insert('subject_prerequisites', ['subject_id' => $id, 'prerequisite_subject_id' => $prerequisite]);
    }
    return $id;
}

function makeRoom(): int
{
    return Database::insert('rooms', ['code' => testCode('R'), 'name' => 'Fixture Room', 'capacity' => 40, 'type' => 'Lecture', 'is_active' => 1]);
}

/** One weekly meeting, in the form ClassScheduleService takes. */
function meeting(int $day, string $start, string $end, ?int $roomId = null): array
{
    return ['day_of_week' => $day, 'start_time' => $start, 'end_time' => $end, 'room_id' => $roomId];
}

/** A class built through ClassScheduleService. With no teacher given, a fixture teacher is made. @param array<int,array> $meetings */
function makeClass(User $registrar, int $sectionId, int $subjectId, ?int $teacherId, array $meetings): int
{
    // Every class needs a teacher; a fresh one per class keeps teacher clashes out of tests about other things.
    $teacherId ??= makeUser(User::ROLE_TEACHER)->id;

    // Students choose their subjects (docs/09) and are enlisted only in those. A class
    // made for a test is a subject the fixture students of that program, year, and
    // term have chosen, so block enlistment behaves as it did before selection existed.
    $section = Database::selectOne('SELECT * FROM sections WHERE id = ?', [$sectionId]);
    Database::query(
        'INSERT IGNORE INTO application_subjects (application_id, subject_id)
         SELECT ea.id, ? FROM enrollment_applications ea
           JOIN students st ON st.id = ea.student_id JOIN users u ON u.id = st.user_id
          WHERE u.username LIKE ? AND ea.program_id = ? AND ea.year_level_id = ? AND ea.term_id = ?',
        [$subjectId, testPrefixPattern(), $section['program_id'], $section['year_level_id'], $section['term_id']]
    );
    return App\Services\ClassScheduleService::saveClass($registrar, $sectionId, null, [
        'subject_id' => $subjectId, 'teacher_user_id' => $teacherId, 'meetings' => $meetings,
    ])['class_id'];
}

function enlistmentIdFor(int $enrollmentId, int $classId): ?int
{
    $id = Database::scalar('SELECT id FROM class_enlistments WHERE enrollment_id = ? AND class_schedule_id = ?', [$enrollmentId, $classId]);
    return $id === null ? null : (int) $id;
}

/** The most recent date, today or earlier, falling on an ISO weekday (1 = Monday). */
function lastWeekday(int $day): string
{
    $date = new DateTimeImmutable('today');
    while ((int) $date->format('N') !== $day) {
        $date = $date->modify('-1 day');
    }
    return $date->format('Y-m-d');
}

/**
 * Run a callback that opens, closes, or re-weights a real term's grading
 * periods, and put them back exactly as they were afterwards.
 */
function withGradePeriods(int $termId, callable $callback): mixed
{
    $snapshot = Database::select('SELECT * FROM grade_periods WHERE term_id = ?', [$termId]);
    try {
        return $callback(array_column($snapshot, 'id', 'name'));
    } finally {
        foreach ($snapshot as $period) {
            Database::update('grade_periods', [
                'weight' => $period['weight'], 'is_open' => $period['is_open'],
                'opened_at' => $period['opened_at'], 'opened_by' => $period['opened_by'],
                'closed_at' => $period['closed_at'], 'closed_by' => $period['closed_by'],
            ], ['id' => $period['id']]);
        }
    }
}

/** Open a period directly, as fixture setup (the service is tested separately). */
function openPeriodForTest(int $periodId): void
{
    Database::update('grade_periods', ['is_open' => 1, 'opened_by' => null, 'closed_by' => null], ['id' => $periodId]);
}
