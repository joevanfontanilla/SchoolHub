<?php
/**
 * Grading periods and grade entry (docs/12, docs/27).
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\ValidationException;
use App\Models\User;
use App\Services\AcademicRecordService as Record;
use App\Services\GradingService as Grading;

/**
 * A class in the current term, taught by a fixture teacher, with one enrolled student.
 *
 * @return array{teacher:User,registrar:User,class_id:int,enlistment_id:int,enrollment_id:int,student:User,term_id:int,section_id:int}
 */
function gradedClass(): array
{
    $enrolled = enrolledStudent(currentTermId());
    $teacher = makeUser(User::ROLE_TEACHER);
    $classId = makeClass($enrolled['registrar'], $enrolled['section_id'], makeSubject(), $teacher->id, [meeting(1, '08:00', '09:00'), meeting(3, '08:00', '09:00')]);

    return $enrolled + [
        'teacher'       => $teacher,
        'class_id'      => $classId,
        'enlistment_id' => (int) enlistmentIdFor($enrolled['enrollment_id'], $classId),
        'term_id'       => currentTermId(),
    ];
}

return [
    'grades are recorded only while their period is open' => function (TestRunner $t): void {
        try {
            $c = gradedClass();
            withGradePeriods($c['term_id'], static function (array $periods) use ($t, $c): void {
                Database::update('grade_periods', ['is_open' => 0], ['id' => $periods['Prelim']]);
                $t->assertThrows(static fn () => Grading::recordGrades($c['teacher'], $c['class_id'], (int) $periods['Prelim'], [$c['enlistment_id'] => '1.75']), 'grading is closed');

                openPeriodForTest((int) $periods['Prelim']);
                $result = Grading::recordGrades($c['teacher'], $c['class_id'], (int) $periods['Prelim'], [$c['enlistment_id'] => '1.75']);
                $t->assertEquals(1, $result['saved']);
                $t->assertEquals('1.75', Database::scalar('SELECT grade FROM grades WHERE class_enlistment_id = ?', [$c['enlistment_id']]));
            });
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'another teacher\'s class is a 404, and other roles are refused' => function (TestRunner $t): void {
        try {
            $c = gradedClass();
            withGradePeriods($c['term_id'], static function (array $periods) use ($t, $c): void {
                openPeriodForTest((int) $periods['Prelim']);
                $entry = [$c['enlistment_id'] => '1.75'];

                $t->assertEquals(404, statusOf(static fn () => Grading::recordGrades(makeUser(User::ROLE_TEACHER), $c['class_id'], (int) $periods['Prelim'], $entry)));
                foreach ([User::ROLE_REGISTRAR, User::ROLE_CASHIER, User::ROLE_SUPER_ADMIN] as $role) {
                    $t->assertEquals(403, statusOf(static fn () => Grading::recordGrades(makeUser($role), $c['class_id'], (int) $periods['Prelim'], $entry)), "{$role} cannot enter grades");
                }
                $t->assertEquals(403, statusOf(static fn () => Grading::recordGrades($c['student'], $c['class_id'], (int) $periods['Prelim'], $entry)));
                $t->assertEquals(0, (int) Database::scalar('SELECT COUNT(*) FROM grades WHERE class_enlistment_id = ?', [$c['enlistment_id']]));

                $otherTerm = (int) Database::scalar('SELECT id FROM grade_periods WHERE term_id <> ? LIMIT 1', [$c['term_id']]);
                $t->assertEquals(404, statusOf(static fn () => Grading::recordGrades($c['teacher'], $c['class_id'], $otherTerm, $entry)), 'A period of another term');
            });
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'an invalid value or a student outside the class saves nothing at all' => function (TestRunner $t): void {
        try {
            $c = gradedClass();
            $outsider = enrolledStudent(currentTermId(), siblingSection($c['section_id']));
            $outsiderClass = makeClass($c['registrar'], $outsider['section_id'], makeSubject(), null, [meeting(2, '08:00', '09:00')]);
            $outsiderEnlistment = enlistmentIdFor($outsider['enrollment_id'], $outsiderClass);

            withGradePeriods($c['term_id'], static function (array $periods) use ($t, $c, $outsiderEnlistment): void {
                openPeriodForTest((int) $periods['Prelim']);

                try {
                    Grading::recordGrades($c['teacher'], $c['class_id'], (int) $periods['Prelim'], [$c['enlistment_id'] => '2.30']);
                    $t->assertTrue(false, '2.30 should be refused');
                } catch (ValidationException $e) {
                    $t->assertContains('not a valid grade', $e->errors()["grades.{$c['enlistment_id']}"][0]);
                }

                $errors = fieldErrors(static fn () => Grading::recordGrades($c['teacher'], $c['class_id'], (int) $periods['Prelim'], [
                    $c['enlistment_id'] => '1.50',
                    $outsiderEnlistment => '1.00',
                ]));
                $t->assertContains('not enlisted in this class', $errors["grades.{$outsiderEnlistment}"][0] ?? '');
                $t->assertEquals(0, (int) Database::scalar('SELECT COUNT(*) FROM grades WHERE class_enlistment_id IN (?, ?)', [$c['enlistment_id'], $outsiderEnlistment]), 'All or nothing');
            });
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a changed grade is audited with its old and new value; an unchanged one is not' => function (TestRunner $t): void {
        try {
            $c = gradedClass();
            withGradePeriods($c['term_id'], static function (array $periods) use ($t, $c): void {
                $prelim = (int) $periods['Prelim'];
                openPeriodForTest($prelim);
                Grading::recordGrades($c['teacher'], $c['class_id'], $prelim, [$c['enlistment_id'] => '2.00']);

                $same = Grading::recordGrades($c['teacher'], $c['class_id'], $prelim, [$c['enlistment_id'] => '2']);
                $t->assertEquals(['saved' => 0, 'changed' => 0], $same, '2 is the same grade as 2.00');

                $changed = Grading::recordGrades($c['teacher'], $c['class_id'], $prelim, [$c['enlistment_id'] => 'inc']);
                $t->assertEquals(1, $changed['changed']);

                $gradeId = (int) Database::scalar('SELECT id FROM grades WHERE class_enlistment_id = ?', [$c['enlistment_id']]);
                $audit = Database::selectOne("SELECT * FROM audit_logs WHERE action = 'grade.changed' AND target_id = ?", [$gradeId]);
                $t->assertContains('"grade":"2.00"', (string) $audit['old_values']);
                $t->assertContains('"remark":"INC"', (string) $audit['new_values']);
                $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'grade.changed' AND target_id = ?", [$gradeId]));
            });
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'closing a period locks its grades and tells students; reopening is audited' => function (TestRunner $t): void {
        try {
            $c = gradedClass();
            withGradePeriods($c['term_id'], static function (array $periods) use ($t, $c): void {
                $prelim = (int) $periods['Prelim'];
                Database::update('grade_periods', ['is_open' => 0], ['id' => $prelim]);

                Grading::openPeriod($c['registrar'], $prelim);
                $t->assertThrows(static fn () => Grading::openPeriod($c['registrar'], $prelim), 'already open');
                Grading::recordGrades($c['teacher'], $c['class_id'], $prelim, [$c['enlistment_id'] => '1.25']);

                Grading::closePeriod($c['registrar'], $prelim);
                $t->assertThrows(static fn () => Grading::recordGrades($c['teacher'], $c['class_id'], $prelim, [$c['enlistment_id'] => '3.00']), 'closed');
                $t->assertEquals('1.25', Database::scalar('SELECT grade FROM grades WHERE class_enlistment_id = ?', [$c['enlistment_id']]));
                $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'grades.released'", [$c['student']->id]));

                Grading::openPeriod($c['registrar'], $prelim);
                $t->assertEquals(2, (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'grading.period_opened' AND target_id = ? AND user_id = ?", [$prelim, $c['registrar']->id]));

                $t->assertEquals(403, statusOf(static fn () => Grading::closePeriod($c['teacher'], $prelim)), 'A teacher cannot close a period');
            });
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'weights must total 100 and are fixed once a grade exists' => function (TestRunner $t): void {
        try {
            $c = gradedClass();
            withGradePeriods($c['term_id'], static function (array $periods) use ($t, $c): void {
                $weights = static fn (int $a, int $b, int $f) => [$periods['Prelim'] => $a, $periods['Midterm'] => $b, $periods['Finals'] => $f];

                $t->assertTrue(isset(fieldErrors(static fn () => Grading::setWeights($c['registrar'], $c['term_id'], $weights(30, 30, 30)))['weights']), '90 is refused');

                // Grades may already exist in the real term; only then is the change refused.
                $graded = (int) Database::scalar('SELECT COUNT(*) FROM grades g JOIN grade_periods gp ON gp.id = g.grade_period_id WHERE gp.term_id = ?', [$c['term_id']]) > 0;
                if (!$graded) {
                    Grading::setWeights($c['registrar'], $c['term_id'], $weights(25, 25, 50));
                    $t->assertEquals(50.0, (float) Database::scalar('SELECT weight FROM grade_periods WHERE id = ?', [$periods['Finals']]));
                }

                openPeriodForTest((int) $periods['Prelim']);
                Grading::recordGrades($c['teacher'], $c['class_id'], (int) $periods['Prelim'], [$c['enlistment_id'] => '1.00']);
                $t->assertThrows(static fn () => Grading::setWeights($c['registrar'], $c['term_id'], $weights(30, 30, 40)), 'can no longer change');
            });
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the final grade is in progress until all three periods are graded, then weighted' => function (TestRunner $t): void {
        try {
            $c = gradedClass();
            withGradePeriods($c['term_id'], static function (array $periods) use ($t, $c): void {
                Grading::setWeights($c['registrar'], $c['term_id'], [$periods['Prelim'] => 30, $periods['Midterm'] => 30, $periods['Finals'] => 40]);

                $record = static fn () => Record::classesForEnrollment($c['enrollment_id'])[0]['final'];
                foreach (['Prelim' => '1.75', 'Midterm' => '2.00'] as $name => $grade) {
                    openPeriodForTest((int) $periods[$name]);
                    Grading::recordGrades($c['teacher'], $c['class_id'], (int) $periods[$name], [$c['enlistment_id'] => $grade]);
                }
                $t->assertEquals('IN_PROGRESS', $record()['remark'], 'Finals missing');

                openPeriodForTest((int) $periods['Finals']);
                Grading::recordGrades($c['teacher'], $c['class_id'], (int) $periods['Finals'], [$c['enlistment_id'] => '1.50']);
                // 0.3 * 1.75 + 0.3 * 2.00 + 0.4 * 1.50 = 1.725 -> nearest step 1.75
                $t->assertEquals(['grade' => '1.75', 'remark' => 'PASSED'], $record());
            });
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },
];
