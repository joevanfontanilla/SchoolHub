<?php
/**
 * Adding a Semi-Finals period to a term that lacks it (docs/27): the standard weights, closed, in place on the teacher's grade
 * sheet, refused when a final would change, and the registrar's alone.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\Router;
use App\Models\GradePeriod;
use App\Models\User;
use App\Services\GradingService as Grading;

/**
 * A class in the current term, taught by a fixture teacher, with one enrolled student.
 *
 * @return array{teacher:User,registrar:User,class_id:int,enlistment_id:int,enrollment_id:int,student:User,term_id:int,section_id:int}
 */
function semiGradedClass(): array
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

/**
 * Run a callback on the current term turned into the old three-period scheme (Prelim 30, Midterm 30, Finals 40), and put the
 * real periods back afterwards, with their original ids.
 */
function withThreePeriods(int $termId, callable $callback): void
{
    $original = Database::select('SELECT * FROM grade_periods WHERE term_id = ? ORDER BY sequence', [$termId]);
    $restore = static function () use ($termId, $original): void {
        Database::query("DELETE FROM grade_periods WHERE term_id = ? AND name = 'Semi-Finals'", [$termId]);
        foreach ($original as $row) {
            if ($row['name'] === 'Semi-Finals') {
                continue;
            }
            Database::update('grade_periods', ['sequence' => $row['sequence'], 'weight' => $row['weight'], 'is_open' => $row['is_open']], ['id' => $row['id']]);
        }
        foreach ($original as $row) {
            if ($row['name'] === 'Semi-Finals') {
                Database::insert('grade_periods', array_diff_key($row, ['created_at' => 1, 'updated_at' => 1]));
            }
        }
    };

    try {
        Database::query("DELETE FROM grade_periods WHERE term_id = ? AND name = 'Semi-Finals'", [$termId]);
        Database::query("UPDATE grade_periods SET sequence = 3, weight = 40, is_open = 0 WHERE term_id = ? AND name = 'Finals'", [$termId]);
        Database::query("UPDATE grade_periods SET weight = 30, is_open = 0 WHERE term_id = ? AND name IN ('Prelim', 'Midterm')", [$termId]);
        $callback();
    } finally {
        deleteTestUsers();
        $restore();
    }
}

return [
    'adding Semi-Finals puts it between Midterm and Finals, closed, at 20/20/20/40, and leaves grades alone' => function (TestRunner $t): void {
        $c = null;
        try {
            $c = semiGradedClass();
            withThreePeriods($c['term_id'], static function () use ($t, $c): void {
                $periods = GradePeriod::forTerm($c['term_id']);
                $t->assertEquals(['Prelim', 'Midterm', 'Finals'], array_column($periods, 'name'));
                $prelim = (int) $periods[0]['id'];

                openPeriodForTest($prelim);
                Grading::recordGrades($c['teacher'], $c['class_id'], $prelim, [$c['enlistment_id'] => '1.70']);

                Grading::addSemiFinals($c['registrar'], $c['term_id']);

                $after = GradePeriod::forTerm($c['term_id']);
                $t->assertEquals(['Prelim', 'Midterm', 'Semi-Finals', 'Finals'], array_column($after, 'name'), 'In the right place');
                $t->assertEquals([1, 2, 3, 4], array_map('intval', array_column($after, 'sequence')));
                $t->assertEquals([20.0, 20.0, 20.0, 40.0], array_map('floatval', array_column($after, 'weight')), 'The standard weights');
                $t->assertEquals(0, (int) $after[2]['is_open'], 'Starts closed');
                $t->assertEquals('1.70', Database::scalar('SELECT grade FROM grades WHERE class_enlistment_id = ? AND grade_period_id = ?', [$c['enlistment_id'], $prelim]), 'The grade already entered stays');

                $audit = Database::selectOne("SELECT old_values, new_values FROM audit_logs WHERE action = 'grading.period_added' AND target_id = ? ORDER BY id DESC LIMIT 1", [$c['term_id']]);
                $t->assertNotNull($audit, 'Audited');
                $t->assertContains('30', (string) $audit['old_values'], 'The old weights are recorded');

                $t->assertThrows(static fn () => Grading::addSemiFinals($c['registrar'], $c['term_id']), 'already has');
            });
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'it is refused when a student already has a complete final, and when the periods are custom' => function (TestRunner $t): void {
        try {
            $c = semiGradedClass();
            withThreePeriods($c['term_id'], static function () use ($t, $c): void {
                $periods = GradePeriod::forTerm($c['term_id']);
                foreach ($periods as $period) {
                    openPeriodForTest((int) $period['id']);
                    Grading::recordGrades($c['teacher'], $c['class_id'], (int) $period['id'], [$c['enlistment_id'] => '1.50']);
                }

                $t->assertThrows(static fn () => Grading::addSemiFinals($c['registrar'], $c['term_id']), 'complete final');
                $t->assertEquals(['Prelim', 'Midterm', 'Finals'], array_column(GradePeriod::forTerm($c['term_id']), 'name'), 'Nothing changed');
                $t->assertEquals([30.0, 30.0, 40.0], array_map('floatval', array_column(GradePeriod::forTerm($c['term_id']), 'weight')));

                // A custom scheme is left to the registrar.
                Database::query("UPDATE grade_periods SET name = 'Quiz Period' WHERE term_id = ? AND name = 'Midterm'", [$c['term_id']]);
                $t->assertThrows(static fn () => Grading::addSemiFinals($c['registrar'], $c['term_id']), 'custom grading periods');
                Database::query("UPDATE grade_periods SET name = 'Midterm' WHERE term_id = ? AND name = 'Quiz Period'", [$c['term_id']]);
            });
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'only the registrar can add it, and a term without periods is a 404' => function (TestRunner $t): void {
        try {
            $c = semiGradedClass();
            withThreePeriods($c['term_id'], static function () use ($t, $c): void {
                foreach ([User::ROLE_CASHIER, User::ROLE_SUPER_ADMIN, User::ROLE_TEACHER, User::ROLE_STUDENT] as $role) {
                    $t->assertEquals(403, statusOf(static fn () => Grading::addSemiFinals(makeUser($role), $c['term_id'])), "{$role} cannot");
                }
                $t->assertEquals(['Prelim', 'Midterm', 'Finals'], array_column(GradePeriod::forTerm($c['term_id']), 'name'));
                $t->assertEquals(404, statusOf(static fn () => Grading::addSemiFinals($c['registrar'], PHP_INT_MAX)));
            });
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the route is the registrar\'s and the button asks for confirmation; every term now has Semi-Finals' => function (TestRunner $t): void {
        $router = new Router();
        require BASE_PATH . '/routes/web.php';
        $found = 0;
        foreach ($router->routes() as $route) {
            if ($route['pattern'] === '/registrar/grading/terms/{id}/semi-finals') {
                $found++;
                $t->assertEquals('POST', $route['method']);
                $t->assertTrue(in_array('role:' . User::ROLE_REGISTRAR, $route['middleware'], true) && in_array('can:grade_periods.manage', $route['middleware'], true));
            }
        }
        $t->assertEquals(1, $found);

        $view = (string) file_get_contents(BASE_PATH . '/app/Views/registrar/grading/index.php');
        $t->assertTrue(preg_match('#/semi-finals\'\)\) \?>"[^>]*data-confirm=#s', $view) === 1, 'The button asks for confirmation');

        $without = Database::select(
            "SELECT t.id FROM terms t WHERE NOT EXISTS (SELECT 1 FROM grade_periods gp WHERE gp.term_id = t.id AND gp.name = 'Semi-Finals')
                AND EXISTS (SELECT 1 FROM grade_periods gp WHERE gp.term_id = t.id)"
        );
        $t->assertEquals([], $without, 'Every term with grading periods has Semi-Finals (migration 041 and the seeder)');
    },
];
