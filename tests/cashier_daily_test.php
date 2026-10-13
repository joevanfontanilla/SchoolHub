<?php
/**
 * Cashier daily operations (docs/14): closing the day, due installments and reminders, quick
 * amounts, and receipt copies.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\Router;
use App\Core\ValidationException;
use App\Models\Finance;
use App\Models\FinanceReport;
use App\Models\User;
use App\Services\DayCloseService;
use App\Services\DisbursementService;
use App\Services\FinanceService;
use App\Services\InstallmentReminderService;
use App\Services\PaymentPolicy;

/** Today may already be closed on a real database: set that aside, and put it back afterwards. */
function stashToday(): ?array
{
    $row = Database::selectOne('SELECT * FROM day_closes WHERE close_date = ?', [date('Y-m-d')]);
    Database::query('DELETE FROM day_closes WHERE close_date = ?', [date('Y-m-d')]);

    return $row;
}

function restoreToday(?array $row): void
{
    Database::query('DELETE FROM day_closes WHERE close_date = ?', [date('Y-m-d')]);
    if ($row !== null) {
        unset($row['created_at'], $row['updated_at']);
        Database::insert('day_closes', $row);
    }
}

function lockedMessage(callable $callback): ?string
{
    try {
        $callback();
    } catch (ValidationException $e) {
        return $e->getMessage();
    }

    return null;
}

return [
    'the summary adds cash received, nets reversals, and subtracts cash vouchers; other methods are not cash' => function (TestRunner $t): void {
        $stash = stashToday();
        try {
            [, , $applicationId] = approvedApplication();
            $cashier = makeUser(User::ROLE_CASHIER);
            $id = (int) Finance::assessmentForApplication($applicationId)['id'];
            $before = DayCloseService::summary(date('Y-m-d'));

            $cash = FinanceService::recordPayment($cashier, $id, ['amount' => '100', 'payment_method' => 'Cash']);
            FinanceService::recordPayment($cashier, $id, ['amount' => '40', 'payment_method' => 'GCash', 'reference_number' => 'GC1']);
            FinanceService::recordPayment($cashier, $id, ['amount' => '30', 'payment_method' => 'Cash']);
            FinanceService::reversePayment($cashier, $cash['payment_id'], 'Recorded by mistake');
            DisbursementService::record($cashier, [
                'payee' => 'ZZT payee', 'particulars' => 'Supplies', 'amount' => '20', 'payment_method' => 'Cash',
                'disbursed_on' => date('Y-m-d'), 'expense_account_id' => (int) Database::scalar("SELECT id FROM ledger_accounts WHERE code = '5900'"),
            ]);

            $after = DayCloseService::summary(date('Y-m-d'));
            $t->assertEquals(3000, $after['receivedCash'] - $before['receivedCash'], '100 + 30 - 100 reversed = 30.00 in cash');
            $t->assertEquals(4000, $after['otherReceived'] - $before['otherReceived'], 'GCash is not cash');
            $t->assertEquals(2000, $after['paidOutCash'] - $before['paidOutCash'], 'A cash voucher leaves the drawer');
            $t->assertEquals(1000, $after['expectedCash'] - $before['expectedCash'], '30.00 in, 20.00 out');
            $t->assertEquals(3, $after['paymentCount'] - $before['paymentCount'], 'Three payments');
            $t->assertEquals(1, $after['reversalCount'] - $before['reversalCount'], 'One reversal');
        } finally {
            actingAs(null);
            Database::query("DELETE FROM disbursement_vouchers WHERE payee = 'ZZT payee'");
            restoreToday($stash);
            deleteTestUsers();
        }
    },

    'closing the day stores the count; a difference needs an explanation; a day closes once' => function (TestRunner $t): void {
        $stash = stashToday();
        try {
            $cashier = makeUser(User::ROLE_CASHIER);
            $today = date('Y-m-d');
            $expected = DayCloseService::summary($today)['expectedCash'];

            $errors = fieldErrors(static fn () => DayCloseService::close($cashier, $today, 'abc', ''));
            $t->assertTrue(isset($errors['counted_cash']), 'A count is a peso amount');

            $short = FinanceService::money($expected + 5000);
            $errors = fieldErrors(static fn () => DayCloseService::close($cashier, $today, $short, ''));
            $t->assertTrue(isset($errors['explanation']), 'A difference needs a reason');
            $t->assertNull(DayCloseService::find($today), 'Nothing was stored');

            $errors = fieldErrors(static fn () => DayCloseService::close($cashier, date('Y-m-d', strtotime('+1 day')), '0', ''));
            $t->assertTrue(isset($errors['date']), 'A future day cannot be closed');

            $close = DayCloseService::close($cashier, $today, $short, 'Customer was given too much change');
            $t->assertEquals('CLOSED', $close['status']);
            $t->assertEquals(FinanceService::money($expected), $close['expected_cash']);
            $t->assertEquals('50.00', $close['difference'], 'Counted minus expected');
            $t->assertEquals('Customer was given too much change', $close['explanation']);
            $t->assertTrue(DayCloseService::isClosed($today));

            $t->assertTrue(lockedMessage(static fn () => DayCloseService::close($cashier, $today, $short, 'again')) !== null, 'A closed day cannot be closed again');
        } finally {
            actingAs(null);
            restoreToday($stash);
            deleteTestUsers();
        }
    },

    'a count that matches needs no explanation, and only the cashier may close' => function (TestRunner $t): void {
        $stash = stashToday();
        try {
            $cashier = makeUser(User::ROLE_CASHIER);
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $expected = DayCloseService::summary(date('Y-m-d'))['expectedCash'];

            $t->assertEquals(403, statusOf(static fn () => DayCloseService::close($registrar, date('Y-m-d'), '0', '')));
            $close = DayCloseService::close($cashier, date('Y-m-d'), FinanceService::money($expected), '');
            $t->assertEquals('0.00', $close['difference']);
            $t->assertNull($close['explanation']);
        } finally {
            actingAs(null);
            restoreToday($stash);
            deleteTestUsers();
        }
    },

    'a closed day takes no payment, reversal, or voucher until the administrator reopens it' => function (TestRunner $t): void {
        $stash = stashToday();
        $lastNotification = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM notifications');
        try {
            [, , $applicationId] = approvedApplication();
            $cashier = makeUser(User::ROLE_CASHIER);
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            $id = (int) Finance::assessmentForApplication($applicationId)['id'];
            $today = date('Y-m-d');

            $first = FinanceService::recordPayment($cashier, $id, ['amount' => '50', 'payment_method' => 'Cash']);
            $close = DayCloseService::close($cashier, $today, FinanceService::money(DayCloseService::summary($today)['expectedCash']), '');

            $payment = static fn () => FinanceService::recordPayment($cashier, $id, ['amount' => '10', 'payment_method' => 'Cash']);
            $t->assertEquals(DayCloseService::LOCKED_MESSAGE, lockedMessage($payment), 'No new payment');
            $t->assertEquals(DayCloseService::LOCKED_MESSAGE, lockedMessage(static fn () => FinanceService::reversePayment($cashier, $first['payment_id'], 'Recorded by mistake')), 'No reversal');
            $t->assertEquals(DayCloseService::LOCKED_MESSAGE, lockedMessage(static fn () => DisbursementService::record($cashier, [
                'payee' => 'ZZT payee', 'particulars' => 'Supplies', 'amount' => '5', 'payment_method' => 'Cash',
                'disbursed_on' => $today, 'expense_account_id' => (int) Database::scalar("SELECT id FROM ledger_accounts WHERE code = '5900'"),
            ])), 'No voucher');

            // The cashier cannot reopen their own close; the reason is required; the administrator can.
            $t->assertEquals(403, statusOf(static fn () => DayCloseService::reopen($cashier, (int) $close['id'], 'I want to')));
            $errors = fieldErrors(static fn () => DayCloseService::reopen($admin, (int) $close['id'], 'no'));
            $t->assertTrue(isset($errors['reason']), 'A reason of at least 5 characters');
            DayCloseService::reopen($admin, (int) $close['id'], 'A payment was missed');

            $t->assertFalse(DayCloseService::isClosed($today), 'Open again');
            $t->assertEquals('REOPENED', DayCloseService::find($today)['status']);
            $t->assertNull(lockedMessage($payment), 'The cashier can record again');
            $t->assertTrue(lockedMessage(static fn () => DayCloseService::reopen($admin, (int) $close['id'], 'twice over')) !== null, 'An open day cannot be reopened');

            $again = DayCloseService::close($cashier, $today, FinanceService::money(DayCloseService::summary($today)['expectedCash']), '');
            $t->assertEquals('CLOSED', $again['status'], 'Closed again with a new count');
            $t->assertEquals((int) $close['id'], (int) $again['id'], 'The same row');
            $t->assertNull($again['reopened_at'], 'The reopening note is cleared on the new close');

            $audit = (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action IN ('finance.day_closed', 'finance.day_reopened') AND target_id = ?", [(int) $close['id']]);
            $t->assertEquals(3, $audit, 'Close, reopen, close are all audited');
        } finally {
            actingAs(null);
            restoreToday($stash);
            Database::query('DELETE FROM notifications WHERE id > ? AND type = ?', [$lastNotification, 'finance.day_reopened']);
            Database::query("DELETE FROM disbursement_vouchers WHERE payee = 'ZZT payee'");
            deleteTestUsers();
        }
    },

    'an installment is due when its period has opened; the account list can show only those accounts' => function (TestRunner $t): void {
        $plan = FinanceService::installments(['total_amount' => '10000.00', 'total_adjustments' => '0.00', 'total_paid' => '2000.00', 'initial_paid' => '2000.00'], [], [1, 2]);
        $t->assertEquals(['P1', 'P2'], array_column(FinanceService::dueRows($plan), 'key'), 'Prelims and Midterm have opened and are unpaid');
        $t->assertEquals([], FinanceService::dueRows(null), 'No plan, nothing due');
        $t->assertEquals([], FinanceService::dueRows(FinanceService::installments(['total_amount' => '10000.00', 'total_adjustments' => '0.00', 'total_paid' => '10000.00', 'initial_paid' => '2000.00'], [], [1, 2, 3, 4])), 'Paid in full, nothing due');

        $all = FinanceReport::accountList(null, null, null);
        $due = FinanceReport::accountList(null, null, null, true);
        $t->assertTrue($due['count'] <= $all['count'], 'The filter only removes accounts');
        foreach ($due['groups'] as $group) {
            foreach ($group['rows'] as $row) {
                $t->assertTrue($row['due_now'] > 0, $row['assessment_number'] . ' has something due');
            }
        }
    },

    'reminders go to students with a due installment, once per installment' => function (TestRunner $t): void {
        $periods = [];
        $assessmentId = 0;
        try {
            [$student, , $applicationId] = approvedApplication();
            $cashier = makeUser(User::ROLE_CASHIER);
            $assessment = Finance::assessmentForApplication($applicationId);
            $assessmentId = (int) $assessment['id'];

            $needed = PaymentPolicy::requiredCents($assessment);
            $t->assertTrue(FinanceService::recordPayment($cashier, $assessmentId, ['amount' => FinanceService::money($needed), 'payment_method' => 'Cash'])['enrolled'], 'Enrolled');

            $termId = (int) $assessment['term_id'];
            // Close the first two exam periods of this term for the test, and put them back afterwards.
            $periods = Database::select('SELECT id, opened_at FROM grade_periods WHERE term_id = ? AND sequence IN (1, 2)', [$termId]);
            Database::query('UPDATE grade_periods SET opened_at = NULL WHERE term_id = ? AND sequence IN (1, 2)', [$termId]);
            $t->assertEquals([], InstallmentReminderService::pending(null, null, $assessmentId), 'No period has opened for this student yet');

            Database::update('grade_periods', ['opened_at' => date('Y-m-d H:i:s')], ['term_id' => $termId, 'sequence' => 1]);

            $pending = InstallmentReminderService::pending(null, null, $assessmentId);
            $t->assertEquals(['P1'], array_column($pending, 'key'), 'Prelims is due');
            $t->assertEquals($student->id, $pending[0]['user_id']);

            $t->assertEquals(403, statusOf(static fn () => InstallmentReminderService::send($student, null, null, $assessmentId)));
            $t->assertEquals(1, InstallmentReminderService::send($cashier, null, null, $assessmentId), 'One reminder sent');
            $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'finance.installment_due'", [$student->id]), 'The student was notified');
            $t->assertEquals(0, InstallmentReminderService::send($cashier, null, null, $assessmentId), 'Not twice for the same installment');
            $t->assertEquals([], InstallmentReminderService::pending(null, null, $assessmentId));

            // Midterm opens: only the new installment is reminded.
            Database::update('grade_periods', ['opened_at' => date('Y-m-d H:i:s')], ['term_id' => $termId, 'sequence' => 2]);
            $t->assertEquals(['P2'], array_column(InstallmentReminderService::pending(null, null, $assessmentId), 'key'));
        } finally {
            actingAs(null);
            if ($assessmentId > 0) {
                Database::query('DELETE FROM installment_reminders WHERE fee_assessment_id = ?', [$assessmentId]);
            }
            foreach ($periods as $period) {
                Database::update('grade_periods', ['opened_at' => $period['opened_at']], ['id' => $period['id']]);
            }
            deleteTestUsers();
        }
    },

    'the quick amounts offer what is needed to enroll, the next installment, and the full balance' => function (TestRunner $t): void {
        $account = ['balance' => '9000.00'];
        $before = FinanceService::quickAmounts($account, '2500.00', null);
        $t->assertEquals(['Required to enroll', 'Full balance'], array_column($before, 'label'), 'Before enrollment there is no installment yet');
        $t->assertEquals(['2500.00', '9000.00'], array_column($before, 'amount'));

        $plan = FinanceService::installments(['total_amount' => '10000.00', 'total_adjustments' => '0.00', 'total_paid' => '1000.00', 'initial_paid' => '1000.00'], []);
        $enrolled = FinanceService::quickAmounts($account, '', $plan);
        $t->assertEquals(['Pay prelims', 'Full balance'], array_column($enrolled, 'label'));
        $t->assertEquals(['2250.00', '9000.00'], array_column($enrolled, 'amount'), 'A quarter of the 9,000.00 left');

        $small = FinanceService::quickAmounts(['balance' => '100.00'], '', $plan);
        $t->assertEquals('100.00', $small[0]['amount'], 'Never more than the balance');

        $t->assertEquals([], FinanceService::quickAmounts(['balance' => '0.00'], '', $plan), 'Nothing to offer on a paid account');
    },

    'the first opening of a receipt by staff is the original; the rest are duplicates' => function (TestRunner $t): void {
        try {
            [, , $applicationId] = approvedApplication();
            $cashier = makeUser(User::ROLE_CASHIER);
            $id = (int) Finance::assessmentForApplication($applicationId)['id'];
            $payment = FinanceService::recordPayment($cashier, $id, ['amount' => '10', 'payment_method' => 'Cash']);

            $t->assertNull(Finance::payment($payment['payment_id'])['first_printed_at'], 'Never opened');
            $t->assertTrue(Finance::markReceiptPrinted($payment['payment_id']), 'The first opening is the original');
            $t->assertFalse(Finance::markReceiptPrinted($payment['payment_id']), 'Every later one is a copy');
            $t->assertTrue(Finance::payment($payment['payment_id'])['first_printed_at'] !== null);
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the day-close and reopen routes are gated to the cashier and the administrator' => function (TestRunner $t): void {
        $router = new Router();
        require BASE_PATH . '/routes/web.php';

        $found = ['cashier' => 0, 'admin' => 0, 'remind' => 0];
        foreach ($router->routes() as $route) {
            if (str_starts_with($route['pattern'], '/cashier/day-close')) {
                $found['cashier']++;
                $t->assertTrue(in_array('role:' . User::ROLE_CASHIER, $route['middleware'], true) && in_array('can:payments.record', $route['middleware'], true), $route['pattern'] . ' is the cashier\'s');
            }
            if (str_starts_with($route['pattern'], '/admin/day-closes')) {
                $found['admin']++;
                $t->assertTrue(in_array('role:' . User::ROLE_SUPER_ADMIN, $route['middleware'], true), $route['pattern'] . ' is the administrator\'s');
            }
            if ($route['pattern'] === '/cashier/reports/outstanding/remind') {
                $found['remind']++;
                $t->assertEquals('POST', $route['method'], 'Reminders are sent by a POST');
                $t->assertTrue(in_array('can:payments.record', $route['middleware'], true));
            }
        }
        $t->assertEquals(['cashier' => 3, 'admin' => 2, 'remind' => 1], $found);
    },
];
