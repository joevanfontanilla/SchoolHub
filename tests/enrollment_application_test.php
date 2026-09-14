<?php
/**
 * Creating, editing, submitting, and cancelling an enrollment application (docs/09).
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Models\EnrollmentApplication as Application;
use App\Models\User;
use App\Services\EnrollmentApplicationService as Applications;
use App\Services\StudentProfileService;

/** @return array<int,string> requirement codes on an application */
function requirementCodes(int $applicationId): array
{
    return array_column(Application::requirements($applicationId), 'code');
}

return [
    'a draft is created for an open term with its document list' => function (TestRunner $t): void {
        try {
            $student = makeStudent();
            $id = Applications::createDraft($student, applicationInput('New'));

            $row = Database::selectOne('SELECT * FROM enrollment_applications WHERE id = ?', [$id]);
            $t->assertEquals(Application::DRAFT, $row['application_status']);
            $t->assertEquals('NOT_ASSESSED', $row['payment_status']);
            $t->assertEquals('NOT_ENROLLED', $row['enrollment_state']);
            $t->assertTrue((bool) preg_match('/^APP-\d{4}-\d{5}$/', $row['application_number']), 'Number format APP-YYYY-NNNNN');

            $codes = requirementCodes($id);
            $t->assertTrue(in_array('report_card', $codes, true), 'New students submit a report card');
            $t->assertFalse(in_array('transcript', $codes, true), 'New students are not asked for a transcript');

            $history = Application::history($id);
            $t->assertEquals(1, count($history));
            $t->assertNull($history[0]['previous_value'], 'Creation is recorded as none -> DRAFT');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a transferee is asked for a transcript and transfer credential, not a report card' => function (TestRunner $t): void {
        try {
            $student = makeStudent();
            $id = Applications::createDraft($student, applicationInput('Transferee'));
            $codes = requirementCodes($id);

            $t->assertTrue(in_array('transcript', $codes, true));
            $t->assertTrue(in_array('transfer_credential', $codes, true));
            $t->assertFalse(in_array('report_card', $codes, true));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a transferee must name their previous school' => function (TestRunner $t): void {
        try {
            $student = makeStudent();
            $input = applicationInput('Transferee');
            $input['previous_school'] = '';

            $t->assertThrows(static fn () => Applications::createDraft($student, $input), 'transferring from');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'applying outside the enrollment window is refused' => function (TestRunner $t): void {
        try {
            $student = makeStudent();
            $input = applicationInput();
            $input['term_id'] = closedTermId();

            $t->assertThrows(static fn () => Applications::createDraft($student, $input), 'Please correct');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a second open application for the same term is refused' => function (TestRunner $t): void {
        try {
            $student = makeStudent();
            Applications::createDraft($student, applicationInput());

            $t->assertThrows(static fn () => Applications::createDraft($student, applicationInput()), 'already have an application');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'after cancelling, the student may apply again for the same term' => function (TestRunner $t): void {
        try {
            $student = makeStudent();
            $first = Applications::createDraft($student, applicationInput());
            Applications::cancel($student, $first);

            $second = Applications::createDraft($student, applicationInput());
            $t->assertTrue($second !== $first, 'A new application is created; the cancelled one is kept');
            $t->assertEquals(Application::CANCELLED, Database::scalar('SELECT application_status FROM enrollment_applications WHERE id = ?', [$first]));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'submitting with a required document missing is refused and names it' => function (TestRunner $t): void {
        try {
            $student = makeStudent();
            $id = Applications::createDraft($student, applicationInput());

            $t->assertThrows(static fn () => Applications::submit($student, $id), 'Upload the remaining required documents');
            $t->assertEquals(Application::DRAFT, Database::scalar('SELECT application_status FROM enrollment_applications WHERE id = ?', [$id]));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'submitting with an incomplete profile is refused' => function (TestRunner $t): void {
        try {
            $student = makeStudent();
            $id = Applications::createDraft($student, applicationInput());
            uploadAllRequired($student, $id);

            $studentId = (int) Database::scalar('SELECT id FROM students WHERE user_id = ?', [$student->id]);
            Database::update('student_profiles', ['mobile' => null], ['student_id' => $studentId]);

            $t->assertThrows(static fn () => Applications::submit($student, $id), 'Complete your profile');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'submitting a complete application succeeds and locks editing' => function (TestRunner $t): void {
        try {
            $student = makeStudent();
            $id = Applications::createDraft($student, applicationInput());
            uploadAllRequired($student, $id);

            Applications::submit($student, $id);
            $t->assertEquals(Application::SUBMITTED, Database::scalar('SELECT application_status FROM enrollment_applications WHERE id = ?', [$id]));

            $t->assertThrows(
                static fn () => Applications::updateDraft($student, $id, ['program_id' => 1, 'year_level_id' => 1]),
                'cannot be edited'
            );
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the name is locked once an application is submitted' => function (TestRunner $t): void {
        try {
            $student = makeStudent();
            $studentId = (int) Database::scalar('SELECT id FROM students WHERE user_id = ?', [$student->id]);
            $t->assertFalse(StudentProfileService::nameLocked($studentId));

            $id = Applications::createDraft($student, applicationInput());
            uploadAllRequired($student, $id);
            Applications::submit($student, $id);

            $t->assertTrue(StudentProfileService::nameLocked($studentId));

            $input = completeProfileInput();
            $input['first_name'] = 'Changed';
            StudentProfileService::update($student, $input);
            $t->assertEquals('Test', Database::scalar('SELECT first_name FROM users WHERE id = ?', [$student->id]), 'The name must not change');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'application numbers are sequential and unique' => function (TestRunner $t): void {
        try {
            $a = makeStudent();
            $b = makeStudent();
            $first = (string) Database::scalar('SELECT application_number FROM enrollment_applications WHERE id = ?', [Applications::createDraft($a, applicationInput())]);
            $second = (string) Database::scalar('SELECT application_number FROM enrollment_applications WHERE id = ?', [Applications::createDraft($b, applicationInput())]);

            $n1 = (int) substr($first, -5);
            $n2 = (int) substr($second, -5);
            $t->assertEquals($n1 + 1, $n2, "{$first} should be followed by the next number, got {$second}");
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the profile requires an emergency contact' => function (TestRunner $t): void {
        try {
            $student = makeUser(User::ROLE_STUDENT);
            $input = completeProfileInput();
            $input['guardians'][0]['is_emergency_contact'] = '';

            $t->assertThrows(static fn () => StudentProfileService::update($student, $input), 'Please correct');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },
];
