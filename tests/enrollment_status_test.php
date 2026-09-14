<?php
/**
 * The enrollment status machine (docs/26).
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\HttpException;
use App\Core\UploadedFile;
use App\Core\ValidationException;
use App\Models\EnrollmentApplication as Application;
use App\Models\User;
use App\Services\DocumentUploadService;
use App\Services\EnrollmentApplicationService;
use App\Services\EnrollmentStatusService as Status;

/** Put an application directly into a state, bypassing the machine (fixture setup only). */
function forceStatus(int $applicationId, string $status): void
{
    Database::update('enrollment_applications', ['application_status' => $status], ['id' => $applicationId]);
}

function historyCount(int $applicationId): int
{
    return (int) Database::scalar('SELECT COUNT(*) FROM enrollment_status_history WHERE application_id = ?', [$applicationId]);
}

function auditCount(int $applicationId): int
{
    return (int) Database::scalar(
        "SELECT COUNT(*) FROM audit_logs WHERE action = 'enrollment.status_changed' AND target_id = ?",
        [$applicationId]
    );
}

/** @return array{0:User,1:User,2:int} student, registrar, application id */
function statusFixture(): array
{
    $student = makeStudent();
    $applicationId = EnrollmentApplicationService::createDraft($student, applicationInput());
    $registrar = makeUser(User::ROLE_REGISTRAR);
    return [$student, $registrar, $applicationId];
}

return [
    'the matrix contains exactly the transitions in docs/26' => function (TestRunner $t): void {
        $expected = [
            'DRAFT>SUBMITTED', 'SUBMITTED>UNDER_REVIEW', 'UNDER_REVIEW>REQUIREMENTS_INCOMPLETE',
            'UNDER_REVIEW>CORRECTION_REQUIRED', 'REQUIREMENTS_INCOMPLETE>SUBMITTED', 'CORRECTION_REQUIRED>SUBMITTED',
            'UNDER_REVIEW>APPROVED', 'UNDER_REVIEW>REJECTED', 'DRAFT>CANCELLED', 'SUBMITTED>CANCELLED',
            'UNDER_REVIEW>CANCELLED', 'REQUIREMENTS_INCOMPLETE>CANCELLED', 'CORRECTION_REQUIRED>CANCELLED',
            'APPROVED>CANCELLED',
        ];
        $actual = array_keys(Status::rules());
        sort($expected);
        sort($actual);
        $t->assertEquals($expected, $actual);
    },

    'a student submits a complete draft, and it writes one history and one audit row' => function (TestRunner $t): void {
        try {
            [$student, , $id] = statusFixture();
            uploadAllRequired($student, $id);

            $historyBefore = historyCount($id);
            $auditBefore = auditCount($id);

            $row = Status::transition($id, Application::SUBMITTED, $student);

            $t->assertEquals(Application::SUBMITTED, $row['application_status']);
            $t->assertNotNull($row['submitted_at']);
            $t->assertEquals($historyBefore + 1, historyCount($id), 'Exactly one history row');
            $t->assertEquals($auditBefore + 1, auditCount($id), 'Exactly one audit row');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'undefined transitions are refused and change nothing' => function (TestRunner $t): void {
        try {
            [, $registrar, $id] = statusFixture();

            foreach ([Application::APPROVED, Application::UNDER_REVIEW, Application::REJECTED, Application::CORRECTION_REQUIRED] as $target) {
                $before = historyCount($id);
                $t->assertThrows(static fn () => Status::transition($id, $target, $registrar, 'reason'), null, "DRAFT>{$target} must be refused");
                $t->assertEquals(Application::DRAFT, Database::scalar('SELECT application_status FROM enrollment_applications WHERE id = ?', [$id]));
                $t->assertEquals($before, historyCount($id), 'A refused transition writes no history');
            }
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a student cannot perform a registrar transition' => function (TestRunner $t): void {
        try {
            [$student, , $id] = statusFixture();
            forceStatus($id, Application::UNDER_REVIEW);

            $status = null;
            try {
                Status::transition($id, Application::APPROVED, $student);
            } catch (HttpException $e) {
                $status = $e->status();
            }
            $t->assertEquals(403, $status, 'A student approving their own application must be forbidden');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'Super Admin is not a substitute registrar' => function (TestRunner $t): void {
        try {
            [, , $id] = statusFixture();
            forceStatus($id, Application::SUBMITTED);
            $admin = makeUser(User::ROLE_SUPER_ADMIN);

            $status = null;
            try {
                Status::transition($id, Application::UNDER_REVIEW, $admin);
            } catch (HttpException $e) {
                $status = $e->status();
            }
            $t->assertEquals(403, $status, 'docs/04: Super Admin may not bypass the transition rules');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a student cannot move another student\'s application' => function (TestRunner $t): void {
        try {
            [, , $id] = statusFixture();
            $intruder = makeStudent();

            $status = null;
            try {
                Status::transition($id, Application::CANCELLED, $intruder);
            } catch (HttpException $e) {
                $status = $e->status();
            }
            $t->assertEquals(404, $status, 'Someone else\'s application must look like it does not exist');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'rejecting without remarks is refused' => function (TestRunner $t): void {
        try {
            [, $registrar, $id] = statusFixture();
            forceStatus($id, Application::UNDER_REVIEW);

            $caught = null;
            try {
                Status::transition($id, Application::REJECTED, $registrar, '   ');
            } catch (ValidationException $e) {
                $caught = $e;
            }
            $t->assertNotNull($caught, 'Rule 9 requires remarks');
            $t->assertTrue(isset($caught->errors()['remarks']));

            $row = Status::transition($id, Application::REJECTED, $registrar, 'Documents do not match the applicant.');
            $t->assertEquals(Application::REJECTED, $row['application_status']);
            $t->assertNotNull($row['decided_at']);
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'approval requires every required document to be verified' => function (TestRunner $t): void {
        try {
            [$student, $registrar, $id] = statusFixture();
            uploadAllRequired($student, $id);
            forceStatus($id, Application::UNDER_REVIEW);

            $t->assertThrows(static fn () => Status::transition($id, Application::APPROVED, $registrar), 'must be verified');

            Database::query("UPDATE enrollment_requirements SET status = 'VERIFIED' WHERE application_id = ? AND is_required = 1", [$id]);
            $row = Status::transition($id, Application::APPROVED, $registrar);
            $t->assertEquals(Application::APPROVED, $row['application_status']);
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'terminal states can never be left' => function (TestRunner $t): void {
        try {
            [$student, $registrar, $id] = statusFixture();
            Status::transition($id, Application::CANCELLED, $student);

            foreach ([Application::DRAFT, Application::SUBMITTED, Application::UNDER_REVIEW] as $target) {
                $t->assertThrows(
                    static fn () => Status::transition($id, $target, $target === Application::UNDER_REVIEW ? $registrar : $student),
                    'can no longer be changed'
                );
            }
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the registrar can run the full review cycle through to approval' => function (TestRunner $t): void {
        try {
            [$student, $registrar, $id] = statusFixture();
            uploadAllRequired($student, $id);

            Status::transition($id, Application::SUBMITTED, $student);
            Status::transition($id, Application::UNDER_REVIEW, $registrar);

            // Returned for a document problem; the student fixes it and resubmits.
            $requirementId = (int) Database::scalar('SELECT id FROM enrollment_requirements WHERE application_id = ? AND is_required = 1 LIMIT 1', [$id]);
            Database::update('enrollment_requirements', ['status' => 'REJECTED', 'remarks' => 'Blurry'], ['id' => $requirementId]);
            Status::transition($id, Application::REQUIREMENTS_INCOMPLETE, $registrar, 'Birth certificate is unreadable.');

            DocumentUploadService::upload($student, $id, $requirementId, UploadedFile::forTesting(fixtureFile('pdf'), 'clear.pdf'));
            Status::transition($id, Application::SUBMITTED, $student);
            Status::transition($id, Application::UNDER_REVIEW, $registrar);

            Database::query("UPDATE enrollment_requirements SET status = 'VERIFIED' WHERE application_id = ? AND is_required = 1", [$id]);
            $row = Status::transition($id, Application::APPROVED, $registrar);

            $t->assertEquals(Application::APPROVED, $row['application_status']);
            $t->assertEquals('NOT_ASSESSED', $row['payment_status'], 'payment_status is derived and is never set by this machine');

            // none->DRAFT, ->SUBMITTED, ->UNDER_REVIEW, ->REQUIREMENTS_INCOMPLETE, ->SUBMITTED, ->UNDER_REVIEW, ->APPROVED
            $t->assertEquals(7, historyCount($id), 'Every step is in the history');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },
];
