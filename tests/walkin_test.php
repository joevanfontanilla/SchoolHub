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

/** The intake form a registrar submits for an applicant. */
function walkInInput(array $overrides = []): array
{
    $email = TEST_PREFIX . bin2hex(random_bytes(4)) . '@test.local';

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
                    'required documents',
                    'A missing required document refuses the submission'
                );
                $t->assertEquals(Application::DRAFT, Database::scalar('SELECT application_status FROM enrollment_applications WHERE id = ?', [$id]));
                $t->assertEquals('VERIFIED', Database::scalar('SELECT status FROM enrollment_requirements WHERE id = ?', [$required[0]]), 'The tick survived the refusal');
            }

            WalkInService::submit($registrar, $id, $required);

            $application = Database::selectOne('SELECT * FROM enrollment_applications WHERE id = ?', [$id]);
            $t->assertEquals(Application::SUBMITTED, $application['application_status']);
            $t->assertNotNull($application['submitted_at']);
            $history = Database::selectOne("SELECT * FROM enrollment_status_history WHERE application_id = ? AND new_value = 'SUBMITTED'", [$id]);
            $t->assertEquals($registrar->id, (int) $history['actor_user_id'], 'The registrar is the recorded actor');
            $t->assertEquals(User::ROLE_REGISTRAR, $history['actor_role']);
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

            Status::transition($id, Application::UNDER_REVIEW, $registrar);
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
            $t->assertEquals(404, statusOf(fn () => WalkInService::reissueLink($registrar, $id)));
            $t->assertEquals(404, statusOf(fn () => WalkInService::cancel($registrar, $id)));
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
];
