<?php
/**
 * The cashier's general ledger (docs/14): computed postings, the trial balance, account ledgers, the
 * chart of accounts, and the expense category on vouchers.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\Router;
use App\Models\Finance;
use App\Models\User;
use App\Services\DisbursementService;
use App\Services\FeeScheduleService;
use App\Services\FinanceService;
use App\Services\LedgerAccountService;
use App\Services\LedgerService;

const LEDGER_TEST_ACCOUNT = '5871';

function ledgerAccountId(string $code): int
{
    return (int) Database::scalar('SELECT id FROM ledger_accounts WHERE code = ?', [$code]);
}

/** Net debit balance of one account over all time up to today. */
function ledgerNet(string $code): int
{
    return LedgerService::accountLedger(ledgerAccountId($code), '2000-01-01', date('Y-m-d'))['closing'];
}

/** Remove what these tests add to the chart (after their users and vouchers are gone). */
function removeLedgerFixtures(): void
{
    deleteTestUsers();
    Database::query('DELETE FROM disbursement_vouchers WHERE payee LIKE ?', ['ZZT%']);
    Database::query('DELETE FROM ledger_accounts WHERE code = ? OR fee_key LIKE ?', [LEDGER_TEST_ACCOUNT, TEST_CODE . '%']);
    Database::query('DELETE FROM fee_types WHERE code LIKE ?', [TEST_CODE . '%']);
}

function ledgerVoucher(User $cashier, string $account, string $method = 'Cash', string $amount = '100.00', ?string $date = null): array
{
    return DisbursementService::record($cashier, [
        'payee' => 'ZZT Payee', 'particulars' => 'Test spending', 'amount' => $amount, 'payment_method' => $method,
        'disbursed_on' => $date ?? date('Y-m-d'), 'expense_account_id' => ledgerAccountId($account),
    ]);
}

return [
    'every posting is a balanced pair, and the trial balance balances, over any range' => function (TestRunner $t): void {
        try {
            [, , $applicationId] = approvedApplication();
            $cashier = makeUser(User::ROLE_CASHIER);
            $id = (int) Finance::assessmentForApplication($applicationId)['id'];
            FinanceService::adjust($cashier, $id, ['type' => 'SCHOLARSHIP', 'percent' => '25', 'reason' => 'Academic scholarship']);
            FinanceService::recordPayment($cashier, $id, ['amount' => '300', 'payment_method' => 'Cash']);
            ledgerVoucher($cashier, '5020');

            $bySource = [];
            foreach (LedgerService::postings(date('Y-m-d')) as $posting) {
                $key = $posting['source'] . '|' . $posting['ref'] . '|' . $posting['at'];
                $bySource[$key] = ($bySource[$key] ?? 0) + $posting['debit'] - $posting['credit'];
            }
            $t->assertEquals([], array_keys(array_filter($bySource, static fn ($net) => $net !== 0)), 'Each posting\'s debits equal its credits');

            foreach ([['2000-01-01', date('Y-m-d')], [date('Y-01-01'), date('Y-m-d')], [date('Y-m-d'), date('Y-m-d')], ['2000-01-01', '2000-12-31']] as [$from, $to]) {
                $report = LedgerService::trialBalance($from, $to);
                $t->assertTrue($report['balanced'], "Balanced for {$from} to {$to}");
                $t->assertEquals($report['totals']['debit'], $report['totals']['credit']);
            }
        } finally {
            actingAs(null);
            removeLedgerFixtures();
        }
    },

    'the ledger agrees with the books: receivables, cash, expenses, and revenue' => function (TestRunner $t): void {
        try {
            [, , $applicationId] = approvedApplication();
            $cashier = makeUser(User::ROLE_CASHIER);
            $id = (int) Finance::assessmentForApplication($applicationId)['id'];
            FinanceService::adjust($cashier, $id, ['type' => 'DISCOUNT', 'amount' => '120', 'reason' => 'Early enrollment discount']);
            FinanceService::adjust($cashier, $id, ['type' => 'OLD_ACCOUNT', 'amount' => '500', 'reason' => 'Balance from last term']);
            FinanceService::adjust($cashier, $id, ['type' => 'BRIDGING', 'amount' => '300', 'reason' => 'Bridging course']);
            FinanceService::adjust($cashier, $id, ['type' => 'OJT_FEE', 'amount' => '200', 'reason' => 'OJT placement']);
            FinanceService::adjust($cashier, $id, ['type' => 'ADDITIONAL_CHARGE', 'amount' => '50', 'reason' => 'Replacement ID']);
            $paid = FinanceService::recordPayment($cashier, $id, ['amount' => '1000', 'payment_method' => 'Cash']);
            FinanceService::recordPayment($cashier, $id, ['amount' => '400', 'payment_method' => 'GCash', 'reference_number' => 'G-1']);
            FinanceService::reversePayment($cashier, $paid['payment_id'], 'Recorded on the wrong account');
            ledgerVoucher($cashier, '5030', 'Cash', '75.25');
            ledgerVoucher($cashier, '5020', 'GCash', '40.00');

            // Student Receivables is what the accounts say students owe.
            $owed = FinanceService::cents((string) Database::scalar("SELECT COALESCE(SUM(balance), 0) FROM fee_assessments WHERE status = 'ACTIVE'"));
            $t->assertEquals($owed, ledgerNet(LedgerAccountService::RECEIVABLES), 'Student Receivables equals the sum of every account balance');

            // Cash on Hand and Cash in Bank are the payments and vouchers by method.
            $received = static fn (bool $cash) => FinanceService::cents((string) Database::scalar('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payment_method ' . ($cash ? '=' : '<>') . " 'Cash'"));
            $paidOut = static fn (bool $cash) => FinanceService::cents((string) Database::scalar('SELECT COALESCE(SUM(amount), 0) FROM disbursement_vouchers WHERE payment_method ' . ($cash ? '=' : '<>') . " 'Cash'"));
            $t->assertEquals($received(true) - $paidOut(true), ledgerNet(LedgerAccountService::CASH), 'Cash on Hand: Cash receipts less Cash disbursements');
            $t->assertEquals($received(false) - $paidOut(false), ledgerNet(LedgerAccountService::BANK), 'Cash in Bank and E-wallets: everything else');

            // Expenses are the vouchers.
            $expenses = 0;
            foreach (Database::select("SELECT id FROM ledger_accounts WHERE type = 'EXPENSE'") as $row) {
                $expenses += LedgerService::accountLedger((int) $row['id'], '2000-01-01', date('Y-m-d'))['closing'];
            }
            $t->assertEquals(FinanceService::cents((string) Database::scalar('SELECT COALESCE(SUM(amount), 0) FROM disbursement_vouchers')), $expenses, 'Expense accounts equal the vouchers');

            // Revenue, contra-revenue, and opening balances are everything ever assessed or adjusted.
            $earned = 0;
            foreach (Database::select("SELECT id FROM ledger_accounts WHERE type IN ('REVENUE', 'CONTRA_REVENUE', 'EQUITY')") as $row) {
                $earned -= LedgerService::accountLedger((int) $row['id'], '2000-01-01', date('Y-m-d'))['closing'];
            }
            $billed = FinanceService::cents((string) Database::scalar('SELECT COALESCE(SUM(total_amount + total_adjustments), 0) FROM fee_assessments'));
            $t->assertEquals($billed, $earned, 'Revenue less discounts, plus opening balances, equals what was billed');
        } finally {
            actingAs(null);
            removeLedgerFixtures();
        }
    },

    'each event posts to the right accounts' => function (TestRunner $t): void {
        try {
            [, , $applicationId] = approvedApplication();
            $cashier = makeUser(User::ROLE_CASHIER);
            $id = (int) Finance::assessmentForApplication($applicationId)['id'];
            $assessment = Finance::assessment($id);

            $find = static function (string $code, string $refPrefix) use ($assessment): array {
                $ledger = LedgerService::accountLedger(ledgerAccountId($code), date('Y-m-d'), date('Y-m-d'));
                return array_values(array_filter($ledger['rows'], static fn ($r) => str_starts_with($r['ref'], $refPrefix)));
            };

            // The assessment: tuition goes to its fee-line account, against receivables.
            $tuitionAccount = (string) Database::scalar("SELECT code FROM ledger_accounts WHERE fee_key = 'TUITION'");
            $tuitionPosted = $find($tuitionAccount, $assessment['assessment_number']);
            $t->assertEquals(1, count($tuitionPosted), 'Tuition is posted to its own revenue account');
            $t->assertEquals(FinanceService::cents((string) Database::scalar("SELECT SUM(amount) FROM fee_items WHERE fee_assessment_id = ? AND basis = 'PER_UNIT'", [$id])), $tuitionPosted[0]['credit']);
            $t->assertEquals(FinanceService::cents($assessment['total_amount']), array_sum(array_column($find(LedgerAccountService::RECEIVABLES, $assessment['assessment_number']), 'debit')), 'Receivables are debited the whole assessment');

            FinanceService::adjust($cashier, $id, ['type' => 'SCHOLARSHIP', 'amount' => '200', 'reason' => 'Academic scholarship']);
            FinanceService::adjust($cashier, $id, ['type' => 'OLD_ACCOUNT', 'amount' => '500', 'reason' => 'Balance from last term']);
            FinanceService::adjust($cashier, $id, ['type' => 'BRIDGING', 'amount' => '300', 'reason' => 'Bridging course']);
            FinanceService::adjust($cashier, $id, ['type' => 'OJT_FEE', 'amount' => '150', 'reason' => 'OJT placement']);
            FinanceService::adjust($cashier, $id, ['type' => 'ADDITIONAL_CHARGE', 'amount' => '50', 'reason' => 'Replacement ID']);
            FinanceService::adjust($cashier, $id, ['type' => 'CORRECTION', 'amount' => '25', 'direction' => 'decrease', 'reason' => 'Rate correction']);

            $t->assertEquals(20000, $find(LedgerAccountService::DISCOUNTS, 'ADJ-')[0]['debit'], 'A scholarship debits Scholarships and Discounts');
            $t->assertEquals(50000, array_sum(array_column($find(LedgerAccountService::OPENING_BALANCES, 'ADJ-'), 'credit')), 'An old account credits Opening Balances, not revenue');
            $t->assertEquals(30000, array_sum(array_column($find(LedgerAccountService::BRIDGING, 'ADJ-'), 'credit')), 'Bridging credits Bridging Fees');
            $t->assertEquals(15000, array_sum(array_column($find(LedgerAccountService::OJT, 'ADJ-'), 'credit')), 'OJT credits OJT Fees');
            $other = $find(LedgerAccountService::OTHER_CHARGES, 'ADJ-');
            $t->assertEquals(5000, array_sum(array_column($other, 'credit')), 'An additional charge credits Other Charges');
            $t->assertEquals(2500, array_sum(array_column($other, 'debit')), 'A correction down debits Other Charges');

            $cash = FinanceService::recordPayment($cashier, $id, ['amount' => '100', 'payment_method' => 'Cash']);
            $gcash = FinanceService::recordPayment($cashier, $id, ['amount' => '60', 'payment_method' => 'GCash', 'reference_number' => 'G-2']);
            $t->assertEquals(10000, $find(LedgerAccountService::CASH, $cash['receipt_number'])[0]['debit'], 'A Cash payment debits Cash on Hand');
            $t->assertEquals(6000, $find(LedgerAccountService::BANK, $gcash['receipt_number'])[0]['debit'], 'A GCash payment debits Cash in Bank and E-wallets');
            $t->assertEquals(10000, $find(LedgerAccountService::RECEIVABLES, $cash['receipt_number'])[0]['credit'], 'And credits Student Receivables');

            FinanceService::reversePayment($cashier, $cash['payment_id'], 'Recorded on the wrong account');
            $reversal = $find(LedgerAccountService::CASH, 'Rev. ' . $cash['receipt_number']);
            $t->assertEquals(10000, $reversal[0]['credit'], 'A reversal credits the same cash account back');

            $categorized = ledgerVoucher($cashier, '5020', 'Cash', '80.00');
            $legacy = ledgerVoucher($cashier, '5900', 'GCash', '20.00');
            Database::query('UPDATE disbursement_vouchers SET expense_account_id = NULL WHERE id = ?', [$legacy['id']]); // a voucher from before categories
            $t->assertEquals(8000, $find('5020', $categorized['voucher_number'])[0]['debit'], 'A voucher debits its expense account');
            $t->assertEquals(8000, $find(LedgerAccountService::CASH, $categorized['voucher_number'])[0]['credit'], 'And credits Cash on Hand for a Cash voucher');
            $t->assertEquals(2000, $find(LedgerAccountService::UNCATEGORIZED, $legacy['voucher_number'])[0]['debit'], 'An uncategorized voucher debits Uncategorized Expense');
            $t->assertEquals(2000, $find(LedgerAccountService::BANK, $legacy['voucher_number'])[0]['credit'], 'And credits the bank and e-wallet account for GCash');
        } finally {
            actingAs(null);
            removeLedgerFixtures();
        }
    },

    'opening balances carry forward: the closing balance does not depend on where the range starts' => function (TestRunner $t): void {
        try {
            $cashier = makeUser(User::ROLE_CASHIER);
            ledgerVoucher($cashier, '5010', 'Cash', '500.00', date('Y-m-d', strtotime('-40 days')));
            ledgerVoucher($cashier, '5010', 'Cash', '200.00', date('Y-m-d', strtotime('-5 days')));
            ledgerVoucher($cashier, '5010', 'Cash', '50.00');

            $closing = static fn (string $from) => array_column(LedgerService::trialBalance($from, date('Y-m-d'))['rows'], 'closing', 'code');
            $wide = $closing('2000-01-01');
            foreach ([date('Y-m-d', strtotime('-30 days')), date('Y-m-d', strtotime('-1 day')), date('Y-m-d')] as $from) {
                $t->assertEquals($wide, $closing($from), "Same closing balances starting {$from}");
            }

            $ledger = LedgerService::accountLedger(ledgerAccountId('5010'), date('Y-m-d', strtotime('-30 days')), date('Y-m-d'));
            $t->assertTrue($ledger['opening'] >= 50000, 'The voucher from 40 days ago is in the opening balance');
            $t->assertEquals($ledger['opening'] + $ledger['debit'] - $ledger['credit'], $ledger['closing']);
            $t->assertEquals($ledger['closing'], end($ledger['rows'])['balance'], 'The last running balance is the closing balance');
            $t->assertTrue(count($ledger['rows']) >= 2, 'The two later vouchers are listed');
        } finally {
            actingAs(null);
            removeLedgerFixtures();
        }
    },

    'a new voucher must be charged to an active expense account' => function (TestRunner $t): void {
        try {
            $cashier = makeUser(User::ROLE_CASHIER);
            $base = ['payee' => 'ZZT Payee', 'particulars' => 'Test', 'amount' => '10', 'payment_method' => 'Cash', 'disbursed_on' => date('Y-m-d')];

            $t->assertTrue(isset(fieldErrors(fn () => DisbursementService::record($cashier, $base))['expense_account_id']), 'No account is refused');
            $t->assertTrue(isset(fieldErrors(fn () => DisbursementService::record($cashier, $base + ['expense_account_id' => (string) ledgerAccountId(LedgerAccountService::CASH)]))['expense_account_id']), 'An asset account is refused');
            $t->assertTrue(isset(fieldErrors(fn () => DisbursementService::record($cashier, $base + ['expense_account_id' => '99999999']))['expense_account_id']), 'An unknown account is refused');
            $uncategorized = (string) ledgerAccountId(LedgerAccountService::UNCATEGORIZED);
            $t->assertTrue(isset(fieldErrors(fn () => DisbursementService::record($cashier, $base + ['expense_account_id' => $uncategorized]))['expense_account_id']), 'Uncategorized Expense is not a choice for a new voucher');
            $t->assertFalse(in_array(LedgerAccountService::UNCATEGORIZED, array_column(LedgerAccountService::expenseAccounts(), 'code'), true), 'Nor does it appear in the list');

            $account = LedgerAccountService::addExpenseAccount($cashier, ['code' => LEDGER_TEST_ACCOUNT, 'name' => 'ZZT Test Expense']);
            LedgerAccountService::setActive($cashier, $account, false);
            $t->assertTrue(isset(fieldErrors(fn () => DisbursementService::record($cashier, $base + ['expense_account_id' => (string) $account]))['expense_account_id']), 'A switched-off account is refused');
        } finally {
            actingAs(null);
            removeLedgerFixtures();
        }
    },

    'the cashier adds and switches off expense accounts; the ledger\'s own accounts are protected' => function (TestRunner $t): void {
        try {
            $cashier = makeUser(User::ROLE_CASHIER);

            $id = LedgerAccountService::addExpenseAccount($cashier, ['code' => LEDGER_TEST_ACCOUNT, 'name' => 'ZZT Test Expense']);
            $row = LedgerAccountService::find($id);
            $t->assertEquals('EXPENSE', $row['type']);
            $t->assertEquals(0, (int) $row['is_system']);
            $t->assertTrue(in_array($id, array_map('intval', array_column(LedgerAccountService::expenseAccounts(), 'id')), true), 'It can be chosen on a voucher');
            $t->assertNotNull(Database::scalar("SELECT id FROM audit_logs WHERE action = 'ledger.account_added' AND target_id = ?", [$id]), 'Adding is audited');

            foreach ([['4100', 'Not expense'], ['5900', 'Duplicate code'], ['5990', 'Reserved'], ['59', 'Short'], ['abcd', 'Letters']] as [$code, $name]) {
                $t->assertTrue(isset(fieldErrors(fn () => LedgerAccountService::addExpenseAccount($cashier, ['code' => $code, 'name' => $name]))['code']), "{$code} is refused");
            }
            $t->assertTrue(isset(fieldErrors(fn () => LedgerAccountService::addExpenseAccount($cashier, ['code' => '5872', 'name' => 'utilities']))['name']), 'A name already used by an expense account is refused');

            LedgerAccountService::setActive($cashier, $id, false);
            $t->assertEquals(0, (int) LedgerAccountService::find($id)['is_active']);
            LedgerAccountService::setActive($cashier, $id, true);
            $t->assertEquals(1, (int) LedgerAccountService::find($id)['is_active']);

            foreach ([LedgerAccountService::CASH, LedgerAccountService::UNCATEGORIZED, LedgerAccountService::DISCOUNTS, '4100'] as $protected) {
                $t->assertThrows(fn () => LedgerAccountService::setActive($cashier, ledgerAccountId($protected), false), 'cannot be switched off', "{$protected} cannot be switched off");
            }

            $t->assertEquals(403, statusOf(fn () => LedgerAccountService::addExpenseAccount(makeUser(User::ROLE_REGISTRAR), ['code' => '5873', 'name' => 'ZZT Nope'])));
            $t->assertEquals(403, statusOf(fn () => LedgerAccountService::setActive(makeUser(User::ROLE_REGISTRAR), $id, false)));
        } finally {
            actingAs(null);
            removeLedgerFixtures();
        }
    },

    'a voucher from before categories can be categorized once, and never changed after' => function (TestRunner $t): void {
        try {
            $cashier = makeUser(User::ROLE_CASHIER);
            $voucher = ledgerVoucher($cashier, '5900');
            Database::query('UPDATE disbursement_vouchers SET expense_account_id = NULL WHERE id = ?', [$voucher['id']]);

            $t->assertThrows(fn () => LedgerAccountService::categorizeVoucher($cashier, (int) $voucher['id'], ledgerAccountId(LedgerAccountService::CASH)), 'expense account', 'Only an expense account');
            LedgerAccountService::categorizeVoucher($cashier, (int) $voucher['id'], ledgerAccountId('5040'));
            $t->assertEquals(ledgerAccountId('5040'), (int) Database::scalar('SELECT expense_account_id FROM disbursement_vouchers WHERE id = ?', [$voucher['id']]));
            $t->assertNotNull(Database::scalar("SELECT id FROM audit_logs WHERE action = 'disbursement.categorized' AND target_id = ?", [$voucher['id']]), 'It is audited');

            $t->assertThrows(fn () => LedgerAccountService::categorizeVoucher($cashier, (int) $voucher['id'], ledgerAccountId('5050')), 'permanent', 'A second change is refused');
            $t->assertEquals(ledgerAccountId('5040'), (int) Database::scalar('SELECT expense_account_id FROM disbursement_vouchers WHERE id = ?', [$voucher['id']]), 'It stayed as first set');
            $t->assertEquals(403, statusOf(fn () => LedgerAccountService::categorizeVoucher(makeUser(User::ROLE_REGISTRAR), (int) $voucher['id'], ledgerAccountId('5050'))));
        } finally {
            actingAs(null);
            removeLedgerFixtures();
        }
    },

    'a new fee line gets a revenue account, and a program-only line shares its fee\'s account' => function (TestRunner $t): void {
        try {
            $cashier = makeUser(User::ROLE_CASHIER);
            $base = ['name' => 'ZZT Fee', 'basis' => 'FIXED', 'amount' => '10.00', 'sort_order' => '999', 'is_active' => '1'];

            FeeScheduleService::save($cashier, null, $base + ['code' => TEST_CODE . 'LEDGER']);
            $account = Database::selectOne('SELECT * FROM ledger_accounts WHERE fee_key = ?', [TEST_CODE . 'LEDGER']);
            $t->assertNotNull($account, 'A general fee line gets a revenue account');
            $t->assertEquals('REVENUE', $account['type']);
            $t->assertEquals(1, (int) $account['is_system']);

            $programId = (int) Database::scalar('SELECT id FROM programs WHERE is_active = 1 ORDER BY id LIMIT 1');
            $before = (int) Database::scalar('SELECT COUNT(*) FROM ledger_accounts');
            FeeScheduleService::save($cashier, null, $base + ['code' => TEST_CODE . 'LEDGERP', 'program_id' => (string) $programId, 'replaces' => TEST_CODE . 'LEDGER']);
            $t->assertEquals($before, (int) Database::scalar('SELECT COUNT(*) FROM ledger_accounts'), 'A scoped line that replaces it adds no account');
        } finally {
            actingAs(null);
            removeLedgerFixtures();
        }
    },

    'the ledger routes are the cashier\'s: viewing needs reports.cashier, changing the chart needs disbursements.manage' => function (TestRunner $t): void {
        $router = new Router();
        require BASE_PATH . '/routes/web.php';

        $found = 0;
        foreach ($router->routes() as $route) {
            if (!str_starts_with($route['pattern'], '/cashier/ledger')) {
                continue;
            }
            $found++;
            $t->assertTrue(in_array('role:' . User::ROLE_CASHIER, $route['middleware'], true), $route['pattern'] . ' is gated to the Cashier role');
            $permission = $route['method'] === 'POST' ? 'can:disbursements.manage' : 'can:reports.cashier';
            $t->assertTrue(in_array($permission, $route['middleware'], true), $route['method'] . ' ' . $route['pattern'] . ' needs ' . $permission);
        }
        $t->assertTrue($found >= 9, 'The ledger routes are registered (' . $found . ')');
    },
];
