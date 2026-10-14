<?php
/**
 * The cash book (docs/14): the two-sided monthly layout, opening balances, bank deposits and
 * withdrawals, and how they reach the ledger and Close the Day.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\Router;
use App\Core\ValidationException;
use App\Models\Finance;
use App\Models\User;
use App\Services\CashBookService;
use App\Services\CashMovementService;
use App\Services\DayCloseService;
use App\Services\DisbursementService;
use App\Services\FinanceService;
use App\Services\LedgerService;

/** Set the real opening balances and today's close aside, and put them back afterwards. */
function bookStash(): array
{
    $stash = [
        'openings' => Database::select("SELECT * FROM cash_movements WHERE movement_type = 'OPENING'"),
        'today'    => Database::selectOne('SELECT * FROM day_closes WHERE close_date = ?', [date('Y-m-d')]),
    ];
    Database::query("DELETE FROM cash_movements WHERE movement_type = 'OPENING'");
    Database::query('DELETE FROM day_closes WHERE close_date = ?', [date('Y-m-d')]);

    return $stash;
}

function bookRestore(array $stash): void
{
    Database::query('DELETE FROM cash_movements WHERE recorded_by IN (SELECT id FROM users WHERE username LIKE ?)', [testPrefixPattern()]);
    Database::query("DELETE FROM cash_movements WHERE movement_type = 'OPENING'");
    Database::query('DELETE FROM day_closes WHERE close_date = ?', [date('Y-m-d')]);
    Database::query("DELETE FROM disbursement_vouchers WHERE payee = 'ZZT payee'");
    foreach ($stash['openings'] as $row) {
        unset($row['created_at']);
        Database::insert('cash_movements', $row);
    }
    if ($stash['today'] !== null) {
        unset($stash['today']['created_at'], $stash['today']['updated_at']);
        Database::insert('day_closes', $stash['today']);
    }
}

function bookVoucher(User $cashier, string $amount, string $method = 'Cash'): void
{
    DisbursementService::record($cashier, [
        'payee' => 'ZZT payee', 'particulars' => 'Supplies', 'amount' => $amount, 'payment_method' => $method,
        'disbursed_on' => date('Y-m-d'), 'expense_account_id' => (int) Database::scalar("SELECT id FROM ledger_accounts WHERE code = '5900'"),
    ]);
}

/** @return array<int,string> the Ref column of a side of the book */
function bookRefs(array $rows): array
{
    return array_column($rows, 'ref');
}

return [
    'the book lists cash payments as inflow, a reversal as a negative inflow, and vouchers as outflow' => function (TestRunner $t): void {
        $stash = bookStash();
        try {
            [, , $applicationId] = approvedApplication();
            $cashier = makeUser(User::ROLE_CASHIER);
            $id = (int) Finance::assessmentForApplication($applicationId)['id'];
            $month = date('Y-m');
            $before = CashBookService::month($month, 'CASH');

            $first = FinanceService::recordPayment($cashier, $id, ['amount' => '100', 'payment_method' => 'Cash']);
            $second = FinanceService::recordPayment($cashier, $id, ['amount' => '30', 'payment_method' => 'Cash']);
            FinanceService::reversePayment($cashier, $first['payment_id'], 'Recorded by mistake');
            bookVoucher($cashier, '20');

            $book = CashBookService::month($month, 'CASH');
            $inflow = array_column($book['inflows'], 'amount', 'ref');
            $t->assertEquals(3000, $inflow[$second['receipt_number']], 'The standing payment is an inflow');
            $reversal = array_values(array_filter($book['inflows'], static fn ($r) => str_starts_with($r['ref'], 'Rev. ')));
            $t->assertEquals(1, count(array_filter($reversal, static fn ($r) => $r['amount'] === -10000)), 'The reversal is a negative inflow of 100.00');
            $t->assertEquals(2000, $book['totalOutflow'] - $before['totalOutflow'], 'The cash voucher is an outflow');
            $t->assertEquals(1000, $book['closing'] - $before['closing'], '30.00 in, 100.00 in and out, 20.00 out');
            $t->assertEquals($book['carriedForward'] + array_sum(array_column($book['inflows'], 'amount')) - $book['totalOutflow'], $book['closing'], 'Balance C/F + inflow - outflow = closing');
        } finally {
            actingAs(null);
            bookRestore($stash);
            deleteTestUsers();
        }
    },

    'e-wallet payments and bank vouchers are in the Bank book, not the Cash book' => function (TestRunner $t): void {
        $stash = bookStash();
        try {
            [, , $applicationId] = approvedApplication();
            $cashier = makeUser(User::ROLE_CASHIER);
            $id = (int) Finance::assessmentForApplication($applicationId)['id'];
            $month = date('Y-m');
            $cashBefore = CashBookService::month($month, 'CASH');
            $bankBefore = CashBookService::month($month, 'BANK');

            $gcash = FinanceService::recordPayment($cashier, $id, ['amount' => '40', 'payment_method' => 'GCash', 'reference_number' => 'GC1']);
            bookVoucher($cashier, '15', 'GCash');

            $cash = CashBookService::month($month, 'CASH');
            $bank = CashBookService::month($month, 'BANK');
            $t->assertFalse(in_array($gcash['receipt_number'], bookRefs($cash['inflows']), true), 'Not in the Cash book');
            $t->assertTrue(in_array($gcash['receipt_number'], bookRefs($bank['inflows']), true), 'In the Bank book');
            $t->assertEquals($cashBefore['closing'], $cash['closing'], 'Cash is untouched');
            $t->assertEquals(2500, $bank['closing'] - $bankBefore['closing'], '40.00 in, 15.00 out');
            $t->assertNull($bank['counted'], 'Only the Cash book has a day-close count');
        } finally {
            actingAs(null);
            bookRestore($stash);
            deleteTestUsers();
        }
    },

    'an opening balance is the first Balance C/F, posts to the ledger, and can be corrected with an audit trail' => function (TestRunner $t): void {
        $stash = bookStash();
        try {
            $cashier = makeUser(User::ROLE_CASHIER);
            $month = date('Y-m');
            $before = CashBookService::month($month, 'CASH');

            $errors = fieldErrors(static fn () => CashMovementService::setOpening($cashier, ['account' => 'CASH', 'amount' => 'abc', 'as_of' => date('Y-m-01')]));
            $t->assertTrue(isset($errors['amount']), 'A peso amount');
            $errors = fieldErrors(static fn () => CashMovementService::setOpening($cashier, ['account' => 'CASH', 'amount' => '10', 'as_of' => date('Y-m-d', strtotime('+2 days'))]));
            $t->assertTrue(isset($errors['as_of']), 'Not in the future');
            $errors = fieldErrors(static fn () => CashMovementService::setOpening($cashier, ['account' => 'SAFE', 'amount' => '10', 'as_of' => date('Y-m-01')]));
            $t->assertTrue(isset($errors['account']), 'Cash or Bank only');
            $t->assertEquals(403, statusOf(static fn () => CashMovementService::setOpening(makeUser(User::ROLE_REGISTRAR), ['account' => 'CASH', 'amount' => '10', 'as_of' => date('Y-m-01')])));

            CashMovementService::setOpening($cashier, ['account' => 'CASH', 'amount' => '5000', 'as_of' => date('Y-m-01')]);
            $book = CashBookService::month($month, 'CASH');
            $t->assertEquals(500000, $book['carriedForward'] - $before['carriedForward'], 'An opening dated the first is carried forward');
            $t->assertEquals(500000, $book['closing'] - $before['closing']);

            // It is dated in a past month: still carried into this one.
            CashMovementService::setOpening($cashier, ['account' => 'CASH', 'amount' => '7000', 'as_of' => date('Y-m-d', strtotime('first day of last month'))]);
            $book = CashBookService::month($month, 'CASH');
            $t->assertEquals(700000, $book['carriedForward'] - $before['carriedForward'], 'Corrected to 7,000.00');
            $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM cash_movements WHERE opening_key = 'CASH'"), 'One opening per account');
            $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'cash.opening_changed' AND user_id = ?", [$cashier->id]), 'The correction is audited');

            // The ledger: Dr Cash on Hand, Cr Opening Cash Balances, and the trial balance still balances.
            $trial = LedgerService::trialBalance(date('Y-m-01'), date('Y-m-d'));
            $t->assertTrue($trial['balanced'], 'The trial balance balances');
            $codes = array_column($trial['rows'], null, 'code');
            $t->assertTrue(isset($codes['3020']), 'The opening cash equity account is in the books');
            $t->assertEquals(-700000, $codes['3020']['closing'], 'Credited with the opening balance');
        } finally {
            actingAs(null);
            bookRestore($stash);
            deleteTestUsers();
        }
    },

    'a deposit moves cash to the bank, a withdrawal moves it back; both balance in the ledger' => function (TestRunner $t): void {
        $stash = bookStash();
        try {
            $cashier = makeUser(User::ROLE_CASHIER);
            $month = date('Y-m');
            $cashBefore = CashBookService::month($month, 'CASH');
            $bankBefore = CashBookService::month($month, 'BANK');

            $deposit = CashMovementService::record($cashier, ['kind' => 'DEPOSIT', 'amount' => '800', 'reference_number' => 'SLIP-9']);
            $t->assertTrue(str_starts_with($deposit['movement_number'], 'CM-'), 'Numbered');

            $cash = CashBookService::month($month, 'CASH');
            $bank = CashBookService::month($month, 'BANK');
            $t->assertEquals(80000, $cash['totalOutflow'] - $cashBefore['totalOutflow'], 'Cash outflow');
            $t->assertEquals(80000, $bank['totalInflow'] - $bankBefore['totalInflow'], 'Bank inflow');
            $t->assertTrue(in_array($deposit['movement_number'], bookRefs($cash['outflows']), true));
            $t->assertTrue(in_array($deposit['movement_number'], bookRefs($bank['inflows']), true));

            CashMovementService::record($cashier, ['kind' => 'WITHDRAWAL', 'amount' => '300']);
            $cash = CashBookService::month($month, 'CASH');
            $bank = CashBookService::month($month, 'BANK');
            $t->assertEquals(-50000, $cash['closing'] - $cashBefore['closing'], '800.00 out, 300.00 back in');
            $t->assertEquals(50000, $bank['closing'] - $bankBefore['closing']);
            $t->assertTrue(LedgerService::trialBalance(date('Y-m-01'), date('Y-m-d'))['balanced'], 'The books still balance');

            $errors = fieldErrors(static fn () => CashMovementService::record($cashier, ['kind' => 'DEPOSIT', 'amount' => '0']));
            $t->assertTrue(isset($errors['amount']), 'A positive amount');
            $errors = fieldErrors(static fn () => CashMovementService::record($cashier, ['kind' => 'TRANSFER', 'amount' => '5']));
            $t->assertTrue(isset($errors['kind']), 'A deposit or a withdrawal');
            $t->assertEquals(403, statusOf(static fn () => CashMovementService::record(makeUser(User::ROLE_REGISTRAR), ['kind' => 'DEPOSIT', 'amount' => '5'])));
        } finally {
            actingAs(null);
            bookRestore($stash);
            deleteTestUsers();
        }
    },

    'banking the cash is not a shortage at day close, and a closed day takes no deposit' => function (TestRunner $t): void {
        $stash = bookStash();
        try {
            $cashier = makeUser(User::ROLE_CASHIER);
            [, , $applicationId] = approvedApplication();
            FinanceService::recordPayment($cashier, (int) Finance::assessmentForApplication($applicationId)['id'], ['amount' => '500', 'payment_method' => 'Cash']);
            $today = date('Y-m-d');
            $before = DayCloseService::summary($today);

            CashMovementService::record($cashier, ['kind' => 'DEPOSIT', 'amount' => '200']);
            CashMovementService::record($cashier, ['kind' => 'WITHDRAWAL', 'amount' => '50']);
            $after = DayCloseService::summary($today);
            $t->assertEquals(20000, $after['paidOutCash'] - $before['paidOutCash'], 'The deposit leaves the drawer');
            $t->assertEquals(5000, $after['receivedCash'] - $before['receivedCash'], 'The withdrawal enters it');
            $t->assertEquals(-15000, $after['expectedCash'] - $before['expectedCash']);

            DayCloseService::close($cashier, $today, FinanceService::money($after['expectedCash']), '');
            try {
                CashMovementService::record($cashier, ['kind' => 'DEPOSIT', 'amount' => '10']);
                $t->assertTrue(false, 'A closed day takes no deposit');
            } catch (ValidationException $e) {
                $t->assertEquals(DayCloseService::LOCKED_MESSAGE, $e->getMessage());
            }
        } finally {
            actingAs(null);
            bookRestore($stash);
            deleteTestUsers();
        }
    },

    'the summary shows what Close the Day counted, and the days with cash activity that were not closed' => function (TestRunner $t): void {
        $stash = bookStash();
        try {
            $cashier = makeUser(User::ROLE_CASHIER);
            [, , $applicationId] = approvedApplication();
            FinanceService::recordPayment($cashier, (int) Finance::assessmentForApplication($applicationId)['id'], ['amount' => '500', 'payment_method' => 'Cash']);
            $month = date('Y-m');
            $before = CashBookService::month($month, 'CASH')['counted'];

            CashMovementService::record($cashier, ['kind' => 'DEPOSIT', 'amount' => '25']);
            $open = CashBookService::month($month, 'CASH')['counted'];
            $t->assertTrue($open['openDays'] >= 1, 'Today has cash activity and is not closed');

            $expected = DayCloseService::summary(date('Y-m-d'))['expectedCash'];
            DayCloseService::close($cashier, date('Y-m-d'), FinanceService::money($expected + 1000), 'Counted ten pesos over');
            $closed = CashBookService::month($month, 'CASH')['counted'];
            $t->assertEquals(1, $closed['closedDays'] - $before['closedDays'], 'One more closed day');
            $t->assertEquals(1000, $closed['difference'] - $before['difference'], 'Its over/short');
            $t->assertEquals($expected + 1000, $closed['counted'] - $before['counted'], 'Its count');
            $t->assertEquals($open['openDays'] - 1, $closed['openDays'], 'Closing the day clears it from the open days');
        } finally {
            actingAs(null);
            bookRestore($stash);
            deleteTestUsers();
        }
    },

    'the month and account in the address are checked, and the cash book routes are the cashier\'s' => function (TestRunner $t): void {
        $t->assertEquals(date('Y-m'), CashBookService::normalizeMonth('2026-13'), 'A bad month is this month');
        $t->assertEquals(date('Y-m'), CashBookService::normalizeMonth('2999-01'), 'A future month is this month');
        $t->assertEquals(date('Y-m'), CashBookService::normalizeMonth(['x']), 'Not an array');
        $t->assertEquals('2026-01', CashBookService::normalizeMonth('2026-01'));
        $t->assertEquals('CASH', CashBookService::normalizeAccount('anything'));
        $t->assertEquals('BANK', CashBookService::normalizeAccount('BANK'));

        $router = new Router();
        require BASE_PATH . '/routes/web.php';
        $found = [];
        foreach ($router->routes() as $route) {
            if (str_starts_with($route['pattern'], '/cashier/reports/cash-book')) {
                $found[] = $route['method'] . ' ' . $route['pattern'];
                $t->assertTrue(in_array('role:' . User::ROLE_CASHIER, $route['middleware'], true), $route['pattern'] . ' is the cashier\'s');
                $needed = $route['method'] === 'POST' ? 'can:disbursements.manage' : 'can:reports.cashier';
                $t->assertTrue(in_array($needed, $route['middleware'], true), $route['pattern'] . ' needs ' . $needed);
            }
        }
        $t->assertEquals(5, count($found), 'The page, print, export, and the two entry forms');
    },
];
