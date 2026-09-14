<?php
/**
 * Enrollment confirmation: section assignment, student numbers, payment policy (docs/26 section 4).
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\HttpException;
use App\Models\User;
use App\Services\EnrollmentConfirmationService as Confirmation;

function enrollmentCount(int $applicationId): int
{
    return (int) Database::scalar('SELECT COUNT(*) FROM enrollments WHERE application_id = ?', [$applicationId]);
}

function studentNumberOf(User $student): ?string
{
    $value = Database::scalar('SELECT student_number FROM students WHERE user_id = ?', [$student->id]);
    return $value === null ? null : (string) $value;
}

return [
    'with no payment required, confirmation enrolls the student and issues a number' => function (TestRunner $t): void {
        try {
            [$student, $registrar, $id] = approvedApplication();
            $sectionId = makeSectionFor($id);

            $result = withSetting('enrollment_payment_policy', 'NONE', static fn () => Confirmation::confirm($registrar, $id, $sectionId));

            $t->assertTrue((bool) preg_match('/^\d{4}-\d{5}$/', $result['student_number']), 'Student number format YYYY-NNNNN');
            $t->assertTrue($result['number_issued']);
            $t->assertEquals($result['student_number'], studentNumberOf($student));

            $application = Database::selectOne('SELECT * FROM enrollment_applications WHERE id = ?', [$id]);
            $t->assertEquals('ENROLLED', $application['enrollment_state']);
            $t->assertEquals('APPROVED', $application['application_status'], 'The review axis is untouched');

            $studentRow = Database::selectOne('SELECT * FROM students WHERE user_id = ?', [$student->id]);
            $t->assertEquals('Enrolled', $studentRow['status']);
            $t->assertEquals((int) $application['program_id'], (int) $studentRow['program_id']);

            $enrollment = Database::selectOne('SELECT * FROM enrollments WHERE application_id = ?', [$id]);
            $t->assertEquals($sectionId, (int) $enrollment['section_id']);

            $history = Database::selectOne("SELECT * FROM enrollment_status_history WHERE application_id = ? AND axis = 'enrollment'", [$id]);
            $t->assertEquals('ENROLLED', $history['new_value']);

            $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'enrollment.enrolled'", [$student->id]));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'under the default policy, confirmation waits for payment and changes nothing' => function (TestRunner $t): void {
        try {
            [$student, $registrar, $id] = approvedApplication();
            $sectionId = makeSectionFor($id);

            withSetting('enrollment_payment_policy', 'MINIMUM_DOWNPAYMENT', static function () use ($t, $registrar, $id, $sectionId): void {
                $t->assertThrows(static fn () => Confirmation::confirm($registrar, $id, $sectionId), 'Waiting for payment');
            });
            withSetting('enrollment_payment_policy', 'FULL', static function () use ($t, $registrar, $id, $sectionId): void {
                $t->assertThrows(static fn () => Confirmation::confirm($registrar, $id, $sectionId), 'Waiting for payment');
            });

            $t->assertEquals(0, enrollmentCount($id), 'No enrollment row');
            $t->assertNull(studentNumberOf($student), 'No student number issued');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'student numbers are gapless even when a confirmation fails' => function (TestRunner $t): void {
        try {
            [$studentA, $registrar, $idA] = approvedApplication();
            [$studentB, , $idB] = approvedApplication();
            $sectionA = makeSectionFor($idA, 1);

            withSetting('enrollment_payment_policy', 'NONE', static function () use ($t, $registrar, $idA, $idB, $sectionA): void {
                $first = Confirmation::confirm($registrar, $idA, $sectionA);

                // The section is now full: this confirmation must fail without using a number.
                $t->assertThrows(static fn () => Confirmation::confirm($registrar, $idB, $sectionA), 'is full');

                $sectionB = makeSectionFor($idB);
                $second = Confirmation::confirm($registrar, $idB, $sectionB);

                $n1 = (int) substr($first['student_number'], -5);
                $n2 = (int) substr($second['student_number'], -5);
                $t->assertEquals($n1 + 1, $n2, "Expected consecutive numbers, got {$first['student_number']} and {$second['student_number']}");
            });
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a student keeps their number when confirmed again in a later term' => function (TestRunner $t): void {
        try {
            [$student, $registrar, $id] = approvedApplication();

            withSetting('enrollment_payment_policy', 'NONE', static function () use ($t, $student, $registrar, $id): void {
                $first = Confirmation::confirm($registrar, $id, makeSectionFor($id));

                // Simulate a later term: a new approved application for another term.
                $otherTerm = closedTermId();
                $later = Database::insert('enrollment_applications', [
                    'application_number' => 'APP-TEST-' . random_int(10000, 99999),
                    'student_id'         => (int) Database::scalar('SELECT id FROM students WHERE user_id = ?', [$student->id]),
                    'academic_year_id'   => (int) Database::scalar('SELECT academic_year_id FROM terms WHERE id = ?', [$otherTerm]),
                    'term_id'            => $otherTerm,
                    'program_id'         => (int) Database::scalar('SELECT program_id FROM enrollment_applications WHERE id = ?', [$id]),
                    'year_level_id'      => (int) Database::scalar('SELECT year_level_id FROM enrollment_applications WHERE id = ?', [$id]),
                    'student_type'       => 'Continuing',
                    'application_status' => 'APPROVED',
                ]);

                $second = Confirmation::confirm($registrar, $later, makeSectionFor($later));
                $t->assertFalse($second['number_issued'], 'No new number for an existing student');
                $t->assertEquals($first['student_number'], $second['student_number']);
            });
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a section for another program, year, or term is refused' => function (TestRunner $t): void {
        try {
            [, $registrar, $id] = approvedApplication();
            $sectionId = makeSectionFor($id);
            $otherProgram = (int) Database::scalar(
                'SELECT id FROM programs WHERE id <> (SELECT program_id FROM enrollment_applications WHERE id = ?) LIMIT 1',
                [$id]
            );
            Database::update('sections', ['program_id' => $otherProgram], ['id' => $sectionId]);

            withSetting('enrollment_payment_policy', 'NONE', static function () use ($t, $registrar, $id, $sectionId): void {
                $t->assertThrows(static fn () => Confirmation::confirm($registrar, $id, $sectionId), 'is not for this application');
            });
            $t->assertEquals(0, enrollmentCount($id));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'an application that is not approved, or already confirmed, is refused' => function (TestRunner $t): void {
        try {
            [, $id] = submittedApplication();
            $registrar = makeUser(User::ROLE_REGISTRAR);

            withSetting('enrollment_payment_policy', 'NONE', static function () use ($t, $registrar, $id): void {
                $t->assertThrows(static fn () => Confirmation::confirm($registrar, $id, makeSectionFor($id)), 'Only an approved application');
            });

            [, $registrar2, $approved] = approvedApplication();
            withSetting('enrollment_payment_policy', 'NONE', static function () use ($t, $registrar2, $approved): void {
                Confirmation::confirm($registrar2, $approved, makeSectionFor($approved));
                $t->assertThrows(static fn () => Confirmation::confirm($registrar2, $approved, makeSectionFor($approved)), 'already been confirmed');
            });
            $t->assertEquals(1, enrollmentCount($approved), 'Still exactly one enrollment');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'an enrolled application cannot be cancelled' => function (TestRunner $t): void {
        try {
            [, $registrar, $id] = approvedApplication();
            withSetting('enrollment_payment_policy', 'NONE', static fn () => Confirmation::confirm($registrar, $id, makeSectionFor($id)));

            $t->assertThrows(
                static fn () => App\Services\EnrollmentStatusService::transition($id, 'CANCELLED', $registrar, 'Duplicate.'),
                'already enrolled'
            );
            $row = Database::selectOne('SELECT application_status, enrollment_state FROM enrollment_applications WHERE id = ?', [$id]);
            $t->assertEquals('APPROVED', $row['application_status'], 'The application is untouched');
            $t->assertEquals('ENROLLED', $row['enrollment_state']);
            $t->assertEquals(1, enrollmentCount($id), 'The enrollment record remains');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'only the registrar may confirm' => function (TestRunner $t): void {
        try {
            [$student, , $id] = approvedApplication();
            $sectionId = makeSectionFor($id);

            foreach ([$student, makeUser(User::ROLE_CASHIER), makeUser(User::ROLE_SUPER_ADMIN)] as $actor) {
                $status = null;
                try {
                    withSetting('enrollment_payment_policy', 'NONE', static fn () => Confirmation::confirm($actor, $id, $sectionId));
                } catch (HttpException $e) {
                    $status = $e->status();
                }
                $t->assertEquals(403, $status, "{$actor->role_name} must not confirm enrollment");
            }
            $t->assertEquals(0, enrollmentCount($id));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },
];
