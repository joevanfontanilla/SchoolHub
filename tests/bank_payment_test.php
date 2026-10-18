<?php
/**
 * Paying by bank (docs/14): the school's bank accounts, a bank payment that names the account it went to, and one reference number used once.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\Router;
use App\Core\UploadedFile;
use App\Models\Finance;
use App\Models\User;
use App\Services\BankAccountService as Banks;
use App\Services\FinanceService;

function bankInput(array $overrides = []): array
{
    return array_merge(['bank_name' => 'ZZ Test Bank', 'account_name' => 'Lyceum of the East Aurora', 'account_number' => '1234-5678-90', 'branch' => 'Baler', 'note' => 'Write the student\'s name on the slip.'], $overrides);
}

/** Make a fixture bank account (through the service, as the cashier would) and return its id. */
function bankAccount(User $cashier, array $overrides = []): int
{
    return Banks::save($cashier, null, bankInput($overrides));
}

function bankPay(User $student, int $assessmentId, array $overrides = []): int
{
    return FinanceService::submitPayment($student, $assessmentId, array_merge([
        'amount' => '100', 'method' => 'Bank transfer', 'reference_number' => 'REF' . random_int(100000, 999999), 'paid_on' => date('Y-m-d'),
    ], $overrides), UploadedFile::forTesting(fixtureFile('png'), 'slip.png'));
}

/** Remove the fixture bank accounts, after the payments that name them are gone. */
function dropBanks(): void
{
    deleteTestUsers();
    dropTestBankAccounts();
}

return [
    'the cashier or the administrator keeps the bank accounts: validated, audited, switched off rather than deleted, nobody else' => function (TestRunner $t): void {
        try {
            $cashier = makeUser(User::ROLE_CASHIER);
            $errors = fieldErrors(static fn () => Banks::save($cashier, null, ['bank_name' => '', 'account_name' => '', 'account_number' => 'abc']));
            $t->assertTrue(isset($errors['bank_name']) && isset($errors['account_name']) && isset($errors['account_number']), 'Bank, name, and number are required, and the number has digits');

            $id = bankAccount($cashier);
            $t->assertNotNull(Database::selectOne("SELECT id FROM audit_logs WHERE action = 'finance.bank_account_created' AND target_id = ?", [$id]));
            $t->assertTrue(isset(fieldErrors(static fn () => Banks::save($cashier, null, bankInput()))['account_number']), 'The same bank and number twice is refused');
            $other = bankAccount($cashier, ['account_number' => '9999 8888 77']);
            $t->assertTrue($other !== $id, 'Another number at the same bank is fine');

            Banks::save($cashier, $id, bankInput(['branch' => 'Maria Aurora']));
            $audit = Database::selectOne("SELECT old_values, new_values FROM audit_logs WHERE action = 'finance.bank_account_updated' AND target_id = ?", [$id]);
            $t->assertContains('Baler', (string) $audit['old_values']);
            $t->assertContains('Maria Aurora', (string) $audit['new_values']);

            $mine = static fn (): array => array_column(array_filter(Banks::active(), static fn (array $a) => in_array((int) $a['id'], [$id, $other], true)), 'id');
            $t->assertEquals(2, count($mine()));
            Banks::setActive($cashier, $other, false);
            $t->assertEquals([$id], array_map('intval', array_values($mine())), 'A switched-off account is not offered');
            $t->assertEquals(2, count(array_filter(Banks::all(), static fn (array $a) => in_array((int) $a['id'], [$id, $other], true))), 'But it is still on the list');
            $t->assertNotNull(Database::selectOne("SELECT id FROM audit_logs WHERE action = 'finance.bank_account_deactivated' AND target_id = ?", [$other]));

            Banks::setActive(makeUser(User::ROLE_SUPER_ADMIN), $id, false);
            $t->assertEquals(0, count($mine()), 'The administrator may switch accounts off too');
            Banks::setActive(makeUser(User::ROLE_SUPER_ADMIN), $id, true);
            foreach ([User::ROLE_REGISTRAR, User::ROLE_TEACHER, User::ROLE_STUDENT] as $role) {
                $t->assertEquals(403, statusOf(static fn () => Banks::save(makeUser($role), null, bankInput(['account_number' => '5555 4444 33']))), "{$role} cannot keep bank accounts");
            }
            $t->assertEquals(404, statusOf(static fn () => Banks::setActive($cashier, PHP_INT_MAX, true)));
        } finally {
            dropBanks();
        }
    },

    'a student\'s bank payment names the school bank account, once any is active; GCash does not, and nothing is asked before setup' => function (TestRunner $t): void {
        try {
            [$student, , $applicationId] = approvedApplication();
            $assessmentId = (int) Finance::assessmentForApplication($applicationId)['id'];
            $cashier = makeUser(User::ROLE_CASHIER);

            // Before the school has an active bank account, a bank payment is accepted without one (the existing behaviour).
            $activeBefore = count(Banks::active());
            if ($activeBefore === 0) {
                $id = bankPay($student, $assessmentId);
                $t->assertNull(Database::scalar('SELECT bank_account_id FROM payment_submissions WHERE id = ?', [$id]));
            }

            $bank = bankAccount($cashier);
            $errors = fieldErrors(static fn () => bankPay($student, $assessmentId));
            $t->assertTrue(isset($errors['bank_account_id']), 'A bank payment must name the bank account');
            $errors = fieldErrors(static fn () => bankPay($student, $assessmentId, ['bank_account_id' => PHP_INT_MAX]));
            $t->assertTrue(isset($errors['bank_account_id']), 'And it must be a real one');

            $gcash = bankPay($student, $assessmentId, ['method' => 'GCash']);
            $t->assertNull(Database::scalar('SELECT bank_account_id FROM payment_submissions WHERE id = ?', [$gcash]), 'GCash needs no bank account');

            $id = bankPay($student, $assessmentId, ['method' => 'Bank deposit', 'bank_account_id' => $bank]);
            $t->assertEquals($bank, (int) Database::scalar('SELECT bank_account_id FROM payment_submissions WHERE id = ?', [$id]), 'The account is kept on the submission');
            $row = Finance::submission($id);
            $t->assertEquals('ZZ Test Bank', $row['bank_name']);
            $t->assertEquals('Bank deposit · ZZ Test Bank', method_text($row, 'method'), 'Shown beside the method');

            $another = bankAccount($cashier, ['bank_name' => 'ZZ Test Bank Three', 'account_number' => '7777 6666 55']);
            Banks::setActive($cashier, $bank, false);
            $t->assertTrue(isset(fieldErrors(static fn () => bankPay($student, $assessmentId, ['bank_account_id' => $bank, 'reference_number' => 'OFF-1']))['bank_account_id']), 'A switched-off account cannot be chosen');
            bankPay($student, $assessmentId, ['bank_account_id' => $another, 'reference_number' => 'OFF-2']);
        } finally {
            dropBanks();
        }
    },

    'the same reference number for the same bank account is used once, and a rejected one can be sent again' => function (TestRunner $t): void {
        try {
            [$student, , $applicationId] = approvedApplication();
            $assessmentId = (int) Finance::assessmentForApplication($applicationId)['id'];
            $cashier = makeUser(User::ROLE_CASHIER);
            $first = bankAccount($cashier);
            $second = bankAccount($cashier, ['bank_name' => 'ZZ Test Bank Two', 'account_number' => '4444 3333 22']);

            $id = bankPay($student, $assessmentId, ['bank_account_id' => $first, 'reference_number' => 'DUP-001']);
            $errors = fieldErrors(static fn () => bankPay($student, $assessmentId, ['bank_account_id' => $first, 'reference_number' => 'DUP-001']));
            $t->assertTrue(isset($errors['reference_number']), 'The same reference at the same bank is refused');
            bankPay($student, $assessmentId, ['bank_account_id' => $second, 'reference_number' => 'DUP-001']);
            $t->assertTrue(true, 'The same reference at another bank is a different payment');

            FinanceService::rejectSubmission($cashier, $id, 'The slip was unreadable');
            bankPay($student, $assessmentId, ['bank_account_id' => $first, 'reference_number' => 'DUP-001']);
            $t->assertTrue(true, 'After a rejection the reference can be sent again');

            // The cashier's window: a bank payment needs the account and a reference, and the reference is checked too.
            $errors = fieldErrors(static fn () => FinanceService::recordPayment($cashier, $assessmentId, ['amount' => '50', 'payment_method' => 'Bank deposit', 'bank_account_id' => $first]));
            $t->assertTrue(isset($errors['reference_number']), 'A bank payment at the window needs a reference');
            $errors = fieldErrors(static fn () => FinanceService::recordPayment($cashier, $assessmentId, ['amount' => '50', 'payment_method' => 'Bank deposit', 'reference_number' => 'WIN-1']));
            $t->assertTrue(isset($errors['bank_account_id']), 'And the bank account');
            $errors = fieldErrors(static fn () => FinanceService::recordPayment($cashier, $assessmentId, ['amount' => '50', 'payment_method' => 'Bank deposit', 'bank_account_id' => $first, 'reference_number' => 'DUP-001']));
            $t->assertTrue(isset($errors['reference_number']), 'A reference a pending submission already holds is refused at the window');

            $paid = FinanceService::recordPayment($cashier, $assessmentId, ['amount' => '50', 'payment_method' => 'Bank deposit', 'bank_account_id' => $first, 'reference_number' => 'WIN-1']);
            $payment = Finance::payment($paid['payment_id']);
            $t->assertEquals($first, (int) $payment['bank_account_id'], 'The account is on the payment');
            $t->assertEquals('Bank deposit · ZZ Test Bank', method_text($payment), 'And on the receipt');
            $errors = fieldErrors(static fn () => bankPay($student, $assessmentId, ['bank_account_id' => $first, 'reference_number' => 'WIN-1']));
            $t->assertTrue(isset($errors['reference_number']), 'A student cannot reuse a reference the window recorded');
        } finally {
            dropBanks();
        }
    },

    'verifying a bank payment puts the account on the payment, and refuses a reference another payment now holds' => function (TestRunner $t): void {
        try {
            [$student, , $applicationId] = approvedApplication();
            $assessmentId = (int) Finance::assessmentForApplication($applicationId)['id'];
            $cashier = makeUser(User::ROLE_CASHIER);
            $bank = bankAccount($cashier);

            $id = bankPay($student, $assessmentId, ['bank_account_id' => $bank, 'reference_number' => 'VER-1']);
            $result = FinanceService::approveSubmission($cashier, $id);
            $payment = Finance::payment($result['payment_id']);
            $t->assertEquals($bank, (int) $payment['bank_account_id'], 'The submission\'s account goes onto the payment');
            $t->assertEquals('Bank transfer · ZZ Test Bank', method_text($payment));

            // A counter payment with the same reference slipped in after the submission (made directly, as a race would): approving is refused.
            $second = bankPay($student, $assessmentId, ['bank_account_id' => $bank, 'reference_number' => 'VER-2']);
            Database::insert('payments', [
                'transaction_number' => 'PAY-ZZ-' . random_int(10000, 99999), 'receipt_number' => 'OR-ZZ-' . random_int(10000, 99999), 'fee_assessment_id' => $assessmentId,
                'student_id' => (int) Database::scalar('SELECT student_id FROM fee_assessments WHERE id = ?', [$assessmentId]), 'amount' => '10.00', 'payment_method' => 'Bank deposit',
                'bank_account_id' => $bank, 'reference_number' => 'VER-2', 'paid_at' => date('Y-m-d H:i:s'), 'cashier_user_id' => $cashier->id,
            ]);
            $t->assertThrows(static fn () => FinanceService::approveSubmission($cashier, $second), 'Another payment already uses this reference');
            $t->assertEquals('PENDING', Database::scalar('SELECT status FROM payment_submissions WHERE id = ?', [$second]), 'Nothing was verified');
        } finally {
            dropBanks();
        }
    },

    'the bank account pages are the cashier\'s and the administrator\'s, and the student sees the accounts to pay into' => function (TestRunner $t): void {
        $router = new Router();
        require BASE_PATH . '/routes/web.php';
        $cashier = $admin = 0;
        foreach ($router->routes() as $route) {
            if (str_starts_with($route['pattern'], '/cashier/bank-accounts')) {
                $cashier++;
                $t->assertTrue(in_array('role:' . User::ROLE_CASHIER, $route['middleware'], true) && in_array('can:fees.manage', $route['middleware'], true), $route['pattern'] . ' is the cashier\'s');
            }
            if (str_starts_with($route['pattern'], '/admin/bank-accounts')) {
                $admin++;
                $t->assertTrue(in_array('role:' . User::ROLE_SUPER_ADMIN, $route['middleware'], true) && in_array('can:settings.manage', $route['middleware'], true), $route['pattern'] . ' is the administrator\'s');
            }
        }
        $t->assertEquals(6, $cashier, 'List, add (2), edit (2), switch');
        $t->assertEquals(6, $admin);

        $student = (string) file_get_contents(BASE_PATH . '/app/Views/student/account.php');
        $t->assertContains('Pay by bank', $student);
        $t->assertContains('data-copy-text', $student, 'A Copy button for the account number');
        $t->assertContains("partial('bank-account-select'", $student);
        $verify = (string) file_get_contents(BASE_PATH . '/app/Views/cashier/payments/show.php');
        $t->assertContains('check this against the bank statement', $verify);
        $list = (string) file_get_contents(BASE_PATH . '/app/Views/cashier/bank-accounts/index.php');
        $t->assertTrue(preg_match('#/toggle\'\)\) \?>"[^>]*data-confirm=#s', $list) === 1, 'Switching an account off asks for confirmation');
    },
];
