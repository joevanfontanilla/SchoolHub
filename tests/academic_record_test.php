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
            $grades = [
                'pass'    => ['1.50', '1.75', '2.00'],
                'fail'    => ['3.00', '3.00', '5.00'],
                'inc'     => ['2.00', '2.00', 'INC'],
                'partial' => ['2.00', '2.00', ''],
            ];

            $classes = [];
            $day = 1;
            foreach ($subjects as $key => $subjectId) {
                $classes[$key] = makeClass($enrolled['registrar'], $enrolled['section_id'], $subjectId, $teacher->id, [meeting($day++, '08:00', '09:00')]);
            }

            withGradePeriods(currentTermId(), static function (array $periods) use ($teacher, $classes, $grades, $enrolled): void {
                Database::query('UPDATE grade_periods SET weight = CASE sequence WHEN 1 THEN 30 WHEN 2 THEN 30 ELSE 40 END WHERE id IN (?, ?, ?)', array_values($periods));
                foreach (array_values($periods) as $index => $periodId) {
                    openPeriodForTest((int) $periodId);
                    foreach ($classes as $key => $classId) {
                        Grading::recordGrades($teacher, $classId, (int) $periodId, [enlistmentIdFor($enrolled['enrollment_id'], $classId) => $grades[$key][$index]]);
                    }
                }
            });

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
            $t->assertEquals(3, count($history[0]['periods']), 'Prelim, Midterm, Finals');
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
