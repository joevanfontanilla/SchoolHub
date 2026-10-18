<?php
/**
 * Withdrawing a student and reinstating them (docs/26, "Withdrawal"): the enrollment is kept, classes are dropped, the seat is
 * freed, money is untouched, only the registrar may do it, and the administrator can delete the account afterwards.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\Router;
use App\Models\User;
use App\Services\EIdService;
use App\Services\UserAdminService;
use App\Services\WithdrawalService;

/** Make the enrollment's school year active and current, returning the year as it was. @return array<string,mixed> */
function wdActivateYear(int $enrollmentId): array
{
    $year = Database::selectOne(
        'SELECT ay.* FROM academic_years ay JOIN terms t ON t.academic_year_id = ay.id JOIN enrollments e ON e.term_id = t.id WHERE e.id = ?',
        [$enrollmentId]
    );
    Database::update('academic_years', ['is_active' => 1, 'end_date' => date('Y-m-d', strtotime('+1 year'))], ['id' => $year['id']]);

    return $year;
}

function wdRestoreYear(?array $year): void
{
    if ($year !== null) {
        Database::update('academic_years', ['is_active' => $year['is_active'], 'end_date' => $year['end_date']], ['id' => $year['id']]);
    }
}

return [
    'withdrawing keeps the enrollment and the money, drops the classes, frees the seat, and tells everyone' => function (TestRunner $t): void {
        $year = null;
        try {
            $e = enrolledStudent();
            $year = wdActivateYear($e['enrollment_id']);
            $registrar = $e['registrar'];
            $paidBefore = Database::scalar('SELECT total_paid FROM fee_assessments WHERE application_id = ?', [$e['application_id']]);

            $seats = static fn (): int => (int) Database::scalar("SELECT COUNT(*) FROM enrollments WHERE section_id = ? AND status = 'ENROLLED'", [$e['section_id']]);
            $t->assertEquals(1, $seats());
            $t->assertTrue(EIdService::enrolledNow($e['student']->id));

            $t->assertTrue(isset(fieldErrors(static fn () => WithdrawalService::withdraw($registrar, $e['enrollment_id'], 'no'))['reason']), 'A reason is required');
            $t->assertEquals('ENROLLED', Database::scalar('SELECT status FROM enrollments WHERE id = ?', [$e['enrollment_id']]), 'A refused attempt changes nothing');

            WithdrawalService::withdraw($registrar, $e['enrollment_id'], 'Transferred to another school');

            $row = Database::selectOne('SELECT * FROM enrollments WHERE id = ?', [$e['enrollment_id']]);
            $t->assertEquals('WITHDRAWN', $row['status'], 'The row stays, marked withdrawn');
            $t->assertEquals($registrar->id, (int) $row['withdrawn_by']);
            $t->assertEquals('Transferred to another school', $row['withdraw_reason']);
            $t->assertNotNull($row['withdrawn_at']);
            $t->assertEquals(0, $seats(), 'The seat is freed');
            $t->assertEquals(0, (int) Database::scalar("SELECT COUNT(*) FROM class_enlistments WHERE enrollment_id = ? AND status = 'ENLISTED'", [$e['enrollment_id']]), 'No class is still enlisted');
            $t->assertEquals('ENROLLED', Database::scalar('SELECT enrollment_state FROM enrollment_applications WHERE id = ?', [$e['application_id']]), 'The application keeps its state, so fees keep working');
            $t->assertEquals($paidBefore, Database::scalar('SELECT total_paid FROM fee_assessments WHERE application_id = ?', [$e['application_id']]), 'Payments are untouched');
            $t->assertFalse(EIdService::enrolledNow($e['student']->id), 'The e-ID is no longer valid');

            $t->assertNotNull(Database::selectOne("SELECT id FROM enrollment_status_history WHERE application_id = ? AND axis = 'enrollment' AND previous_value = 'ENROLLED' AND new_value = 'WITHDRAWN' AND remarks = 'Transferred to another school'", [$e['application_id']]), 'History row');
            $t->assertNotNull(Database::selectOne("SELECT id FROM audit_logs WHERE action = 'enrollment.withdrawn' AND target_id = ? AND remarks = 'Transferred to another school'", [$e['enrollment_id']]), 'Audit row');
            $t->assertNotNull(Database::selectOne("SELECT id FROM notifications WHERE user_id = ? AND type = 'enrollment.withdrawn'", [$e['student']->id]), 'The student is told');

            $t->assertThrows(static fn () => WithdrawalService::withdraw($registrar, $e['enrollment_id'], 'Withdrawing again'), 'Only an enrolled');
            $t->assertEquals($e['enrollment_id'], (int) WithdrawalService::withdrawnFor($e['student_id'])['id'], 'The record page finds it');
        } finally {
            wdRestoreYear($year);
            deleteTestUsers();
        }
    },

    'only the registrar can withdraw or reinstate; the cashier and the administrator cannot' => function (TestRunner $t): void {
        $year = null;
        try {
            $e = enrolledStudent();
            $year = wdActivateYear($e['enrollment_id']);
            foreach ([User::ROLE_CASHIER, User::ROLE_SUPER_ADMIN, User::ROLE_TEACHER, User::ROLE_STUDENT] as $role) {
                $t->assertEquals(403, statusOf(static fn () => WithdrawalService::withdraw(makeUser($role), $e['enrollment_id'], 'Not my job to do')), "{$role} cannot withdraw");
            }
            $t->assertEquals('ENROLLED', Database::scalar('SELECT status FROM enrollments WHERE id = ?', [$e['enrollment_id']]));
            $t->assertEquals(404, statusOf(static fn () => WithdrawalService::withdraw($e['registrar'], PHP_INT_MAX, 'Nobody is there')));

            WithdrawalService::withdraw($e['registrar'], $e['enrollment_id'], 'Left the school');
            $t->assertEquals(403, statusOf(static fn () => WithdrawalService::reinstate(makeUser(User::ROLE_CASHIER), $e['enrollment_id'], 'Cashier trying')), 'Only the registrar reinstates');
        } finally {
            wdRestoreYear($year);
            deleteTestUsers();
        }
    },

    'reinstating brings the classes back, and is refused when the year ended or the section is full' => function (TestRunner $t): void {
        $year = null;
        try {
            $e = enrolledStudent();
            $year = wdActivateYear($e['enrollment_id']);
            $registrar = $e['registrar'];
            $enlisted = (int) Database::scalar("SELECT COUNT(*) FROM class_enlistments WHERE enrollment_id = ? AND status = 'ENLISTED'", [$e['enrollment_id']]);

            $t->assertThrows(static fn () => WithdrawalService::reinstate($registrar, $e['enrollment_id'], 'Not withdrawn yet'), 'Only a withdrawn');
            WithdrawalService::withdraw($registrar, $e['enrollment_id'], 'Left the school');

            // Section full: refused, and nothing changes.
            Database::update('sections', ['capacity' => 0], ['id' => $e['section_id']]);
            $t->assertContains('full', (string) WithdrawalService::withdrawnFor($e['student_id'])['reinstate_problem']);
            $t->assertThrows(static fn () => WithdrawalService::reinstate($registrar, $e['enrollment_id'], 'Back to class'), 'full');
            Database::update('sections', ['capacity' => 40], ['id' => $e['section_id']]);

            // Year over: refused.
            Database::update('academic_years', ['end_date' => date('Y-m-d', strtotime('-1 day'))], ['id' => $year['id']]);
            $t->assertThrows(static fn () => WithdrawalService::reinstate($registrar, $e['enrollment_id'], 'Back to class'), 'no longer active');
            Database::update('academic_years', ['end_date' => date('Y-m-d', strtotime('+1 year'))], ['id' => $year['id']]);

            $t->assertTrue(isset(fieldErrors(static fn () => WithdrawalService::reinstate($registrar, $e['enrollment_id'], 'no'))['reason']), 'A reason is required');
            WithdrawalService::reinstate($registrar, $e['enrollment_id'], 'Withdrawn by mistake');

            $row = Database::selectOne('SELECT * FROM enrollments WHERE id = ?', [$e['enrollment_id']]);
            $t->assertEquals('ENROLLED', $row['status']);
            $t->assertNull($row['withdrawn_at']);
            $t->assertNull($row['withdraw_reason']);
            $t->assertEquals($enlisted, (int) Database::scalar("SELECT COUNT(*) FROM class_enlistments WHERE enrollment_id = ? AND status = 'ENLISTED'", [$e['enrollment_id']]), 'The same classes are back');
            $t->assertTrue(EIdService::enrolledNow($e['student']->id), 'Valid again');
            $t->assertNotNull(Database::selectOne("SELECT id FROM audit_logs WHERE action = 'enrollment.reinstated' AND target_id = ?", [$e['enrollment_id']]));
            $t->assertNotNull(Database::selectOne("SELECT id FROM notifications WHERE user_id = ? AND type = 'enrollment.reinstated'", [$e['student']->id]));
        } finally {
            wdRestoreYear($year);
            deleteTestUsers();
        }
    },

    'a class dropped on its own before the withdrawal stays dropped after reinstating' => function (TestRunner $t): void {
        $year = null;
        try {
            $e = enrolledStudent();
            $year = wdActivateYear($e['enrollment_id']);
            $first = Database::selectOne("SELECT id FROM class_enlistments WHERE enrollment_id = ? AND status = 'ENLISTED' LIMIT 1", [$e['enrollment_id']]);
            if ($first === null) {
                $t->assertTrue(true, 'The fixture section has no classes; nothing to compare');
                return;
            }
            App\Services\EnlistmentService::dropClass($e['registrar'], (int) $first['id'], 'Schedule clash');
            WithdrawalService::withdraw($e['registrar'], $e['enrollment_id'], 'Left the school');
            WithdrawalService::reinstate($e['registrar'], $e['enrollment_id'], 'Withdrawn by mistake');
            $t->assertEquals('DROPPED', Database::scalar('SELECT status FROM class_enlistments WHERE id = ?', [$first['id']]), 'Only classes dropped by the withdrawal return');
        } finally {
            wdRestoreYear($year);
            deleteTestUsers();
        }
    },

    'the administrator can delete a student account only after the registrar withdraws them' => function (TestRunner $t): void {
        $year = null;
        try {
            $e = enrolledStudent();
            $year = wdActivateYear($e['enrollment_id']);
            $admin = makeUser(User::ROLE_SUPER_ADMIN);

            $t->assertContains('registrar', (string) UserAdminService::deleteProblem($admin, User::find($e['student']->id)));
            WithdrawalService::withdraw($e['registrar'], $e['enrollment_id'], 'Left the school');
            $t->assertNull(UserAdminService::deleteProblem($admin, User::find($e['student']->id)), 'A withdrawn student can be deleted');

            UserAdminService::delete($admin, $e['student']->id, 'Duplicate account', $e['student']->username);
            $t->assertEquals('WITHDRAWN', Database::scalar('SELECT status FROM enrollments WHERE id = ?', [$e['enrollment_id']]), 'The enrollment record stays');
        } finally {
            wdRestoreYear($year);
            deleteTestUsers();
        }
    },

    'the withdraw and reinstate routes are the registrar\'s, and their forms ask for confirmation' => function (TestRunner $t): void {
        $router = new Router();
        require BASE_PATH . '/routes/web.php';
        $found = 0;
        foreach ($router->routes() as $route) {
            if (in_array($route['pattern'], ['/registrar/students/{id}/withdraw', '/registrar/students/{id}/reinstate'], true)) {
                $found++;
                $t->assertEquals('POST', $route['method']);
                $t->assertTrue(in_array('role:' . User::ROLE_REGISTRAR, $route['middleware'], true) && in_array('can:students.manage', $route['middleware'], true), $route['pattern'] . ' is the registrar\'s');
            }
        }
        $t->assertEquals(2, $found);

        $view = (string) file_get_contents(BASE_PATH . '/app/Views/registrar/students/show.php');
        foreach (['/withdraw', '/reinstate'] as $action) {
            $t->assertTrue(preg_match('#/registrar/students/\' \. \$id \. \'' . preg_quote($action, '#') . '\'\)\) \?>"[^>]*data-confirm=#s', $view) === 1, "The {$action} form asks for confirmation");
        }
    },
];
