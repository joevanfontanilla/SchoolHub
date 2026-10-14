<?php
/**
 * The payment breakdown (docs/14): the allocation rule, one account's split, and a period's report.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\Router;
use App\Models\Finance;
use App\Models\User;
use App\Services\FinanceService;
use App\Services\PaymentBreakdownService as Breakdown;

/** A report's collected cents for one fee-line key, 0 when the line is absent. */
function lineCollected(array $report, string $key): int
{
    foreach ($report['lines'] as $row) {
        if ($row['key'] === $key) {
            return $row['collected'];
        }
    }

    return 0;
}

return [
    'a payment is split: the old account first, then across the lines in proportion to what each was charged' => function (TestRunner $t): void {
        $even = Breakdown::allocate(100000, 0, ['A' => 60000, 'B' => 40000]);
        $t->assertEquals(60000, $even['A']);
        $t->assertEquals(40000, $even['B'], 'Paid in full: each line gets what it was charged');

        $half = Breakdown::allocate(50000, 0, ['A' => 60000, 'B' => 40000]);
        $t->assertEquals([30000, 20000], [$half['A'], $half['B']], 'Half paid: half of each line');

        $old = Breakdown::allocate(50000, 30000, ['A' => 60000, 'B' => 40000]);
        $t->assertEquals(30000, $old[Breakdown::OLD], 'The old account is paid first');
        $t->assertEquals([12000, 8000], [$old['A'], $old['B']], 'The rest is shared in proportion');

        $short = Breakdown::allocate(20000, 30000, ['A' => 60000]);
        $t->assertEquals([20000, 0], [$short[Breakdown::OLD], $short['A']], 'Less than the old account: all of it goes there');

        $t->assertEquals(0, array_sum(Breakdown::allocate(-500, 0, ['A' => 100])), 'A negative total is treated as nothing paid');
        $none = Breakdown::allocate(1000, 0, []);
        $t->assertEquals(1000, $none[Breakdown::UNALLOCATED], 'With no lines the money is shown as unmatched, not lost');
    },

    'the parts always add up to exactly what was paid, and leftover centavos go to the largest remainders' => function (TestRunner $t): void {
        $thirds = Breakdown::allocate(100, 0, ['A' => 1, 'B' => 1, 'C' => 1]);
        $t->assertEquals(100, array_sum($thirds));
        $t->assertEquals([34, 33, 33], [$thirds['A'], $thirds['B'], $thirds['C']], 'A tie goes to the first key');

        mt_srand(42);
        for ($i = 0; $i < 300; $i++) {
            $weights = [];
            foreach (range(1, mt_rand(1, 9)) as $n) {
                $weights['L' . $n] = mt_rand(1, 500000);
            }
            $old = mt_rand(0, 3) === 0 ? mt_rand(0, 200000) : 0;
            $paid = mt_rand(0, array_sum($weights) + $old);
            $split = Breakdown::allocate($paid, $old, $weights);
            $t->assertEquals($paid, array_sum($split), "Case {$i}: nothing lost or invented");
            $t->assertTrue(min($split) >= 0, "Case {$i}: no negative part");
            $t->assertTrue($split[Breakdown::OLD] <= $old, "Case {$i}: not more than the old account");
        }
    },

    'one account: each line with what was assessed and paid toward it, discounts shown apart, totals matching the account' => function (TestRunner $t): void {
        try {
            [, , $applicationId] = approvedApplication();
            $cashier = makeUser(User::ROLE_CASHIER);
            $id = (int) Finance::assessmentForApplication($applicationId)['id'];

            FinanceService::adjust($cashier, $id, ['type' => 'OLD_ACCOUNT', 'amount' => '500', 'reason' => 'Unpaid last term']);
            FinanceService::adjust($cashier, $id, ['type' => 'DISCOUNT', 'amount' => '100', 'reason' => 'Early payment discount']);
            FinanceService::recordPayment($cashier, $id, ['amount' => '800', 'payment_method' => 'Cash']);

            $split = Breakdown::forAssessment($id);
            $account = Finance::assessment($id);
            $labels = array_column($split['rows'], 'label');
            $t->assertTrue(in_array('Old account', $labels, true), 'The old account is a line');
            $rows = array_column($split['rows'], null, 'key');
            $t->assertEquals(50000, $rows[Breakdown::OLD]['assessed']);
            $t->assertEquals(50000, $rows[Breakdown::OLD]['paid'], 'It is paid first');
            $t->assertEquals(-10000, $split['discounts'], 'The discount is shown apart');
            $t->assertEquals(80000, $split['paid'], 'Paid so far');
            $t->assertEquals(80000, array_sum(array_column($split['rows'], 'paid')), 'The lines add up to what was paid');
            $t->assertEquals(max(0, FinanceService::dueCents($account)), $split['assessed'], 'Assessed is the amount due');
            $t->assertEquals(FinanceService::cents($account['balance']), $split['balance'], 'The balance matches the account');

            $html = partial('cashier/payment-split', ['split' => $split]);
            $t->assertContains('Old account', $html);
            $t->assertContains('Discounts, scholarships, and corrections', $html);
            $t->assertContains('does not change the balance', $html);

            $t->assertNull(Breakdown::forAssessment(999999999), 'No such account');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a period counts only what was received in it, a reversal lowers the line it counted toward, and nothing is counted twice' => function (TestRunner $t): void {
        try {
            [, , $applicationId] = approvedApplication();
            $cashier = makeUser(User::ROLE_CASHIER);
            $id = (int) Finance::assessmentForApplication($applicationId)['id'];
            $today = date('Y-m-d');
            $yesterday = date('Y-m-d', strtotime('-1 day'));

            $today0 = Breakdown::report($today, $today, null, null);
            $both0 = Breakdown::report($yesterday, $today, null, null);

            FinanceService::adjust($cashier, $id, ['type' => 'OLD_ACCOUNT', 'amount' => '300', 'reason' => 'Unpaid last term']);
            $first = FinanceService::recordPayment($cashier, $id, ['amount' => '1000', 'payment_method' => 'Cash']);
            // That payment was made yesterday.
            Database::query('UPDATE payments SET created_at = ? WHERE id = ?', [$yesterday . ' 10:00:00', $first['payment_id']]);
            $second = FinanceService::recordPayment($cashier, $id, ['amount' => '500', 'payment_method' => 'GCash', 'reference_number' => 'GC-9']);

            $onlyToday = Breakdown::report($today, $today, null, null);
            $t->assertEquals(50000, $onlyToday['collected'] - $today0['collected'], 'Today only counts the 500.00 received today');
            $t->assertEquals(0, lineCollected($onlyToday, Breakdown::OLD) - lineCollected($today0, Breakdown::OLD), 'The old account was paid off yesterday, so none of today\'s money goes to it');
            $t->assertEquals(1, $onlyToday['accounts'] - $today0['accounts'], 'One more account paid today');
            $t->assertEquals(50000, ($onlyToday['methods']['GCash']['cents'] ?? 0) - ($today0['methods']['GCash']['cents'] ?? 0), 'By method');
            $t->assertEquals(50000, ($onlyToday['days'][$today]['cents'] ?? 0) - ($today0['days'][$today]['cents'] ?? 0), 'By day');

            $both = Breakdown::report($yesterday, $today, null, null);
            $t->assertEquals(150000, $both['collected'] - $both0['collected'], 'Both days: 1,500.00');
            $t->assertEquals(30000, lineCollected($both, Breakdown::OLD) - lineCollected($both0, Breakdown::OLD), 'The old account was paid first, once');
            $lineSum = array_sum(array_column($both['lines'], 'collected'));
            $t->assertEquals($both['collected'], $lineSum, 'The lines add up to the collected total');

            // Reverse today's payment: the period nets to nothing for it.
            FinanceService::reversePayment($cashier, $second['payment_id'], 'Recorded on the wrong account');
            $after = Breakdown::report($today, $today, null, null);
            $t->assertEquals(0, $after['collected'] - $today0['collected'], 'Received and reversed on the same day nets to zero');
            $t->assertEquals(0, ($after['methods']['GCash']['cents'] ?? 0) - ($today0['methods']['GCash']['cents'] ?? 0));

            // Reverse yesterday's payment today: today shows the loss, the two days together net out.
            FinanceService::reversePayment($cashier, $first['payment_id'], 'Recorded on the wrong account');
            $loss = Breakdown::report($today, $today, null, null);
            $t->assertEquals(-100000, $loss['collected'] - $today0['collected'], 'The reversal is today\'s, lowering the lines it counted toward');
            $t->assertEquals(-30000, lineCollected($loss, Breakdown::OLD) - lineCollected($today0, Breakdown::OLD), 'Including the old account it had paid');
            $net = Breakdown::report($yesterday, $today, null, null);
            $t->assertEquals(0, $net['collected'] - $both0['collected'], 'Over both days it nets to zero');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'filters narrow the accounts, and a period with no payments is empty' => function (TestRunner $t): void {
        try {
            [, , $applicationId] = approvedApplication();
            $cashier = makeUser(User::ROLE_CASHIER);
            $assessment = Finance::assessmentForApplication($applicationId);
            $id = (int) $assessment['id'];
            $application = Database::selectOne('SELECT program_id, year_level_id FROM enrollment_applications WHERE id = ?', [$applicationId]);
            FinanceService::recordPayment($cashier, $id, ['amount' => '200', 'payment_method' => 'Cash']);
            $today = date('Y-m-d');

            $mine = Breakdown::report($today, $today, (int) $application['program_id'], (int) $application['year_level_id']);
            $t->assertTrue($mine['accounts'] >= 1 && $mine['collected'] >= 20000, 'Included for its own program and year');

            $otherProgram = (int) Database::scalar('SELECT id FROM programs WHERE id <> ? ORDER BY id LIMIT 1', [(int) $application['program_id']]);
            if ($otherProgram > 0) {
                $other = Breakdown::report($today, $today, $otherProgram, (int) $application['year_level_id']);
                $t->assertTrue($other['collected'] < $mine['collected'] || $other['accounts'] < $mine['accounts'], 'Excluded from another program');
            }

            $empty = Breakdown::report('1999-01-01', '1999-01-31', null, null);
            $t->assertEquals([], $empty['lines']);
            $t->assertEquals(0, $empty['collected']);
            $t->assertEquals(0, $empty['accounts']);
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the walk-in payments page shows the chosen student\'s split at the top, above the search, and nothing when no student is chosen' => function (TestRunner $t): void {
        $controller = (string) file_get_contents(BASE_PATH . '/app/Controllers/Cashier/WalkInPaymentController.php');
        $t->assertContains('PaymentBreakdownService::forAssessment((int) $account[\'id\'])', $controller, 'The controller passes the split for the chosen account');
        $t->assertContains('$account !== null ? PaymentBreakdownService', $controller, 'And only when an account is chosen');

        $view = (string) file_get_contents(BASE_PATH . '/app/Views/cashier/walk-in-payments/index.php');
        $split = strpos($view, "partial('cashier/payment-split'");
        $search = strpos($view, 'aria-labelledby="find-heading"');
        $t->assertTrue($split !== false && $search !== false && $split < $search, 'The table comes before "Find the student"');
        $t->assertContains('<?php if ($account !== null): ?>', $view, 'Neither table is shown without a chosen student');
        $t->assertContains('<?php if ($split !== null): ?>', $view, 'The split needs an account with charges');

        $installments = strpos($view, "partial('installments'");
        $t->assertTrue($installments !== false && $installments < $search, 'The Installments table also comes before "Find the student"');
        $t->assertTrue($installments < $split, 'Installments first, then where the payments went');
        $t->assertContains('\'plan\'      => $plan', $controller, 'The controller passes the installment plan');
    },

    'the breakdown routes are the cashier\'s, behind reports.cashier, and the printout ends with Prepared by' => function (TestRunner $t): void {
        $router = new Router();
        require BASE_PATH . '/routes/web.php';

        $found = 0;
        foreach ($router->routes() as $route) {
            if (str_starts_with($route['pattern'], '/cashier/reports/payment-breakdown')) {
                $found++;
                $t->assertEquals('GET', $route['method']);
                $t->assertTrue(in_array('role:' . User::ROLE_CASHIER, $route['middleware'], true) && in_array('can:reports.cashier', $route['middleware'], true), $route['pattern'] . ' is the cashier\'s');
            }
        }
        $t->assertEquals(3, $found, 'The page, its printout, and the CSV');
        $t->assertContains("partial('cashier/prepared-by'", (string) file_get_contents(BASE_PATH . '/app/Views/print/payment-breakdown.php'));
    },
];
