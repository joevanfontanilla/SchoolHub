<?php
/**
 * Walk-in enrollment (docs/09): the registrar enrolls an applicant who comes to the office.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Models\EnrollmentApplication as Application;
use App\Models\User;
use App\Services\EnrollmentStatusService as Status;
use App\Core\Router;
use App\Services\WalkInService;
use App\Core\UploadedFile;
use App\Models\Catalog;
use App\Services\NotificationService;

/** The intake form a registrar submits for an applicant. */
/** The intake needs a section for its program, year level, and term (docs/09); make a fixture one when the database has none. */
function ensureIntakeSection(array $input): void
{
    if (Catalog::sectionsForApplication($input) === []) {
        Database::insert('sections', [
            'code'          => TEST_CODE . strtoupper(bin2hex(random_bytes(3))),
            'program_id'    => (int) $input['program_id'],
            'year_level_id' => (int) $input['year_level_id'],
            'term_id'       => (int) $input['term_id'],
            'capacity'      => 40,
            'is_active'     => 1,
        ]);
    }
}

function walkInInput(array $overrides = []): array
{
    $email = TEST_PREFIX . bin2hex(random_bytes(4)) . '@test.local';
    ensureIntakeSection(applicationInput());

    return array_merge(completeProfileInput(), applicationInput(), [
        'first_name'      => 'Walk',
        'middle_name'     => '',
        'last_name'       => 'Inn',
        'email'           => $email,
        'privacy_consent' => '1',
    ], $overrides);
}

/** @return array<int,array<string,mixed>> the application's documents */
function walkInDocuments(int $applicationId): array
{
    return Application::requirements($applicationId);
}

/** Ids of the required documents of an application. */
function walkInRequiredIds(int $applicationId): array
{
    return array_column(array_filter(
        walkInDocuments($applicationId),
        static fn ($r) => (int) $r['is_required'] === 1
    ), 'id');
}

return [
    'registering a walk-in creates a pending account, a profile, and a walk-in draft' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $input = walkInInput();

            $result = WalkInService::register($registrar, $input);

            $applicant = User::find($result['user_id']);
            $t->assertEquals(User::STATUS_PENDING, $applicant->status, 'The applicant sets a password before signing in');
            $t->assertEquals(strtolower($input['email']), $applicant->email);
            $t->assertTrue($applicant->isStudent(), 'The account is a student account');

            $application = Database::selectOne('SELECT * FROM enrollment_applications WHERE id = ?', [$result['application_id']]);
            $t->assertEquals('walk_in', $application['source']);
            $t->assertEquals($registrar->id, (int) $application['created_by'], 'The registrar who started it is recorded');
            $t->assertEquals(Application::DRAFT, $application['application_status']);

            $t->assertNotNull(Database::scalar('SELECT privacy_consented_at FROM users WHERE id = ?', [$applicant->id]));
            $t->assertTrue(strlen($result['token']) === 64, 'A one-time set-up link is issued');
            $t->assertNotNull(App\Services\PasswordResetService::find($result['token']), 'The link works');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the stored password is unusable: only the set-up link gets the applicant in' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $result = WalkInService::register($registrar, walkInInput());

            $hash = (string) Database::scalar('SELECT password_hash FROM users WHERE id = ?', [$result['user_id']]);
            $t->assertTrue($hash !== '' && password_get_info($hash)['algo'] !== null, 'A real hash is stored');
            $t->assertFalse(password_verify('', $hash));
            $t->assertFalse(password_verify('Password123', $hash));

            App\Services\PasswordResetService::complete($result['token'], 'Walk1nPassw0rd!x', 'Walk1nPassw0rd!x');
            $t->assertEquals(User::STATUS_ACTIVE, User::find($result['user_id'])->status, 'Setting a password activates the account');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'an incomplete profile or a closed term creates nothing at all' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);

            $noMobile = walkInInput(['mobile' => '']);
            $errors = fieldErrors(fn () => WalkInService::register($registrar, $noMobile));
            $t->assertTrue(isset($errors['mobile']), 'The missing mobile number is named');
            $t->assertEquals(0, (int) Database::scalar('SELECT COUNT(*) FROM users WHERE email = ?', [strtolower($noMobile['email'])]), 'No account was left behind');

            $closed = walkInInput(['term_id' => closedTermId()]);
            $errors = fieldErrors(fn () => WalkInService::register($registrar, $closed));
            $t->assertTrue(isset($errors['term_id']), 'A closed enrollment window is refused, with no override');
            $t->assertEquals(0, (int) Database::scalar('SELECT COUNT(*) FROM users WHERE email = ?', [strtolower($closed['email'])]));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the privacy consent, a new email, and the registrar role are all required' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);

            $errors = fieldErrors(fn () => WalkInService::register($registrar, walkInInput(['privacy_consent' => ''])));
            $t->assertTrue(isset($errors['privacy_consent']), 'Consent is required');

            $first = WalkInService::register($registrar, walkInInput(['email' => TEST_PREFIX . 'dup@test.local']));
            $errors = fieldErrors(fn () => WalkInService::register($registrar, walkInInput(['email' => TEST_PREFIX . 'dup@test.local'])));
            $t->assertTrue(isset($errors['email']), 'An email already in use is refused');

            foreach ([User::ROLE_STUDENT, User::ROLE_CASHIER, User::ROLE_TEACHER, User::ROLE_SUPER_ADMIN] as $role) {
                $other = makeUser($role);
                $t->assertEquals(403, statusOf(fn () => WalkInService::register($other, walkInInput())), "{$role} cannot register a walk-in");
            }
            $t->assertTrue($first['application_id'] > 0);
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'documents ticked as received are verified at once, with no file, and can be unticked' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $result = WalkInService::register($registrar, walkInInput());
            $id = $result['application_id'];
            $required = walkInRequiredIds($id);
            $t->assertTrue($required !== [], 'The fixture term has required documents');

            WalkInService::recordDocuments($registrar, $id, [$required[0]]);
            $row = Database::selectOne('SELECT * FROM enrollment_requirements WHERE id = ?', [$required[0]]);
            $t->assertEquals('VERIFIED', $row['status']);
            $t->assertEquals(1, (int) $row['received_in_person']);
            $t->assertEquals($registrar->id, (int) $row['verified_by']);
            $t->assertEquals(0, (int) Database::scalar('SELECT COUNT(*) FROM enrollment_requirement_files WHERE enrollment_requirement_id = ?', [$required[0]]), 'No file exists');

            WalkInService::recordDocuments($registrar, $id, []);
            $row = Database::selectOne('SELECT * FROM enrollment_requirements WHERE id = ?', [$required[0]]);
            $t->assertEquals('NOT_SUBMITTED', $row['status'], 'Unticking undoes it');
            $t->assertEquals(0, (int) $row['received_in_person']);
            $t->assertNull($row['verified_by']);
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'submitting needs every required document; the ticks are kept when it is refused' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $result = WalkInService::register($registrar, walkInInput());
            $id = $result['application_id'];
            $required = walkInRequiredIds($id);

            if (count($required) > 1) {
                $t->assertThrows(
                    fn () => WalkInService::submit($registrar, $id, [$required[0]]),
                    'received in person first',
                    'A missing required document refuses the submission'
                );
                $t->assertEquals(Application::DRAFT, Database::scalar('SELECT application_status FROM enrollment_applications WHERE id = ?', [$id]));
                $t->assertEquals('VERIFIED', Database::scalar('SELECT status FROM enrollment_requirements WHERE id = ?', [$required[0]]), 'The tick survived the refusal');
            }

            WalkInService::submit($registrar, $id, $required);

            $application = Database::selectOne('SELECT * FROM enrollment_applications WHERE id = ?', [$id]);
            $t->assertEquals(Application::UNDER_REVIEW, $application['application_status'], 'Submitting goes straight on to review: the documents were verified at the counter');
            $t->assertNotNull($application['submitted_at']);
            foreach (['SUBMITTED', 'UNDER_REVIEW'] as $step) {
                $history = Database::selectOne("SELECT * FROM enrollment_status_history WHERE application_id = ? AND new_value = ?", [$id, $step]);
                $t->assertEquals($registrar->id, (int) $history['actor_user_id'], "The registrar is the recorded actor of {$step}");
                $t->assertEquals(User::ROLE_REGISTRAR, $history['actor_role']);
            }
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a submitted walk-in continues through the ordinary registrar review' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $result = WalkInService::register($registrar, walkInInput());
            $id = $result['application_id'];
            WalkInService::submit($registrar, $id, walkInRequiredIds($id));

            $t->assertEquals(Application::UNDER_REVIEW, Database::scalar('SELECT application_status FROM enrollment_applications WHERE id = ?', [$id]), 'Already under review: no Start review click');
            $section = assignSectionForTest($id);
            App\Services\EnrollmentApplicationService::approve($registrar, $id, $section);

            $t->assertEquals(Application::APPROVED, Database::scalar('SELECT application_status FROM enrollment_applications WHERE id = ?', [$id]));
            $t->assertNotNull(Database::scalar('SELECT id FROM fee_assessments WHERE application_id = ?', [$id]), 'The fee assessment is issued');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the registrar can act on a walk-in draft but never on a student\'s own draft' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $student = makeStudent();
            $own = App\Services\EnrollmentApplicationService::createDraft($student, applicationInput());

            $t->assertEquals(403, statusOf(fn () => Status::transition($own, Application::SUBMITTED, $registrar)), 'Submitting a student\'s draft is refused');
            $t->assertEquals(403, statusOf(fn () => Status::transition($own, Application::CANCELLED, $registrar)), 'Cancelling a student\'s draft is refused');
            $t->assertEquals(404, statusOf(fn () => WalkInService::cancel($registrar, $own)), 'It is not a walk-in at all');
            $t->assertEquals(404, statusOf(fn () => WalkInService::recordDocuments($registrar, $own, [])));
            $t->assertEquals(Application::DRAFT, Database::scalar('SELECT application_status FROM enrollment_applications WHERE id = ?', [$own]));

            $walkIn = WalkInService::register($registrar, walkInInput());
            WalkInService::cancel($registrar, $walkIn['application_id']);
            $t->assertEquals(Application::CANCELLED, Database::scalar('SELECT application_status FROM enrollment_applications WHERE id = ?', [$walkIn['application_id']]));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'once submitted, a walk-in is no longer editable through the walk-in pages' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $id = WalkInService::register($registrar, walkInInput())['application_id'];
            WalkInService::submit($registrar, $id, walkInRequiredIds($id));

            $t->assertEquals(404, statusOf(fn () => WalkInService::recordDocuments($registrar, $id, [])));
            $t->assertEquals(404, statusOf(fn () => WalkInService::cancel($registrar, $id)));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a lost set-up link can be re-issued after the walk-in was submitted, but never for an online applicant' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $first = WalkInService::register($registrar, walkInInput());
            $id = $first['application_id'];
            WalkInService::submit($registrar, $id, walkInRequiredIds($id));

            $fresh = WalkInService::reissueLink($registrar, $id);
            $t->assertNull(App\Services\PasswordResetService::find($first['token']), 'The lost link no longer works');
            $t->assertNotNull(App\Services\PasswordResetService::find($fresh['token']), 'The new one does');
            App\Services\PasswordResetService::complete($fresh['token'], 'Walk1nPassw0rd!x', 'Walk1nPassw0rd!x');

            $student = makeStudent();
            $online = App\Services\EnrollmentApplicationService::createDraft($student, applicationInput());
            $t->assertEquals(404, statusOf(fn () => WalkInService::reissueLink($registrar, $online)), 'An online applicant is not the registrar\'s to issue links for');

            $other = makeUser(User::ROLE_CASHIER);
            $t->assertEquals(403, statusOf(fn () => WalkInService::reissueLink($other, $id)));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a new set-up link replaces the old one, and only while the account is pending' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $result = WalkInService::register($registrar, walkInInput());
            $id = $result['application_id'];

            $fresh = WalkInService::reissueLink($registrar, $id);
            $t->assertNull(App\Services\PasswordResetService::find($result['token']), 'The first link no longer works');
            $t->assertNotNull(App\Services\PasswordResetService::find($fresh['token']), 'The new one does');

            App\Services\PasswordResetService::complete($fresh['token'], 'Walk1nPassw0rd!x', 'Walk1nPassw0rd!x');
            $t->assertThrows(fn () => WalkInService::reissueLink($registrar, $id), 'already set a password', 'No link once a password is set');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the audit log records the walk-in without personal data' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $input = walkInInput(['last_name' => 'Zzyzx']);
            $id = WalkInService::register($registrar, $input)['application_id'];
            WalkInService::recordDocuments($registrar, $id, [walkInRequiredIds($id)[0]]);

            $registered = Database::selectOne("SELECT * FROM audit_logs WHERE action = 'walkin.registered' AND target_id = ?", [$id]);
            $t->assertNotNull($registered, 'walkin.registered is logged');
            $t->assertEquals($registrar->id, (int) $registered['user_id']);
            $t->assertNotNull(Database::scalar("SELECT id FROM audit_logs WHERE action = 'walkin.document_received' AND user_id = ? LIMIT 1", [$registrar->id]));

            $dump = json_encode(Database::select("SELECT new_values, old_values, remarks FROM audit_logs WHERE action LIKE 'walkin.%' AND user_id = ?", [$registrar->id]));
            $t->assertFalse(str_contains((string) $dump, 'Zzyzx'), 'No name in the log');
            $t->assertFalse(str_contains((string) $dump, strtolower($input['email'])), 'No email in the log');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'only the registrar holds the walk-in permission, and every walk-in route requires it' => function (TestRunner $t): void {
        $holders = Database::select(
            "SELECT r.name FROM roles r
               JOIN role_permissions rp ON rp.role_id = r.id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE p.`key` = 'enrollment.walkin'"
        );
        $t->assertEquals([User::ROLE_REGISTRAR], array_column($holders, 'name'));

        $router = new Router();
        require BASE_PATH . '/routes/web.php';
        $found = 0;
        foreach ($router->routes() as $route) {
            if (str_starts_with($route['pattern'], '/registrar/walk-in')) {
                $found++;
                $t->assertTrue(in_array('can:enrollment.walkin', $route['middleware'], true), $route['pattern'] . ' needs the permission');
                $t->assertTrue(in_array('role:' . User::ROLE_REGISTRAR, $route['middleware'], true), $route['pattern'] . ' needs the Registrar role');
            }
        }
        $t->assertTrue($found >= 7, 'The walk-in routes are registered');
    },

    'every mistake on the intake form is reported in one pass, with the guardian fields flagged and registrar wording' => function (TestRunner $t): void {
        try {
            $errors = WalkInService::registerErrors([]);
            foreach (['first_name', 'last_name', 'email', 'privacy_consent', 'birthdate', 'sex', 'civil_status', 'mobile', 'barangay', 'city_municipality', 'province',
                      'guardians', 'guardians.0.full_name', 'guardians.0.relationship', 'guardians.0.mobile', 'term_id', 'student_type', 'year_level_id', 'program_id'] as $field) {
                $t->assertTrue(isset($errors[$field]), "{$field} is reported on the first submit");
            }
            $t->assertContains('the applicant\'s emergency contact', $errors['guardians'][0], 'Not "your" emergency contact');

            $registrar = makeUser(User::ROLE_REGISTRAR);
            $threw = null;
            try {
                WalkInService::register($registrar, []);
            } catch (ValidationException $e) {
                $threw = $e->errors();
            }
            $t->assertEquals(array_keys($errors), array_keys($threw ?? []), 'register() throws the same full list, and creates nothing');
            $t->assertEquals(1, (int) Database::scalar('SELECT COUNT(*) FROM users WHERE username LIKE ?', [testPrefixPattern()]), 'Only the registrar fixture exists: nothing was created');

            $partial = array_keys(WalkInService::registerErrors(walkInInput(['mobile' => '', 'barangay' => ''])));
            sort($partial);
            $t->assertEquals(['barangay', 'mobile'], $partial, 'Only what is wrong is reported');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a program, year level, and term with no section is refused at intake instead of leaving a draft that cannot be approved' => function (TestRunner $t): void {
        try {
            $input = walkInInput();
            $withoutSection = null;
            foreach (App\Models\Program::active() as $program) {
                foreach (Catalog::yearLevels() as $year) {
                    $candidate = ['term_id' => $input['term_id'], 'program_id' => (int) $program['id'], 'year_level_id' => (int) $year['id']];
                    if (Catalog::sectionsForApplication($candidate) === []) {
                        $withoutSection = $candidate;
                        break 2;
                    }
                }
            }
            if ($withoutSection === null) {
                $t->assertTrue(true, 'Every program and year already has a section here: nothing to refuse');
                return;
            }

            $errors = WalkInService::registerErrors(array_merge($input, $withoutSection));
            $t->assertTrue(isset($errors['program_id']), 'The program field carries the problem');
            $t->assertContains('No section exists', $errors['program_id'][0]);
            $t->assertContains('Sections', $errors['program_id'][0], 'It says where to fix it');
            $t->assertEquals([], array_diff_key($errors, ['program_id' => 1]), 'Nothing else is wrong');

            $t->assertTrue(WalkInService::registerErrors($input) === [], 'A combination with a section is accepted');
        } finally {
            actingAs(null);
            deleteTestUsers();
            deleteTestCatalog();
        }
    },

    'a name and date of birth already registered is flagged, and the registrar can say it is a different person' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $first = walkInInput(['first_name' => 'Zeta', 'last_name' => 'Duplicate', 'birthdate' => '2004-02-03']);
            WalkInService::register($registrar, $first);

            $again = walkInInput(['first_name' => 'zeta', 'last_name' => 'DUPLICATE', 'birthdate' => '2004-02-03']);
            $errors = WalkInService::registerErrors($again);
            $t->assertTrue(isset($errors['duplicate']), 'The same name and birthdate, in any case, is flagged');
            $t->assertContains('Find an existing student', $errors['duplicate'][0]);

            $t->assertTrue(WalkInService::registerErrors($again + ['confirm_new' => '1']) === [], 'Ticking "different person" lets it through');
            $t->assertTrue(WalkInService::registerErrors(array_merge($again, ['birthdate' => '2004-02-04'])) === [], 'A different birthdate is not a match');
            $t->assertEquals(1, count(WalkInService::possibleDuplicates('Zeta', 'Duplicate', '2004-02-03')));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a registered student is enrolled again on their own account: no new account, no link' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $result = WalkInService::register($registrar, walkInInput());
            $studentId = (int) Database::scalar('SELECT student_id FROM enrollment_applications WHERE id = ?', [$result['application_id']]);
            $input = applicationInput('Old');
            ensureIntakeSection($input);
            $users = (int) Database::scalar('SELECT COUNT(*) FROM users');

            // While the first application is open, a second for the same term is refused.
            $errors = fieldErrors(static fn () => WalkInService::registerExisting($registrar, $studentId, $input));
            $t->assertTrue(isset($errors['term_id']), 'One open application per term');

            WalkInService::cancel($registrar, $result['application_id']);
            $second = WalkInService::registerExisting($registrar, $studentId, $input);

            $application = Database::selectOne('SELECT * FROM enrollment_applications WHERE id = ?', [$second]);
            $t->assertEquals($studentId, (int) $application['student_id'], 'On the existing student');
            $t->assertEquals('walk_in', $application['source']);
            $t->assertEquals(Application::DRAFT, $application['application_status']);
            $t->assertEquals('Old', $application['student_type']);
            $t->assertEquals($users, (int) Database::scalar('SELECT COUNT(*) FROM users'), 'No new account was made');
            $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'walkin.registered' AND target_id = ?", [$second]), 'Audited');
            $t->assertEquals([$second], array_map('intval', array_column(array_filter(WalkInService::unfinished(), static fn ($r) => (int) $r['id'] === $second), 'id')), 'It appears under Walk-ins not yet submitted');

            $bad = fieldErrors(static fn () => WalkInService::registerExisting($registrar, $studentId, ['term_id' => '', 'program_id' => '', 'year_level_id' => '', 'student_type' => '']));
            foreach (['term_id', 'program_id', 'year_level_id', 'student_type'] as $field) {
                $t->assertTrue(isset($bad[$field]), "{$field} is required");
            }
            $t->assertEquals(404, statusOf(static fn () => WalkInService::registerExisting($registrar, 999999999, $input)), 'An unknown student');
            $t->assertEquals(403, statusOf(static fn () => WalkInService::registerExisting(makeUser(User::ROLE_CASHIER), $studentId, $input)), 'Only the registrar');

            Database::update('users', ['status' => User::STATUS_DISABLED], ['id' => (int) Database::scalar('SELECT user_id FROM students WHERE id = ?', [$studentId])]);
            $t->assertThrows(fn () => WalkInService::registerExisting($registrar, $studentId, $input), 'account is disabled', 'A disabled account is refused');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'registered students are found by name, student number, or email, and nothing odd matches everyone' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $result = WalkInService::register($registrar, walkInInput(['first_name' => 'Findable', 'last_name' => 'Personxyz']));
            $studentId = (int) Database::scalar('SELECT student_id FROM enrollment_applications WHERE id = ?', [$result['application_id']]);
            $ids = static fn (string $q): array => array_map('intval', array_column(WalkInService::findStudents($q), 'id'));

            $t->assertTrue(in_array($studentId, $ids('Personxyz'), true), 'By last name');
            $t->assertTrue(in_array($studentId, $ids('findable person'), true), 'By first and last name');
            $t->assertTrue(in_array($studentId, $ids('Personxyz, Findable'), true), 'Last name first');
            $t->assertTrue(in_array($studentId, $ids(substr(User::find($result['user_id'])->email, 0, 12)), true), 'By email');
            $t->assertEquals([], $ids('P'), 'One character matches nobody');
            $t->assertEquals([], $ids('%'), 'A wildcard is just a character, not "everyone"');
            $t->assertEquals([], $ids('_____'), 'And so is an underscore');
            $t->assertTrue(count($ids('Personxyz')) <= 10, 'At most ten results');

            Database::update('users', ['status' => User::STATUS_DISABLED], ['id' => $result['user_id']]);
            $t->assertFalse(in_array($studentId, $ids('Personxyz'), true), 'A disabled account is not offered');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the registrar who submits a walk-in is not alerted about their own entry, but the other registrars are' => function (TestRunner $t): void {
        try {
            $mine = makeUser(User::ROLE_REGISTRAR);
            $other = makeUser(User::ROLE_REGISTRAR);
            $result = WalkInService::register($mine, walkInInput());
            WalkInService::submit($mine, $result['application_id'], walkInRequiredIds($result['application_id']));

            $alerts = static fn (User $user): int => (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'enrollment.submitted'", [$user->id]);
            $t->assertEquals(0, $alerts($mine), 'Not the registrar who entered it');
            $t->assertEquals(1, $alerts($other), 'Another registrar is told');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the applicant\'s photo is saved at the counter as their profile photo, replaced cleanly, and refused when it is not a real image' => function (TestRunner $t): void {
        $paths = [];
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $result = WalkInService::register($registrar, walkInInput());
            $id = $result['application_id'];
            $studentId = (int) Database::scalar('SELECT student_id FROM enrollment_applications WHERE id = ?', [$id]);
            $photoPath = static fn () => (string) Database::scalar('SELECT photo_path FROM student_profiles WHERE student_id = ?', [$studentId]);

            $t->assertFalse(WalkInService::savePhoto($registrar, $id, null), 'No photo sent');
            $t->assertFalse(WalkInService::savePhoto($registrar, $id, UploadedFile::forTesting(fixtureFile('png'), 'x.png', UPLOAD_ERR_NO_FILE)), 'An empty file field');

            $errors = fieldErrors(static fn () => WalkInService::savePhoto($registrar, $id, UploadedFile::forTesting(fixtureFile('fake_pdf'), 'me.png')));
            $t->assertTrue(isset($errors['photo']), 'A text file renamed .png is refused');
            $t->assertEquals('', $photoPath(), 'Nothing was saved');

            $t->assertTrue(WalkInService::savePhoto($registrar, $id, UploadedFile::forTesting(fixtureFile('png'), 'me.png')), 'A real image is saved');
            $first = $photoPath();
            $paths[] = $first;
            $t->assertTrue(str_starts_with($first, 'uploads/profile-photos/') && is_file(STORAGE_PATH . '/' . $first), 'As the profile photo, under storage/uploads/profile-photos');

            WalkInService::savePhoto($registrar, $id, UploadedFile::forTesting(fixtureFile('png'), 'again.png'));
            $second = $photoPath();
            $paths[] = $second;
            $t->assertTrue($second !== $first && !is_file(STORAGE_PATH . '/' . $first), 'A new photo replaces the old file');
            $t->assertEquals(2, (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'walkin.photo_saved' AND target_id = ?", [$id]), 'Each save is audited');

            WalkInService::submit($registrar, $id, walkInRequiredIds($id));
            $t->assertEquals(404, statusOf(static fn () => WalkInService::savePhoto($registrar, $id, UploadedFile::forTesting(fixtureFile('png'), 'late.png'))), 'Not once the walk-in is submitted');
            $t->assertEquals(403, statusOf(static fn () => WalkInService::savePhoto(makeUser(User::ROLE_CASHIER), $id, UploadedFile::forTesting(fixtureFile('png'), 'x.png'))), 'Only the registrar');
        } finally {
            actingAs(null);
            deleteTestUsers();
            foreach ($paths as $path) {
                @unlink(STORAGE_PATH . '/' . $path);
            }
        }
    },

    'the set-up link is shown on its own page after a redirect, never as the reply to a form post' => function (TestRunner $t): void {
        $controller = (string) file_get_contents(BASE_PATH . '/app/Controllers/Registrar/WalkInController.php');
        $t->assertFalse(str_contains($controller, 'linkView'), 'No action renders the link as its own response any more');
        $t->assertContains('private function showLinkNext', $controller);
        $t->assertContains('Session::forget(self::LINK_KEY)', $controller, 'The held link is read once and then gone');
        $t->assertContains('The set-up link is shown only once', $controller, 'Opening the page again explains it');
        $t->assertContains('header(\'Cache-Control: no-store\')', $controller, 'And the page is never cached');

        $router = new Router();
        require BASE_PATH . '/routes/web.php';
        $found = [];
        foreach ($router->routes() as $route) {
            if (in_array($route['pattern'], ['/registrar/walk-in/{id}/link', '/registrar/walk-in/student/{studentId}'], true)) {
                $found[] = $route['method'] . ' ' . $route['pattern'];
                $t->assertTrue(in_array('can:enrollment.walkin', $route['middleware'], true), $route['pattern'] . ' needs enrollment.walkin');
            }
        }
        $t->assertEquals(4, count($found), 'The link page (GET) with "issue a new link" (POST), and the existing-student page (GET) with its form (POST)');
    },
];
