<?php
/**
 * The Official Grading System in use (docs/27): what a teacher types is stored as an equivalent and a percentage, finals come from the
 * percentages, the marks are INC, OW, UW and NGA, and the migration moved the old data over.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\Router;
use App\Models\User;
use App\Services\AcademicRecordService as Record;
use App\Services\GradeCalculator;
use App\Services\GradingService as Grading;

/** A class in the current term, taught by a fixture teacher, with one enrolled student. @return array<string,mixed> */
function officialGradedClass(): array
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
    'a percentage, an equivalent, and a mark are each stored as the teacher typed them, with the equivalent and percentage together' => function (TestRunner $t): void {
        try {
            $c = officialGradedClass();
            withGradePeriods($c['term_id'], static function (array $periods) use ($t, $c): void {
                $prelim = (int) $periods['Prelim'];
                $midterm = (int) $periods['Midterm'];
                $semi = (int) $periods['Semi-Finals'];
                foreach ([$prelim, $midterm, $semi] as $id) {
                    openPeriodForTest($id);
                }
                $row = static fn (int $period): array => Database::selectOne('SELECT grade, percentage, remark FROM grades WHERE class_enlistment_id = ? AND grade_period_id = ?', [$c['enlistment_id'], $period]);

                Grading::recordGrades($c['teacher'], $c['class_id'], $prelim, [$c['enlistment_id'] => '92.4']);
                $t->assertEquals(['grade' => '1.40', 'percentage' => '92.40', 'remark' => null], $row($prelim), 'A percentage');

                Grading::recordGrades($c['teacher'], $c['class_id'], $midterm, [$c['enlistment_id'] => '2.10']);
                $t->assertEquals(['grade' => '2.10', 'percentage' => '84.00', 'remark' => null], $row($midterm), 'An equivalent brings the table\'s percentage');

                Grading::recordGrades($c['teacher'], $c['class_id'], $semi, [$c['enlistment_id'] => 'nga']);
                $t->assertEquals(['grade' => null, 'percentage' => null, 'remark' => 'NGA'], $row($semi), 'A mark');

                $errors = fieldErrors(static fn () => Grading::recordGrades($c['teacher'], $c['class_id'], $prelim, [$c['enlistment_id'] => '1.75']));
                $t->assertContains('not a valid grade', $errors["grades.{$c['enlistment_id']}"][0] ?? '');
                $t->assertContains('percentage', $errors["grades.{$c['enlistment_id']}"][0] ?? '', 'The message says what to type');
                $t->assertEquals('1.40', $row($prelim)['grade'], 'A refused entry changes nothing');
            });
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'saving the sheet again with the values it showed changes nothing, and a change is audited with the percentage' => function (TestRunner $t): void {
        try {
            $c = officialGradedClass();
            withGradePeriods($c['term_id'], static function (array $periods) use ($t, $c): void {
                $prelim = (int) $periods['Prelim'];
                openPeriodForTest($prelim);
                Grading::recordGrades($c['teacher'], $c['class_id'], $prelim, [$c['enlistment_id'] => '1.40']);

                // The sheet puts the stored percentage back in the box; saving it again is no change.
                $same = Grading::recordGrades($c['teacher'], $c['class_id'], $prelim, [$c['enlistment_id'] => '92.4']);
                $t->assertEquals(['saved' => 0, 'changed' => 0], $same, '1.40 and 92.4 are the same grade');

                $changed = Grading::recordGrades($c['teacher'], $c['class_id'], $prelim, [$c['enlistment_id'] => '91.9']);
                $t->assertEquals(1, $changed['changed'], '91.9 is a different percentage, even though the nearby equivalent differs by one step');

                $gradeId = (int) Database::scalar('SELECT id FROM grades WHERE class_enlistment_id = ?', [$c['enlistment_id']]);
                $audit = Database::selectOne("SELECT old_values, new_values FROM audit_logs WHERE action = 'grade.changed' AND target_id = ?", [$gradeId]);
                $t->assertContains('"percentage":"92.40"', (string) $audit['old_values']);
                $t->assertContains('"percentage":"91.90"', (string) $audit['new_values']);
                $t->assertContains('"grade":"1.45"', (string) $audit['new_values']);
            });
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the final comes from the percentages, a withdrawn class shows OW, and a mark in a period is the final' => function (TestRunner $t): void {
        try {
            $c = officialGradedClass();
            withGradePeriods($c['term_id'], static function (array $periods) use ($t, $c): void {
                $final = static fn () => Record::classesForEnrollment($c['enrollment_id'])[0]['final'];
                $values = ['Prelim' => '92.4', 'Midterm' => '88', 'Semi-Finals' => '90', 'Finals' => '85'];
                foreach ($values as $name => $value) {
                    openPeriodForTest((int) $periods[$name]);
                    Grading::recordGrades($c['teacher'], $c['class_id'], (int) $periods[$name], [$c['enlistment_id'] => $value]);
                }
                // 20/20/20/40: 18.48 + 17.6 + 18 + 34 = 88.08 -> 88.1 -> 1.70
                $t->assertEquals(['grade' => '1.70', 'percentage' => '88.1', 'remark' => 'PASSED'], $final());

                Grading::recordGrades($c['teacher'], $c['class_id'], (int) $periods['Finals'], [$c['enlistment_id'] => 'UW']);
                $t->assertEquals('UW', $final()['remark'], 'A mark in any period is the final');

                Database::update('class_enlistments', ['status' => 'DROPPED'], ['id' => $c['enlistment_id']]);
                $t->assertEquals('OW', $final()['remark'], 'A class the registrar dropped shows OW');
            });
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the migration left no DRP and no grade off the official scale, and every numeric grade has a percentage' => function (TestRunner $t): void {
        $t->assertEquals(0, (int) Database::scalar("SELECT COUNT(*) FROM grades WHERE remark = 'DRP'"), 'No DRP left');
        $t->assertEquals(0, (int) Database::scalar('SELECT COUNT(*) FROM grades WHERE grade IS NOT NULL AND percentage IS NULL'), 'Every numeric grade has a percentage');
        $official = GradeCalculator::equivalents();
        foreach (Database::select('SELECT DISTINCT grade FROM grades WHERE grade IS NOT NULL') as $row) {
            $t->assertTrue(in_array(number_format((float) $row['grade'], 2, '.', ''), $official, true), "{$row['grade']} is an official equivalent");
        }

        $sql = (string) file_get_contents(BASE_PATH . '/database/migrations/042_official_grading_system.sql');
        $t->assertContains('WHEN 1.75 THEN 87.50', $sql);
        $t->assertContains("WHEN 87.50 THEN 1.80 WHEN 82.50 THEN 2.30 WHEN 77.50 THEN 2.80", $sql);
        $t->assertContains("remark = 'OW' WHERE remark = 'DRP'", $sql);
    },

    'the grade sheet has a box to type in, not a dropdown, and the grading system page is for every signed-in role' => function (TestRunner $t): void {
        $view = (string) file_get_contents(BASE_PATH . '/app/Views/teacher/classes/grades.php');
        $t->assertContains('type="text" inputmode="decimal"', $view, 'A text box');
        $t->assertContains('data-grade-input', $view);
        $t->assertFalse(strpos($view, '<select id="grade-') !== false, 'No dropdown for grades');
        $t->assertContains('id="grade-scale"', $view, 'The table for the live equivalent');
        $t->assertContains("url('/grading-system')", $view, 'A link to the official table');

        $router = new Router();
        require BASE_PATH . '/routes/web.php';
        $found = false;
        foreach ($router->routes() as $route) {
            if ($route['pattern'] === '/grading-system') {
                $found = true;
                $t->assertEquals('GET', $route['method']);
                $t->assertEquals(['auth'], $route['middleware'], 'Signed in, any role');
            }
        }
        $t->assertTrue($found, 'The route exists');

        $js = (string) file_get_contents(BASE_PATH . '/public/assets/app.js');
        $t->assertContains('data-grade-input', $js, 'The script shows the equivalent as the teacher types');
    },
];
