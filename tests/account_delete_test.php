<?php
/**
 * Deleting an account (docs/07): anonymised and sign-in ends, records stay, the guards hold, restore returns it
 * Disabled, and erase works only when nothing refers to the account.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Router;
use App\Models\Finance;
use App\Models\User;
use App\Services\AuthService;
use App\Services\EIdService;
use App\Services\FinanceService;
use App\Services\PaymentPolicy;
use App\Services\UserAdminService;

/** @return array{0:User,1:array<string,mixed>,2:int} an enrolled student in the active year, the year as it was, and the application */
function delEnrolledStudent(): array
{
    [$student, , $applicationId] = approvedApplication();
    $cashier = makeUser(User::ROLE_CASHIER);
    $assessment = Finance::assessmentForApplication($applicationId);
    FinanceService::recordPayment($cashier, (int) $assessment['id'], ['amount' => FinanceService::money(PaymentPolicy::requiredCents($assessment)), 'payment_method' => 'Cash']);
    $year = Database::selectOne('SELECT ay.* FROM academic_years ay JOIN terms t ON t.academic_year_id = ay.id WHERE t.id = ?', [(int) $assessment['term_id']]);
    Database::update('academic_years', ['is_active' => 1, 'end_date' => date('Y-m-d', strtotime('+1 year'))], ['id' => $year['id']]);

    return [$student, $year, $applicationId];
}

return [
    'deleting an account anonymises it, ends sign-in and sessions, frees the username, and audits the reason' => function (TestRunner $t): void {
        try {
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            $teacher = makeUser(User::ROLE_TEACHER, 'GoodPass123');
            $username = $teacher->username;
            $email = $teacher->email;

            actingAs($teacher);
            $t->assertNotNull(Auth::user(), 'Signed in before');
            Database::insert('sessions', ['id' => 'del' . bin2hex(random_bytes(8)), 'user_id' => $teacher->id, 'last_activity' => time()]);

            $t->assertTrue(isset(fieldErrors(static fn () => UserAdminService::delete($admin, $teacher->id, 'no', $username))['reason']), 'A reason is required');
            $t->assertTrue(isset(fieldErrors(static fn () => UserAdminService::delete($admin, $teacher->id, 'Left the school', 'wrong-name'))['confirm_username']), 'The username must be typed');
            $t->assertEquals(User::STATUS_ACTIVE, User::find($teacher->id)->status, 'Nothing changed on a failed attempt');

            UserAdminService::delete($admin, $teacher->id, 'Left the school', $username);
            $gone = User::find($teacher->id);
            $t->assertEquals(User::STATUS_DELETED, $gone->status);
            $t->assertEquals('deleted-' . $teacher->id, $gone->username);
            $t->assertTrue($gone->email !== $email, 'The email is released too');
            $t->assertEquals('Test', $gone->first_name, 'The name stays, so old records still read correctly');
            $t->assertEquals(0, (int) Database::scalar('SELECT COUNT(*) FROM sessions WHERE user_id = ?', [$teacher->id]), 'Stored sessions are gone');

            actingAs($teacher);
            $t->assertNull(Auth::user(), 'The open session no longer counts');
            $t->assertFalse(AuthService::attempt($username, 'GoodPass123', '127.0.0.1', 'test')['ok'], 'The old username no longer signs in');
            $t->assertNull(User::findByLogin($username), 'And no account answers to it');
            actingAs(null);

            $audit = Database::selectOne("SELECT remarks FROM audit_logs WHERE action = 'user.deleted' AND target_id = ?", [$teacher->id]);
            $t->assertEquals('Left the school', $audit['remarks'] ?? null);

            $t->assertEquals([], array_filter(User::adminList([], 500)['rows'], static fn (array $r): bool => (int) $r['id'] === $teacher->id), 'Left out of the normal list');
            $t->assertEquals(1, count(array_filter(User::adminList(['status' => User::STATUS_DELETED], 500)['rows'], static fn (array $r): bool => (int) $r['id'] === $teacher->id)), 'Listed under Deleted');

            $t->assertThrows(static fn () => UserAdminService::delete($admin, $teacher->id, 'Again please', 'deleted-' . $teacher->id), 'already deleted');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the guards: not yourself, not the last active Super Admin, not an enrolled student, not a teacher with classes, not a section adviser; only a Super Admin' => function (TestRunner $t): void {
        $year = null;
        try {
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            $t->assertThrows(static fn () => UserAdminService::delete($admin, $admin->id, 'Testing myself', $admin->username), 'your own account');

            // Another active Super Admin exists, so the second can go; the very last cannot.
            $other = makeUser(User::ROLE_SUPER_ADMIN);
            $actives = (int) Database::scalar("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'Super Admin' AND u.status = 'Active'");
            if ($actives === 2) {
                $t->assertThrows(static fn () => UserAdminService::delete($other, $admin->id, 'Last one left', $admin->username), 'last active Super Admin');
            }

            $teacher = makeUser(User::ROLE_TEACHER);
            $adviser = makeUser(User::ROLE_TEACHER);
            [$student, $year, $applicationId] = delEnrolledStudent();
            $sectionId = makeSectionFor($applicationId);
            Database::update('sections', ['adviser_user_id' => $adviser->id], ['id' => $sectionId]);
            $t->assertContains('advises an active section', (string) UserAdminService::deleteProblem($admin, $adviser));
            Database::update('sections', ['is_active' => 0], ['id' => $sectionId]);
            $t->assertNull(UserAdminService::deleteProblem($admin, $adviser), 'An inactive section no longer blocks');

            $t->assertTrue(EIdService::enrolledNow($student->id));
            $t->assertThrows(static fn () => UserAdminService::delete($admin, $student->id, 'Wants to leave', $student->username), 'currently enrolled');

            $t->assertEquals(403, statusOf(static fn () => UserAdminService::delete(makeUser(User::ROLE_REGISTRAR), $teacher->id, 'Not my job', $teacher->username)));
            $t->assertEquals(404, statusOf(static fn () => UserAdminService::delete($admin, PHP_INT_MAX, 'Nobody here', 'x')));
        } finally {
            if ($year !== null) {
                Database::update('academic_years', ['is_active' => $year['is_active'], 'end_date' => $year['end_date']], ['id' => $year['id']]);
            }
            deleteTestUsers();
        }
    },

    'a deleted student keeps their record, and their e-ID stops verifying once the year no longer covers them' => function (TestRunner $t): void {
        try {
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            $student = makeStudent();
            $studentId = (int) Database::scalar('SELECT id FROM students WHERE user_id = ?', [$student->id]);
            $t->assertTrue($studentId > 0);

            UserAdminService::delete($admin, $student->id, 'Duplicate account', $student->username);
            $t->assertEquals($studentId, (int) Database::scalar('SELECT id FROM students WHERE user_id = ?', [$student->id]), 'The student record stays');
            $t->assertFalse(EIdService::isValid(User::find($student->id)), 'A deleted account is never a valid e-ID');
        } finally {
            deleteTestUsers();
        }
    },

    'restore brings the account back Disabled with its own username, and refuses when the username is taken' => function (TestRunner $t): void {
        try {
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            $cashier = makeUser(User::ROLE_CASHIER, 'GoodPass123');
            $username = $cashier->username;
            $t->assertThrows(static fn () => UserAdminService::restore($admin, $cashier->id), 'Only a deleted account');

            UserAdminService::delete($admin, $cashier->id, 'Created by mistake', $username);
            // Someone takes the freed username in the meantime.
            $taker = makeUser(User::ROLE_TEACHER);
            Database::update('users', ['username' => $username], ['id' => $taker->id]);
            $t->assertThrows(static fn () => UserAdminService::restore($admin, $cashier->id), 'belongs to another account');
            Database::update('users', ['username' => 'taken-' . bin2hex(random_bytes(3))], ['id' => $taker->id]);

            UserAdminService::restore($admin, $cashier->id);
            $back = User::find($cashier->id);
            $t->assertEquals($username, $back->username);
            $t->assertEquals(User::STATUS_DISABLED, $back->status, 'Never straight back to Active');
            $t->assertNull($back->deleted_at);
            $t->assertFalse(AuthService::attempt($username, 'GoodPass123', '127.0.0.1', 'test')['ok'], 'Disabled until an administrator enables it');
            $t->assertNotNull(Database::selectOne("SELECT id FROM audit_logs WHERE action = 'user.restored' AND target_id = ?", [$cashier->id]));

            UserAdminService::enable($admin, $cashier->id);
            $t->assertTrue(AuthService::attempt($username, 'GoodPass123', '127.0.0.1', 'test')['ok'], 'Enabled on purpose');
        } finally {
            deleteTestUsers();
        }
    },

    'erase removes an account nothing refers to, and refuses one with records' => function (TestRunner $t): void {
        try {
            $admin = makeUser(User::ROLE_SUPER_ADMIN);

            $clean = makeUser(User::ROLE_TEACHER);
            $cleanName = $clean->username;
            $t->assertThrows(static fn () => UserAdminService::erase($admin, $clean->id, $cleanName), 'Delete the account first');
            UserAdminService::delete($admin, $clean->id, 'Test account', $cleanName);
            $t->assertTrue(isset(fieldErrors(static fn () => UserAdminService::erase($admin, $clean->id, 'wrong'))['confirm_username']), 'The original username must be typed');
            UserAdminService::erase($admin, $clean->id, $cleanName);
            $t->assertNull(User::find($clean->id), 'Gone for good');
            $t->assertNotNull(Database::selectOne("SELECT id FROM audit_logs WHERE action = 'user.erased' AND target_id = ?", [$clean->id]), 'The erase itself is audited');

            // A student always has a student record, and a cashier with a payment is referenced by it.
            $student = makeStudent();
            $studentName = $student->username;
            UserAdminService::delete($admin, $student->id, 'Duplicate account', $studentName);
            $t->assertThrows(static fn () => UserAdminService::erase($admin, $student->id, $studentName), 'Records still refer');
            $t->assertEquals(User::STATUS_DELETED, User::find($student->id)->status, 'It stays deleted');
        } finally {
            deleteTestUsers();
        }
    },

    'the delete, restore, and erase routes are the Super Admin\'s, and their buttons ask for confirmation' => function (TestRunner $t): void {
        $router = new Router();
        require BASE_PATH . '/routes/web.php';
        $found = 0;
        foreach ($router->routes() as $route) {
            if (in_array($route['pattern'], ['/admin/users/{id}/delete', '/admin/users/{id}/restore', '/admin/users/{id}/erase'], true)) {
                $found++;
                $t->assertEquals('POST', $route['method'], $route['pattern'] . ' changes data, so it is a POST');
                $t->assertTrue(in_array('role:' . User::ROLE_SUPER_ADMIN, $route['middleware'], true) && in_array('can:users.manage', $route['middleware'], true), $route['pattern'] . ' is the administrator\'s');
            }
        }
        $t->assertEquals(3, $found);

        $view = (string) file_get_contents(BASE_PATH . '/app/Views/admin/users/show.php');
        foreach (['/delete', '/restore', '/erase'] as $action) {
            $t->assertTrue(preg_match('#/admin/users/\' \. \$account->id \. \'' . preg_quote($action, '#') . '\'\)\) \?>"[^>]*data-confirm=#s', $view) === 1, "The {$action} form asks for confirmation");
        }
    },
];
