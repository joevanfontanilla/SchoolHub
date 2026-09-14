<?php
/**
 * Class attendance (docs/12).
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Models\User;
use App\Services\AttendanceService as Attendance;

/**
 * A Monday/Wednesday class in the current term with one enrolled student.
 *
 * @return array<string,mixed>
 */
function attendanceClass(): array
{
    $enrolled = enrolledStudent(currentTermId());
    $teacher = makeUser(User::ROLE_TEACHER);
    $classId = makeClass($enrolled['registrar'], $enrolled['section_id'], makeSubject(), $teacher->id, [meeting(1, '08:00', '09:00'), meeting(3, '08:00', '09:00')]);

    return $enrolled + ['teacher' => $teacher, 'class_id' => $classId, 'enlistment_id' => (int) enlistmentIdFor($enrolled['enrollment_id'], $classId)];
}

function attendanceRefusal(callable $callback): string
{
    try {
        $callback();
    } catch (App\Core\ValidationException $e) {
        return $e->getMessage();
    }
    return '';
}

return [
    'a teacher takes attendance on a meeting day, and saving again updates instead of duplicating' => function (TestRunner $t): void {
        try {
            $c = attendanceClass();
            $monday = lastWeekday(1);

            $first = Attendance::record($c['teacher'], $c['class_id'], $monday, [$c['enlistment_id'] => 'present']);
            $t->assertEquals(['saved' => 1, 'changed' => 0], $first);

            $same = Attendance::record($c['teacher'], $c['class_id'], $monday, [$c['enlistment_id'] => 'PRESENT']);
            $t->assertEquals(['saved' => 0, 'changed' => 0], $same, 'Nothing changed');

            $late = Attendance::record($c['teacher'], $c['class_id'], $monday, [$c['enlistment_id'] => 'LATE'], [$c['enlistment_id'] => 'Arrived 8:20']);
            $t->assertEquals(['saved' => 0, 'changed' => 1], $late);

            $rows = Database::select('SELECT * FROM attendance WHERE class_enlistment_id = ?', [$c['enlistment_id']]);
            $t->assertEquals(1, count($rows), 'One row per student per day');
            $t->assertEquals('LATE', $rows[0]['status']);
            $t->assertEquals('Arrived 8:20', $rows[0]['remarks']);

            $t->assertEquals(2, (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'attendance.recorded' AND target_id = ? AND user_id = ?", [$c['class_id'], $c['teacher']->id]), 'One audit entry per save that changed something');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a future date, a date outside the term, and a day the class does not meet are refused' => function (TestRunner $t): void {
        try {
            $c = attendanceClass();
            $entry = [$c['enlistment_id'] => 'PRESENT'];
            $term = Database::selectOne('SELECT * FROM terms WHERE id = ?', [currentTermId()]);

            $nextMonday = (new DateTimeImmutable(lastWeekday(1)))->modify('+7 days')->format('Y-m-d');
            $t->assertContains('future date', attendanceRefusal(static fn () => Attendance::record($c['teacher'], $c['class_id'], $nextMonday, $entry)));

            $beforeTerm = (new DateTimeImmutable($term['start_date']))->modify('-7 days')->format('Y-m-d');
            $t->assertContains('outside the term', attendanceRefusal(static fn () => Attendance::record($c['teacher'], $c['class_id'], $beforeTerm, $entry)));

            $tuesday = (new DateTimeImmutable(lastWeekday(1)))->modify(lastWeekday(1) === date('Y-m-d') ? '-6 days' : '+1 day')->format('Y-m-d');
            $t->assertContains('does not meet on Tuesdays', attendanceRefusal(static fn () => Attendance::record($c['teacher'], $c['class_id'], $tuesday, $entry)));

            $t->assertContains('valid date', attendanceRefusal(static fn () => Attendance::record($c['teacher'], $c['class_id'], '2026-02-30', $entry)));
            $t->assertEquals(0, (int) Database::scalar('SELECT COUNT(*) FROM attendance WHERE class_enlistment_id = ?', [$c['enlistment_id']]));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'only the class teacher records, for enlisted students, with a known status' => function (TestRunner $t): void {
        try {
            $c = attendanceClass();
            $monday = lastWeekday(1);

            $t->assertEquals(404, statusOf(static fn () => Attendance::record(makeUser(User::ROLE_TEACHER), $c['class_id'], $monday, [$c['enlistment_id'] => 'PRESENT'])));
            $t->assertEquals(403, statusOf(static fn () => Attendance::record($c['registrar'], $c['class_id'], $monday, [$c['enlistment_id'] => 'PRESENT'])));

            $errors = fieldErrors(static fn () => Attendance::record($c['teacher'], $c['class_id'], $monday, [$c['enlistment_id'] => 'HOLIDAY']));
            $t->assertTrue(isset($errors["attendance.{$c['enlistment_id']}"]), 'An unknown status');

            $errors = fieldErrors(static fn () => Attendance::record($c['teacher'], $c['class_id'], $monday, [$c['enlistment_id'] => 'PRESENT', 999999999 => 'ABSENT']));
            $t->assertTrue(isset($errors['attendance.999999999']), 'A student not in the class');
            $t->assertEquals(0, (int) Database::scalar('SELECT COUNT(*) FROM attendance WHERE class_enlistment_id = ?', [$c['enlistment_id']]), 'All or nothing');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },
];
