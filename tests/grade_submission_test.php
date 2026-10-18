<?php
/**
 * Releasing grades (docs/27): a teacher saves a draft, submits a complete sheet, and a student sees the grades only after the registrar
 * confirms them. A returned sheet goes back to the teacher and is hidden again.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\Router;
use App\Models\User;
use App\Services\AcademicRecordService as Record;
use App\Services\GradeSubmissionService as Sub;
use App\Services\GradingService as Grading;

/** A class in the current term, taught by a fixture teacher, with one enrolled student. @return array<string,mixed> */
function subGradedClass(): array
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

function subSubmissionId(int $classId, int $periodId): int
{
    return (int) Database::scalar('SELECT id FROM grade_submissions WHERE class_schedule_id = ? AND grade_period_id = ?', [$classId, $periodId]);
}

/** The student's own view of the one class: its Prelim entry, and whether the period shows as pending. @return array{0:?array,1:bool} */
function subStudentView(array $c, int $periodId): array
{
    $class = Record::classesForEnrollment($c['enrollment_id'])[0];

    return [$class['period_grades'][$periodId] ?? null, isset($class['pending_periods'][$periodId])];
}

return [
    'a saved grade is a draft: the teacher and the registrar see it, the student does not' => function (TestRunner $t): void {
        try {
            $c = subGradedClass();
            withGradePeriods($c['term_id'], static function (array $periods) use ($t, $c): void {
                $prelim = (int) $periods['Prelim'];
                openPeriodForTest($prelim);
                $t->assertEquals('DRAFT', Sub::statusOf($c['class_id'], $prelim), 'Nothing saved yet reads as a draft');

                Grading::recordGrades($c['teacher'], $c['class_id'], $prelim, [$c['enlistment_id'] => '92.4']);
                $t->assertEquals('DRAFT', Sub::statusOf($c['class_id'], $prelim));
                $t->assertTrue(subSubmissionId($c['class_id'], $prelim) > 0, 'A submission row holds it back from the first save');

                [$entry, $pending] = subStudentView($c, $prelim);
                $t->assertNull($entry, 'The student sees nothing');
                $t->assertFalse($pending, 'And not even "pending release": a draft is the teacher\'s business');
                $own = Record::classesForEnrollment($c['enrollment_id'], true)[0];
                $t->assertEquals('1.40', $own['period_grades'][$prelim]['grade'], 'The registrar\'s view includes it');

                $teacherSheet = App\Models\ClassSchedule::gradeSheet($c['class_id'], $c['term_id']);
                $t->assertEquals('1.40', $teacherSheet[0]['period_grades'][$prelim]['grade'], 'The teacher sees their own grades');
            });
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'submit needs a complete sheet and an open period, locks it, tells the registrars, and shows the student "pending release"' => function (TestRunner $t): void {
        try {
            $c = subGradedClass();
            withGradePeriods($c['term_id'], static function (array $periods) use ($t, $c): void {
                $prelim = (int) $periods['Prelim'];

                // Closed period, nothing saved: refused.
                $t->assertThrows(static fn () => Sub::submit($c['teacher'], $c['class_id'], $prelim), 'closed');
                openPeriodForTest($prelim);

                // Nothing for the student yet: refused, naming who.
                $t->assertThrows(static fn () => Sub::submit($c['teacher'], $c['class_id'], $prelim), 'no grade or mark yet');
                $t->assertEquals('DRAFT', Sub::statusOf($c['class_id'], $prelim));

                Grading::recordGrades($c['teacher'], $c['class_id'], $prelim, [$c['enlistment_id'] => '92.4']);
                $t->assertEquals(404, statusOf(static fn () => Sub::submit(makeUser(User::ROLE_TEACHER), $c['class_id'], $prelim)), 'Another teacher\'s class is a 404');
                $t->assertEquals(403, statusOf(static fn () => Sub::submit($c['registrar'], $c['class_id'], $prelim)), 'Only the class teacher submits');

                Sub::submit($c['teacher'], $c['class_id'], $prelim);
                $t->assertEquals('SUBMITTED', Sub::statusOf($c['class_id'], $prelim));
                $submissionId = subSubmissionId($c['class_id'], $prelim);
                $t->assertNotNull(Database::selectOne("SELECT id FROM audit_logs WHERE action = 'grades.submitted' AND target_id = ?", [$submissionId]), 'Audited');
                $t->assertTrue((int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE type = 'grades.submitted' AND link_url = ?", ['/registrar/grading/submissions/' . $submissionId]) >= 1, 'The registrars are told');

                // Locked for the teacher.
                $t->assertThrows(static fn () => Grading::recordGrades($c['teacher'], $c['class_id'], $prelim, [$c['enlistment_id'] => '80']), 'submitted to the registrar');
                $t->assertThrows(static fn () => Sub::submit($c['teacher'], $c['class_id'], $prelim), 'already submitted');
                $t->assertEquals('1.40', Database::scalar('SELECT grade FROM grades WHERE class_enlistment_id = ?', [$c['enlistment_id']]), 'Nothing changed');

                [$entry, $pending] = subStudentView($c, $prelim);
                $t->assertNull($entry, 'Still hidden from the student');
                $t->assertTrue($pending, 'But the student sees "pending release"');
            });
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'confirming releases the grades, tells the teacher and the student, and only the registrar can' => function (TestRunner $t): void {
        try {
            $c = subGradedClass();
            withGradePeriods($c['term_id'], static function (array $periods) use ($t, $c): void {
                $prelim = (int) $periods['Prelim'];
                openPeriodForTest($prelim);
                Grading::recordGrades($c['teacher'], $c['class_id'], $prelim, [$c['enlistment_id'] => '92.4']);
                $submissionId = 0;

                $t->assertThrows(static fn () => Sub::confirm($c['registrar'], subSubmissionId($c['class_id'], $prelim)), 'Only a submitted');
                Sub::submit($c['teacher'], $c['class_id'], $prelim);
                $submissionId = subSubmissionId($c['class_id'], $prelim);

                foreach ([User::ROLE_TEACHER, User::ROLE_CASHIER, User::ROLE_SUPER_ADMIN, User::ROLE_STUDENT] as $role) {
                    $t->assertEquals(403, statusOf(static fn () => Sub::confirm(makeUser($role), $submissionId)), "{$role} cannot confirm");
                }
                $t->assertEquals('SUBMITTED', Sub::statusOf($c['class_id'], $prelim));

                Sub::confirm($c['registrar'], $submissionId);
                $t->assertEquals('CONFIRMED', Sub::statusOf($c['class_id'], $prelim));
                [$entry, $pending] = subStudentView($c, $prelim);
                $t->assertEquals('1.40', $entry['grade'] ?? null, 'Released: the student sees it');
                $t->assertFalse($pending);
                $t->assertNotNull(Database::selectOne("SELECT id FROM audit_logs WHERE action = 'grades.confirmed' AND target_id = ?", [$submissionId]), 'Audited');
                $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'grades.confirmed'", [$c['teacher']->id]), 'The teacher is told');
                $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'grades.released'", [$c['student']->id]), 'The student is told');

                // A confirmed sheet is locked for the teacher.
                $t->assertThrows(static fn () => Grading::recordGrades($c['teacher'], $c['class_id'], $prelim, [$c['enlistment_id'] => '80']), 'confirmed');
                $t->assertThrows(static fn () => Sub::confirm($c['registrar'], $submissionId), 'Only a submitted');
            });
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'returning hides the grades again, needs a reason, tells the teacher, and the teacher can correct and resubmit even after the period closed' => function (TestRunner $t): void {
        try {
            $c = subGradedClass();
            withGradePeriods($c['term_id'], static function (array $periods) use ($t, $c): void {
                $prelim = (int) $periods['Prelim'];
                openPeriodForTest($prelim);
                Grading::recordGrades($c['teacher'], $c['class_id'], $prelim, [$c['enlistment_id'] => '92.4']);
                Sub::submit($c['teacher'], $c['class_id'], $prelim);
                $submissionId = subSubmissionId($c['class_id'], $prelim);
                Sub::confirm($c['registrar'], $submissionId);

                $errors = fieldErrors(static fn () => Sub::returnToTeacher($c['registrar'], $submissionId, 'no'));
                $t->assertTrue(isset($errors['reason']), 'A reason is required');
                $t->assertEquals(403, statusOf(static fn () => Sub::returnToTeacher($c['teacher'], $submissionId, 'Trying to return my own')), 'Only the registrar returns');

                Sub::returnToTeacher($c['registrar'], $submissionId, 'The quiz scores were entered wrongly');
                $t->assertEquals('RETURNED', Sub::statusOf($c['class_id'], $prelim));
                [$entry] = subStudentView($c, $prelim);
                $t->assertNull($entry, 'Hidden from the student again');
                $note = Database::selectOne("SELECT body FROM notifications WHERE user_id = ? AND type = 'grades.returned'", [$c['teacher']->id]);
                $t->assertContains('The quiz scores were entered wrongly', (string) ($note['body'] ?? ''), 'The teacher is told why');

                // The period closes meanwhile: a returned sheet is still the teacher's to correct.
                Database::update('grade_periods', ['is_open' => 0], ['id' => $prelim]);
                Grading::recordGrades($c['teacher'], $c['class_id'], $prelim, [$c['enlistment_id'] => '88']);
                $t->assertEquals('1.70', Database::scalar('SELECT grade FROM grades WHERE class_enlistment_id = ?', [$c['enlistment_id']]), 'Corrected');
                Sub::submit($c['teacher'], $c['class_id'], $prelim);
                $t->assertEquals('SUBMITTED', Sub::statusOf($c['class_id'], $prelim));
                [$entry] = subStudentView($c, $prelim);
                $t->assertNull($entry, 'Still hidden until confirmed again');

                Sub::confirm($c['registrar'], $submissionId);
                [$entry] = subStudentView($c, $prelim);
                $t->assertEquals('1.70', $entry['grade'] ?? null, 'The corrected grade is released');

                // A submitted sheet can be returned too.
                Sub::returnToTeacher($c['registrar'], $submissionId, 'Please check the second student');
                Sub::submit($c['teacher'], $c['class_id'], $prelim);
                Sub::returnToTeacher($c['registrar'], $submissionId, 'Returned before confirming');
                $t->assertEquals('RETURNED', Sub::statusOf($c['class_id'], $prelim));
            });
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'only confirmed grades count toward passing, the final, and prerequisites' => function (TestRunner $t): void {
        try {
            $c = subGradedClass();
            $subjectId = (int) Database::scalar('SELECT subject_id FROM class_schedules WHERE id = ?', [$c['class_id']]);
            withGradePeriods($c['term_id'], static function (array $periods) use ($t, $c, $subjectId): void {
                foreach ($periods as $id) {
                    openPeriodForTest((int) $id);
                    Grading::recordGrades($c['teacher'], $c['class_id'], (int) $id, [$c['enlistment_id'] => '90']);
                }
                $t->assertEquals([], Record::passedSubjectIds($c['student_id']), 'Draft grades do not pass a subject');
                $t->assertEquals('IN_PROGRESS', Record::classesForEnrollment($c['enrollment_id'])[0]['final']['remark'], 'And give the student no final');
                $t->assertEquals('PASSED', Record::classesForEnrollment($c['enrollment_id'], true)[0]['final']['remark'], 'The registrar\'s view computes it');

                foreach ($periods as $id) {
                    Sub::submit($c['teacher'], $c['class_id'], (int) $id);
                    Sub::confirm($c['registrar'], subSubmissionId($c['class_id'], (int) $id));
                }
                $t->assertEquals([$subjectId], Record::passedSubjectIds($c['student_id']), 'Confirmed grades pass it');
                $t->assertEquals('1.50', Record::classesForEnrollment($c['enrollment_id'])[0]['final']['grade']);
            });
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'confirming several at once releases the submitted ones and skips the rest' => function (TestRunner $t): void {
        try {
            $c = subGradedClass();
            withGradePeriods($c['term_id'], static function (array $periods) use ($t, $c): void {
                $prelim = (int) $periods['Prelim'];
                $midterm = (int) $periods['Midterm'];
                openPeriodForTest($prelim);
                openPeriodForTest($midterm);
                Grading::recordGrades($c['teacher'], $c['class_id'], $prelim, [$c['enlistment_id'] => '92.4']);
                Grading::recordGrades($c['teacher'], $c['class_id'], $midterm, [$c['enlistment_id'] => '88']);
                Sub::submit($c['teacher'], $c['class_id'], $prelim);

                $ids = [subSubmissionId($c['class_id'], $prelim), subSubmissionId($c['class_id'], $midterm), PHP_INT_MAX];
                $t->assertEquals(1, Sub::confirmMany($c['registrar'], $ids), 'Only the submitted one; a draft and a missing id are skipped');
                $t->assertEquals('CONFIRMED', Sub::statusOf($c['class_id'], $prelim));
                $t->assertEquals('DRAFT', Sub::statusOf($c['class_id'], $midterm));
                $t->assertEquals(403, statusOf(static fn () => Sub::confirmMany($c['teacher'], $ids)));

                $listed = array_column(Sub::listing('CONFIRMED'), 'id');
                $t->assertTrue(in_array(subSubmissionId($c['class_id'], $prelim), array_map('intval', $listed), true), 'The confirmed list shows it');
            });
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'every grade that existed before this step counts as confirmed, and the registrar\'s pages are gated' => function (TestRunner $t): void {
        $held = (int) Database::scalar(
            "SELECT COUNT(*) FROM grades g JOIN class_enlistments ce ON ce.id = g.class_enlistment_id
          LEFT JOIN grade_submissions gs ON gs.class_schedule_id = ce.class_schedule_id AND gs.grade_period_id = g.grade_period_id
              WHERE gs.id IS NULL"
        );
        $t->assertEquals(0, $held, 'No grade is without a submission row (migration 043 backfilled them as confirmed)');
        $t->assertEquals(0, (int) Database::scalar("SELECT COUNT(*) FROM grade_submissions WHERE status IN ('DRAFT', 'SUBMITTED', 'RETURNED')"), 'No leftover test sheets');

        $router = new Router();
        require BASE_PATH . '/routes/web.php';
        $patterns = [];
        foreach ($router->routes() as $route) {
            if (str_starts_with($route['pattern'], '/registrar/grading/submissions')) {
                $patterns[] = $route['method'] . ' ' . $route['pattern'];
                $t->assertTrue(in_array('role:' . User::ROLE_REGISTRAR, $route['middleware'], true) && in_array('can:grade_periods.manage', $route['middleware'], true), $route['pattern'] . ' is the registrar\'s');
            }
            if ($route['pattern'] === '/teacher/classes/{id}/grades/submit') {
                $patterns[] = 'teacher submit';
                $t->assertEquals('POST', $route['method']);
                $t->assertTrue(in_array('role:' . User::ROLE_TEACHER, $route['middleware'], true), 'Submitting is the teacher\'s');
            }
        }
        $t->assertEquals(6, count($patterns), 'List, review, confirm one, confirm many, return, and the teacher\'s submit');

        $sheet = (string) file_get_contents(BASE_PATH . '/app/Views/teacher/classes/grades.php');
        $t->assertContains('Save and submit to Registrar', $sheet);
        $review = (string) file_get_contents(BASE_PATH . '/app/Views/registrar/grading/submission.php');
        foreach (['/confirm', '/return'] as $action) {
            $t->assertTrue(preg_match('#/registrar/grading/submissions/\' \. \$id \. \'' . preg_quote($action, '#') . '\'\)\) \?>"[^>]*data-confirm=#s', $review) === 1, "{$action} asks for confirmation");
        }
    },
];
