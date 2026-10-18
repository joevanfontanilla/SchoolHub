<?php
/**
 * Enlisting students in classes: block enlistment, overrides, load, drops (docs/13, docs/28).
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\ValidationException;
use App\Models\User;
use App\Services\EnlistmentService as Enlistment;
use App\Services\EnrollmentConfirmationService as Confirmation;
use App\Services\GradingService;

/** @return array<int,string> the problem types for an enrollment and class */
function problemTypes(int $enrollmentId, int $classId): array
{
    $enrollment = Database::selectOne('SELECT * FROM enrollments WHERE id = ?', [$enrollmentId]);
    return array_column(Enlistment::problems($enrollment, $classId), 'type');
}

return [
    'confirmation enlists the student in every class of the block section' => function (TestRunner $t): void {
        try {
            [, $registrar, $applicationId] = approvedApplication();
            $sectionId = makeSectionFor($applicationId);
            makeClass($registrar, $sectionId, makeSubject(3), null, [meeting(1, '08:00', '09:00')]);
            makeClass($registrar, $sectionId, makeSubject(2), null, [meeting(1, '09:00', '10:00')]);

            $result = withSetting('enrollment_payment_policy', 'NONE', static fn () => Confirmation::confirm($registrar, $applicationId, $sectionId));

            $t->assertEquals(2, count($result['enlistment']['enlisted']));
            $t->assertEquals([], $result['enlistment']['skipped']);
            $t->assertEquals(5.0, (float) Database::scalar('SELECT total_units FROM enrollments WHERE id = ?', [$result['enrollment_id']]));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a class whose prerequisite is not passed is skipped, flagged, and added only with a reason' => function (TestRunner $t): void {
        try {
            [, $registrar, $applicationId] = approvedApplication();
            $sectionId = makeSectionFor($applicationId);
            $basic = makeSubject();
            $advanced = makeSubject(3, [$basic]);
            $classId = makeClass($registrar, $sectionId, $advanced, null, [meeting(2, '08:00', '09:00')]);

            $result = withSetting('enrollment_payment_policy', 'NONE', static fn () => Confirmation::confirm($registrar, $applicationId, $sectionId));
            $enrollmentId = $result['enrollment_id'];

            $t->assertEquals(1, count($result['enlistment']['skipped']));
            $t->assertContains('Prerequisite not passed', $result['enlistment']['skipped'][0]['reason']);
            $t->assertNull(enlistmentIdFor($enrollmentId, $classId));

            $flags = Enlistment::notEnlisted($enrollmentId);
            $t->assertEquals('prerequisite', $flags[0]['problems'][0]['type'], 'The registrar sees why');

            $errors = fieldErrors(static fn () => Enlistment::addClass($registrar, $enrollmentId, $classId, ''));
            $t->assertTrue(isset($errors['override_reason']), 'No reason, no override');

            Enlistment::addClass($registrar, $enrollmentId, $classId, 'Passed the equivalent subject at Aurora State College');
            $row = Database::selectOne('SELECT * FROM class_enlistments WHERE id = ?', [enlistmentIdFor($enrollmentId, $classId)]);
            $t->assertEquals('ENLISTED', $row['status']);
            $t->assertContains('Aurora State College', (string) $row['override_reason']);
            $t->assertEquals(1, (int) Database::scalar(
                "SELECT COUNT(*) FROM audit_logs WHERE action = 'enlistment.added' AND target_id = ? AND remarks LIKE '%Aurora State%'",
                [$row['id']]
            ));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a time conflict can never be overridden' => function (TestRunner $t): void {
        try {
            $enrolled = enrolledStudent();
            $registrar = $enrolled['registrar'];
            makeClass($registrar, $enrolled['section_id'], makeSubject(), null, [meeting(3, '08:00', '09:00')]);
            $elsewhere = makeClass($registrar, siblingSection($enrolled['section_id']), makeSubject(), null, [meeting(3, '08:30', '09:30')]);

            try {
                Enlistment::addClass($registrar, $enrolled['enrollment_id'], $elsewhere, 'Please allow it');
                $t->assertTrue(false, 'The clash should have been refused');
            } catch (ValidationException $e) {
                $t->assertContains('cannot be overridden', $e->getMessage());
            }
            $t->assertNull(enlistmentIdFor($enrolled['enrollment_id'], $elsewhere));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the unit limit is the setting, or the curriculum\'s heavier regular load for that term' => function (TestRunner $t): void {
        try {
            $enrolled = enrolledStudent();
            $registrar = $enrolled['registrar'];
            $enrollment = Database::selectOne('SELECT * FROM enrollments WHERE id = ?', [$enrolled['enrollment_id']]);
            $section = Database::selectOne('SELECT s.*, t.sequence AS term_sequence FROM sections s JOIN terms t ON t.id = s.term_id WHERE s.id = ?', [$enrolled['section_id']]);

            // 27 units of block classes, then one more 3-unit class elsewhere.
            foreach ([1, 2, 3] as $day) {
                makeClass($registrar, $enrolled['section_id'], makeSubject(9), null, [meeting($day, '08:00', '09:00')]);
            }
            $extra = makeClass($registrar, siblingSection($enrolled['section_id']), makeSubject(3), null, [meeting(6, '08:00', '09:00')]);

            Database::update('students', ['curriculum_id' => null], ['id' => $enrolled['student_id']]);
            withSetting('max_units_per_term', '27', static function () use ($t, $enrollment, $enrolled, $extra, $section, $registrar): void {
                $t->assertEquals(27.0, Enlistment::unitLimit($enrollment), 'With no curriculum, the setting');
                $t->assertTrue(in_array('load', problemTypes($enrolled['enrollment_id'], $extra), true), '30 units is an overload');

                // A curriculum whose regular load for this year and term is 30 units.
                $programId = (int) $section['program_id'];
                $curriculumId = Database::insert('curricula', ['program_id' => $programId, 'code' => testCode('C'), 'name' => 'Heavy', 'effective_school_year' => '2024-2025']);
                foreach ([10, 10, 10] as $units) {
                    makeSubject($units, [], ['curriculum_id' => $curriculumId, 'program_id' => $programId, 'year_level_id' => $section['year_level_id'], 'term_sequence' => $section['term_sequence']]);
                }
                Database::update('students', ['curriculum_id' => $curriculumId], ['id' => $enrolled['student_id']]);

                $t->assertEquals(30.0, Enlistment::unitLimit($enrollment), 'The regular load wins when heavier');
                $t->assertFalse(in_array('load', problemTypes($enrolled['enrollment_id'], $extra), true), '30 units is the regular load, not an overload');

                Enlistment::addClass($registrar, $enrolled['enrollment_id'], $extra, null);
                $t->assertEquals(30.0, (float) Database::scalar('SELECT total_units FROM enrollments WHERE id = ?', [$enrolled['enrollment_id']]));
            });
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a subject needing curriculum progress waits until enough units are passed' => function (TestRunner $t): void {
        try {
            $enrolled = enrolledStudent(currentTermId());
            $registrar = $enrolled['registrar'];
            $section = Database::selectOne('SELECT * FROM sections WHERE id = ?', [$enrolled['section_id']]);

            $curriculumId = Database::insert('curricula', ['program_id' => $section['program_id'], 'code' => testCode('C'), 'name' => 'Progress', 'effective_school_year' => '2024-2025']);
            $inCurriculum = ['curriculum_id' => $curriculumId, 'program_id' => $section['program_id']];
            $course = makeSubject(3, [], $inCurriculum);
            $internship = makeSubject(3, [], $inCurriculum + ['min_progress_percent' => 50]);
            Database::update('students', ['curriculum_id' => $curriculumId], ['id' => $enrolled['student_id']]);

            $teacher = makeUser(User::ROLE_TEACHER);
            $courseClass = makeClass($registrar, $enrolled['section_id'], $course, $teacher->id, [meeting(1, '08:00', '09:00')]);
            $internClass = makeClass($registrar, siblingSection($enrolled['section_id']), $internship, null, [meeting(2, '08:00', '09:00')]);

            $t->assertTrue(in_array('progress', problemTypes($enrolled['enrollment_id'], $internClass), true), '0% passed');

            // Pass the 3-unit course: 3 of 6 units is 50%.
            withGradePeriods((int) $section['term_id'], static function (array $periods) use ($teacher, $courseClass, $enrolled): void {
                $enlistment = enlistmentIdFor($enrolled['enrollment_id'], $courseClass);
                foreach ($periods as $periodId) {
                    openPeriodForTest((int) $periodId);
                    GradingService::recordGrades($teacher, $courseClass, (int) $periodId, [$enlistment => '2.00']);
                }
            });

            confirmGradeSheets($courseClass);
            $t->assertFalse(in_array('progress', problemTypes($enrolled['enrollment_id'], $internClass), true), '50% passed meets 50%');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'dropping keeps the enlistment with its reason, recomputes units, and can be undone' => function (TestRunner $t): void {
        try {
            $enrolled = enrolledStudent();
            $registrar = $enrolled['registrar'];
            $classId = makeClass($registrar, $enrolled['section_id'], makeSubject(3), null, [meeting(1, '08:00', '09:00')]);
            $enlistmentId = enlistmentIdFor($enrolled['enrollment_id'], $classId);

            $t->assertTrue(isset(fieldErrors(static fn () => Enlistment::dropClass($registrar, $enlistmentId, '  '))['drop_reason']), 'A reason is required');

            Enlistment::dropClass($registrar, $enlistmentId, 'Student requested');
            $row = Database::selectOne('SELECT * FROM class_enlistments WHERE id = ?', [$enlistmentId]);
            $t->assertEquals('DROPPED', $row['status']);
            $t->assertEquals('Student requested', $row['drop_reason']);
            $t->assertEquals(0.0, (float) Database::scalar('SELECT total_units FROM enrollments WHERE id = ?', [$enrolled['enrollment_id']]));

            $t->assertEquals(1, count(App\Services\AcademicRecordService::classesForEnrollment($enrolled['enrollment_id'])), 'The dropped class stays in the record');
            $t->assertEquals('OW', App\Services\AcademicRecordService::classesForEnrollment($enrolled['enrollment_id'])[0]['final']['remark']);

            $t->assertEquals([], Enlistment::problems(Database::selectOne('SELECT * FROM enrollments WHERE id = ?', [$enrolled['enrollment_id']]), $classId));
            Enlistment::addClass($registrar, $enrolled['enrollment_id'], $classId, null);
            $t->assertEquals($enlistmentId, enlistmentIdFor($enrolled['enrollment_id'], $classId), 'Re-adding revives the same row');
            $t->assertEquals('ENLISTED', Database::scalar('SELECT status FROM class_enlistments WHERE id = ?', [$enlistmentId]));

            // Block enlistment leaves a registrar's drop alone.
            Enlistment::dropClass($registrar, $enlistmentId, 'Again');
            Enlistment::enlistBlock($enrolled['enrollment_id'], $registrar);
            $t->assertEquals('DROPPED', Database::scalar('SELECT status FROM class_enlistments WHERE id = ?', [$enlistmentId]));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'only the registrar adds or drops classes' => function (TestRunner $t): void {
        try {
            $enrolled = enrolledStudent();
            $classId = makeClass($enrolled['registrar'], $enrolled['section_id'], makeSubject(), null, [meeting(1, '08:00', '09:00')]);
            $teacher = makeUser(User::ROLE_TEACHER);

            $t->assertEquals(403, statusOf(static fn () => Enlistment::addClass($teacher, $enrolled['enrollment_id'], $classId, 'x')));
            $t->assertEquals(403, statusOf(static fn () => Enlistment::dropClass($enrolled['student'], (int) enlistmentIdFor($enrolled['enrollment_id'], $classId), 'x')));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },
];
