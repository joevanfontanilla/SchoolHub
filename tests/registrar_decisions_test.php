<?php
/**
 * Registrar decisions on applications, through the status machine (docs/26).
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Models\User;
use App\Services\EnrollmentStatusService as Status;
use App\Services\RequirementReviewService as Review;

function notificationCount(int $userId, string $type): int
{
    return (int) Database::scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = ?', [$userId, $type]);
}

return [
    'submitting notifies the registrar\'s office' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            [, $id] = submittedApplication();

            $count = (int) Database::scalar(
                "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'enrollment.submitted' AND link_url = ?",
                [$registrar->id, "/registrar/applications/{$id}"]
            );
            $t->assertEquals(1, $count, 'Each active registrar gets a new-application notice');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'returning as incomplete needs a rejected document and notifies the student' => function (TestRunner $t): void {
        try {
            [$student, $id] = submittedApplication();
            $registrar = makeUser(User::ROLE_REGISTRAR);
            Status::transition($id, 'UNDER_REVIEW', $registrar);

            $t->assertThrows(
                static fn () => Status::transition($id, 'REQUIREMENTS_INCOMPLETE', $registrar, 'Please fix your documents.'),
                'Mark at least one document'
            );

            $requirementId = (int) Database::scalar('SELECT id FROM enrollment_requirements WHERE application_id = ? AND is_required = 1 LIMIT 1', [$id]);
            Review::review($registrar, $requirementId, 'REJECTED', 'Unreadable.');
            Status::transition($id, 'REQUIREMENTS_INCOMPLETE', $registrar, 'Please fix your documents.');

            $t->assertEquals(1, notificationCount($student->id, 'enrollment.requirements_incomplete'));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'approval needs every required document verified' => function (TestRunner $t): void {
        try {
            [$student, $id] = submittedApplication();
            $registrar = makeUser(User::ROLE_REGISTRAR);
            Status::transition($id, 'UNDER_REVIEW', $registrar);

            $t->assertThrows(static fn () => Status::transition($id, 'APPROVED', $registrar), 'must be verified');

            foreach (App\Models\EnrollmentApplication::requirements($id) as $requirement) {
                if ((int) $requirement['is_required'] === 1) {
                    Review::review($registrar, (int) $requirement['id'], 'VERIFIED', null);
                }
            }
            $row = Status::transition($id, 'APPROVED', $registrar);

            $t->assertEquals('APPROVED', $row['application_status']);
            $t->assertEquals(1, notificationCount($student->id, 'enrollment.approved'));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'rejecting the application notifies the student with the reason' => function (TestRunner $t): void {
        try {
            [$student, $id] = submittedApplication();
            $registrar = makeUser(User::ROLE_REGISTRAR);
            Status::transition($id, 'UNDER_REVIEW', $registrar);
            Status::transition($id, 'REJECTED', $registrar, 'Did not meet the admission requirements.');

            $body = (string) Database::scalar(
                "SELECT body FROM notifications WHERE user_id = ? AND type = 'enrollment.rejected'",
                [$student->id]
            );
            $t->assertEquals('Did not meet the admission requirements.', $body);
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'an approved application can be cancelled only while unpaid' => function (TestRunner $t): void {
        try {
            [, $registrar, $id] = approvedApplication();

            Database::update('enrollment_applications', ['payment_status' => 'PARTIAL'], ['id' => $id]);
            $t->assertThrows(static fn () => Status::transition($id, 'CANCELLED', $registrar, 'Duplicate application.'), 'payment has already been recorded');

            Database::update('enrollment_applications', ['payment_status' => 'PENDING'], ['id' => $id]);
            $row = Status::transition($id, 'CANCELLED', $registrar, 'Duplicate application.');
            $t->assertEquals('CANCELLED', $row['application_status']);
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a student withdrawing after submission notifies the registrar\'s office' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            [$student, $id] = submittedApplication();
            Status::transition($id, 'CANCELLED', $student);

            $count = (int) Database::scalar(
                "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'enrollment.cancelled' AND link_url = ?",
                [$registrar->id, "/registrar/applications/{$id}"]
            );
            $t->assertEquals(1, $count);
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },
];
