<?php
/**
 * A student's classes, grades, and history (docs/13 "Student Academic History").
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Models\User;
use App\Services\AcademicRecordService as Record;
use App\Services\GradingService as Grading;

return [
    'only a passing final counts as passed: failed, INC, and in-progress grades do not' => function (TestRunner $t): void {
        try {
            $enrolled = enrolledStudent(currentTermId());
            $teacher = makeUser(User::ROLE_TEACHER);
            $subjects = ['pass' => makeSubject(), 'fail' => makeSubject(), 'inc' => makeSubject(), 'partial' => makeSubject()];

            $classes = [];
            $day = 1;
            foreach ($subjects as $key => $subjectId) {
                $classes[$key] = makeClass($enrolled['registrar'], $enrolled['section_id'], $subjectId, $teacher->id, [meeting($day++, '08:00', '09:00')]);
            }

            // The value each subject gets in period #$index. Outcomes are weight- and
            // period-count-independent: all passing, all failing, one INC, or one left blank.
            $valueFor = static fn (string $key, int $index, int $last): string => match ($key) {
                'fail'    => '5.00',
                'inc'     => $index === 0 ? 'INC' : '2.00',
                'partial' => $index === $last ? '' : '2.00',
                default   => '1.50',
            };

            withGradePeriods(currentTermId(), static function (array $periods) use ($teacher, $classes, $enrolled, $valueFor): void {
                $ids = array_values($periods);
                $last = count($ids) - 1;
                foreach ($ids as $index => $periodId) {
                    openPeriodForTest((int) $periodId);
                    foreach ($classes as $key => $classId) {
                        Grading::recordGrades($teacher, $classId, (int) $periodId, [enlistmentIdFor($enrolled['enrollment_id'], $classId) => $valueFor($key, $index, $last)]);
                    }
                }
            });

            foreach ($classes as $classId) {
                confirmGradeSheets($classId);
            }

            $finals = [];
            foreach (Record::classesForEnrollment($enrolled['enrollment_id']) as $class) {
                $finals[array_search((int) $class['subject_id'], $subjects, true)] = $class['final']['remark'];
            }
            $t->assertEquals(['pass' => 'PASSED', 'fail' => 'FAILED', 'inc' => 'INC', 'partial' => 'IN_PROGRESS'], $finals, 'Remarks');

            $t->assertTrue(Record::hasPassed($enrolled['student_id'], $subjects['pass']));
            foreach (['fail', 'inc', 'partial'] as $key) {
                $t->assertFalse(Record::hasPassed($enrolled['student_id'], $subjects[$key]), "{$key} is not passed");
            }
            $t->assertEquals([$subjects['pass']], Record::passedSubjectIds($enrolled['student_id']));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'history lists each enrolled term with its section, periods, and classes' => function (TestRunner $t): void {
        try {
            $enrolled = enrolledStudent(currentTermId());
            makeClass($enrolled['registrar'], $enrolled['section_id'], makeSubject(), null, [meeting(1, '08:00', '09:00')]);

            $history = Record::history($enrolled['student_id']);
            $t->assertEquals(1, count($history));
            $t->assertEquals(currentTermId(), (int) $history[0]['term_id']);
            $expectedPeriods = (int) Database::scalar('SELECT COUNT(*) FROM grade_periods WHERE term_id = ?', [currentTermId()]);
            $t->assertEquals($expectedPeriods, count($history[0]['periods']), 'One history row per grading period');
            $t->assertEquals(1, count($history[0]['classes']));
            $t->assertEquals(1, count($history[0]['classes'][0]['meetings']));

            $t->assertEquals($enrolled['enrollment_id'], (int) Record::currentEnrollment($enrolled['student_id'])['id']);
            $t->assertEquals([], Record::history((int) Database::scalar('SELECT id FROM students WHERE user_id = ?', [makeStudent()->id])), 'A student never enrolled has no history');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },
];
