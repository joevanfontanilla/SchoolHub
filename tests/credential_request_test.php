<?php
/**
 * Student document requests and the registrar's processing of them (docs/08).
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Models\CredentialRequest;
use App\Models\User;
use App\Services\CredentialRequestService;

/** @return array{0:User,1:int,2:User} student, student id, registrar */
function credentialFixture(): array
{
    $student = makeStudent();
    $studentId = (int) Database::scalar('SELECT id FROM students WHERE user_id = ?', [$student->id]);
    $registrar = makeUser(User::ROLE_REGISTRAR);
    return [$student, $studentId, $registrar];
}

return [
    'a student requests a document, and cannot open two of the same kind at once' => function (TestRunner $t): void {
        try {
            [$student, $studentId] = credentialFixture();
            CredentialRequestService::request($student, ['type' => 'TRANSCRIPT', 'purpose' => 'For transfer', 'copies' => 2]);

            $mine = CredentialRequest::forStudent($studentId);
            $t->assertEquals(1, count($mine), 'One request');
            $t->assertEquals('REQUESTED', $mine[0]['status']);
            $t->assertEquals(2, (int) $mine[0]['copies']);

            $t->assertTrue(isset(fieldErrors(static fn () => CredentialRequestService::request($student, ['type' => 'TRANSCRIPT']))['type']), 'A second open TOR is refused');
            $t->assertTrue(isset(fieldErrors(static fn () => CredentialRequestService::request($student, ['type' => 'NOPE']))['type']), 'An unknown type is refused');
            $t->assertTrue(isset(fieldErrors(static fn () => CredentialRequestService::request($student, ['type' => 'GOOD_MORAL', 'copies' => 99]))['copies']), 'Too many copies is refused');

            // A different document is allowed.
            CredentialRequestService::request($student, ['type' => 'GOOD_MORAL']);
            $t->assertEquals(2, count(CredentialRequest::forStudent($studentId)), 'A different document is a separate request');
        } finally {
            deleteTestUsers();
        }
    },

    'the registrar advances a request through its statuses, notifying the student each time' => function (TestRunner $t): void {
        try {
            [$student, $studentId, $registrar] = credentialFixture();
            CredentialRequestService::request($student, ['type' => 'ENROLLMENT']);
            $id = (int) CredentialRequest::forStudent($studentId)[0]['id'];

            CredentialRequestService::process($registrar, $id, ['status' => 'PROCESSING']);
            CredentialRequestService::process($registrar, $id, ['status' => 'READY', 'fee_amount' => '50']);
            $ready = CredentialRequest::find($id);
            $t->assertEquals('READY', $ready['status']);
            $t->assertEquals('50.00', $ready['fee_amount']);

            CredentialRequestService::process($registrar, $id, ['status' => 'RELEASED']);
            $released = CredentialRequest::find($id);
            $t->assertEquals('RELEASED', $released['status']);
            $t->assertTrue($released['released_at'] !== null, 'The release time is stamped');

            // The student got a notification for each step (processing, ready, released).
            $t->assertTrue((int) Database::scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type LIKE ?', [$student->id, 'credential.%']) >= 3, 'The student was notified at each step');
        } finally {
            deleteTestUsers();
        }
    },

    'a skipped or backward status change is refused, and rejecting needs a reason' => function (TestRunner $t): void {
        try {
            [$student, $studentId, $registrar] = credentialFixture();
            CredentialRequestService::request($student, ['type' => 'GRADES']);
            $id = (int) CredentialRequest::forStudent($studentId)[0]['id'];

            // From REQUESTED you cannot jump straight to RELEASED.
            $t->assertThrows(static fn () => CredentialRequestService::process($registrar, $id, ['status' => 'RELEASED']), null, 'No skipping to released');
            // Rejecting requires a reason.
            $t->assertTrue(isset(fieldErrors(static fn () => CredentialRequestService::process($registrar, $id, ['status' => 'REJECTED']))['remarks']), 'Reject needs a reason');

            CredentialRequestService::process($registrar, $id, ['status' => 'REJECTED', 'remarks' => 'Clear your balance first.']);
            $t->assertEquals('REJECTED', CredentialRequest::find($id)['status']);
            // A terminal request cannot be advanced further.
            $t->assertThrows(static fn () => CredentialRequestService::process($registrar, $id, ['status' => 'PROCESSING']), null, 'A rejected request is closed');
        } finally {
            deleteTestUsers();
        }
    },

    'permissions: a non-student cannot request, a non-registrar cannot process, and cancel is owner-only and pending-only' => function (TestRunner $t): void {
        try {
            [$student, $studentId, $registrar] = credentialFixture();
            $cashier = makeUser(User::ROLE_CASHIER);

            $t->assertEquals(403, statusOf(static fn () => CredentialRequestService::request($cashier, ['type' => 'TRANSCRIPT'])), 'A cashier cannot request');

            CredentialRequestService::request($student, ['type' => 'TRANSCRIPT']);
            $id = (int) CredentialRequest::forStudent($studentId)[0]['id'];
            $t->assertEquals(403, statusOf(static fn () => CredentialRequestService::process($cashier, $id, ['status' => 'PROCESSING'])), 'A cashier cannot process');

            // Another student cannot cancel this one.
            $other = makeStudent();
            $t->assertEquals(404, statusOf(static fn () => CredentialRequestService::cancel($other, $id)), "Another student cannot touch it");

            // The owner can cancel while REQUESTED, but not after processing starts.
            CredentialRequestService::cancel($student, $id);
            $t->assertEquals('CANCELLED', CredentialRequest::find($id)['status']);

            CredentialRequestService::request($student, ['type' => 'GOOD_MORAL']);
            $id2 = (int) CredentialRequest::forStudent($studentId)[0]['id'];
            CredentialRequestService::process($registrar, $id2, ['status' => 'PROCESSING']);
            $t->assertThrows(static fn () => CredentialRequestService::cancel($student, $id2), null, 'Cannot cancel once processing');
        } finally {
            deleteTestUsers();
        }
    },
];
