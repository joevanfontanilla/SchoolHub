<?php
/**
 * The registrar's review of individual documents (docs/10, docs/11).
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Models\User;
use App\Services\EnrollmentStatusService;
use App\Services\RequirementReviewService as Review;

/** @return array{0:User,1:User,2:int,3:int} student, registrar, application id, a required requirement id */
function reviewFixture(bool $startReview = true): array
{
    [$student, $id] = submittedApplication();
    $registrar = makeUser(User::ROLE_REGISTRAR);
    if ($startReview) {
        EnrollmentStatusService::transition($id, 'UNDER_REVIEW', $registrar);
    }
    $requirementId = (int) Database::scalar(
        'SELECT id FROM enrollment_requirements WHERE application_id = ? AND is_required = 1 ORDER BY id LIMIT 1',
        [$id]
    );
    return [$student, $registrar, $id, $requirementId];
}

function requirementRow(int $id): array
{
    return Database::selectOne('SELECT * FROM enrollment_requirements WHERE id = ?', [$id]);
}

return [
    'starting the review marks submitted documents as under review' => function (TestRunner $t): void {
        try {
            [, , $id] = reviewFixture();
            $statuses = array_unique(array_column(
                Database::select("SELECT status FROM enrollment_requirements WHERE application_id = ? AND is_required = 1", [$id]),
                'status'
            ));
            $t->assertEquals(['UNDER_REVIEW'], array_values($statuses));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the registrar verifies a document, recording who and when' => function (TestRunner $t): void {
        try {
            [, $registrar, , $requirementId] = reviewFixture();
            Review::review($registrar, $requirementId, 'VERIFIED', null);

            $row = requirementRow($requirementId);
            $t->assertEquals('VERIFIED', $row['status']);
            $t->assertEquals($registrar->id, (int) $row['verified_by']);
            $t->assertNotNull($row['verified_at']);
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'rejecting a document requires a note and notifies the student' => function (TestRunner $t): void {
        try {
            [$student, $registrar, , $requirementId] = reviewFixture();

            $caught = null;
            try {
                Review::review($registrar, $requirementId, 'REJECTED', '  ');
            } catch (ValidationException $e) {
                $caught = $e;
            }
            $t->assertNotNull($caught, 'A rejection without a note must be refused');
            $t->assertTrue(isset($caught->errors()['remarks']));

            Review::review($registrar, $requirementId, 'REJECTED', 'The scan is too blurry to read.');
            $row = requirementRow($requirementId);
            $t->assertEquals('REJECTED', $row['status']);
            $t->assertEquals('The scan is too blurry to read.', $row['remarks']);

            $notified = (int) Database::scalar(
                "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'requirement.rejected'",
                [$student->id]
            );
            $t->assertEquals(1, $notified, 'The student is notified of the rejection');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a decision can be changed while the review is open' => function (TestRunner $t): void {
        try {
            [, $registrar, , $requirementId] = reviewFixture();
            Review::review($registrar, $requirementId, 'REJECTED', 'Wrong file.');
            Review::review($registrar, $requirementId, 'VERIFIED', null);

            $row = requirementRow($requirementId);
            $t->assertEquals('VERIFIED', $row['status']);
            $t->assertNull($row['remarks'], 'Verifying clears the earlier note');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'documents cannot be reviewed before the review has started' => function (TestRunner $t): void {
        try {
            [, $registrar, , $requirementId] = reviewFixture(false);
            $t->assertThrows(static fn () => Review::review($registrar, $requirementId, 'VERIFIED', null), 'only while the application is under review');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a document with no file cannot be reviewed' => function (TestRunner $t): void {
        try {
            [, $registrar, $id] = reviewFixture();
            $optional = (int) Database::scalar(
                "SELECT id FROM enrollment_requirements WHERE application_id = ? AND is_required = 0 AND status = 'NOT_SUBMITTED' LIMIT 1",
                [$id]
            );
            $t->assertTrue($optional > 0, 'Fixture has an optional document with nothing uploaded');
            $t->assertThrows(static fn () => Review::review($registrar, $optional, 'VERIFIED', null), 'No file has been uploaded');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'only the registrar may review documents' => function (TestRunner $t): void {
        try {
            [$student, , , $requirementId] = reviewFixture();

            foreach ([$student, makeUser(User::ROLE_TEACHER), makeUser(User::ROLE_CASHIER), makeUser(User::ROLE_SUPER_ADMIN)] as $actor) {
                $status = null;
                try {
                    Review::review($actor, $requirementId, 'VERIFIED', null);
                } catch (HttpException $e) {
                    $status = $e->status();
                }
                $t->assertEquals(403, $status, "{$actor->role_name} must not review documents");
            }
            $t->assertEquals('UNDER_REVIEW', requirementRow($requirementId)['status'], 'Nothing changed');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'an unknown decision is refused' => function (TestRunner $t): void {
        try {
            [, $registrar, , $requirementId] = reviewFixture();
            $t->assertThrows(static fn () => Review::review($registrar, $requirementId, 'DELETED', null), 'Choose verify');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },
];
