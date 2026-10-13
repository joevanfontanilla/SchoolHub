<?php
/**
 * Exam-period installments and the Bridging / OJT / Old Account charges (docs/14).
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Models\Finance;
use App\Models\User;
use App\Services\FinanceService;

/** @return array<string,mixed> an assessment row as the plan reads it */
function planAssessment(string $total, string $adjustments, string $paid, ?string $initial): array
{
    return ['total_amount' => $total, 'total_adjustments' => $adjustments, 'total_paid' => $paid, 'initial_paid' => $initial];
}

/** @return array<string,array<string,mixed>> the plan's rows by key */
function planRows(?array $plan): array
{
    return array_column($plan['rows'] ?? [], null, 'key');
}

function oldAccount(string $amount): array
{
    return ['type' => 'OLD_ACCOUNT', 'amount' => $amount];
}

return [
    'the plan reproduces the school\'s ledger: the rest after the initial payment in four equal installments' => function (TestRunner $t): void {
        // The spreadsheet student: 13,133.14 assessed, 50% off tuition (-3,359.79), 4,500.00 initial payment.
        $assessment = planAssessment('13133.14', '-3359.79', '4500.00', '4500.00');
        $plan = FinanceService::installments($assessment, []);

        $rows = planRows($plan);
        $t->assertEquals(527335, $plan['remaining'], 'What is left after the initial payment: 5,273.35');
        $t->assertEquals([131834, 131834, 131834, 131833], [$rows['P1']['due'], $rows['P2']['due'], $rows['P3']['due'], $rows['P4']['due']], 'Four installments of 1,318.34, the last 1,318.33');
        $t->assertEquals(527335, array_sum([$rows['P1']['due'], $rows['P2']['due'], $rows['P3']['due'], $rows['P4']['due']]), 'They add up exactly');
        $t->assertEquals(450000, $rows['INITIAL']['due']);
        $t->assertEquals(0, $rows['INITIAL']['balance'], 'The initial payment is paid');

        // The sheet's next entry: 1,000.00 against Prelims leaves 318.34.
        $paid = FinanceService::installments(planAssessment('13133.14', '-3359.79', '5500.00', '4500.00'), []);
        $t->assertEquals(100000, planRows($paid)['P1']['paid']);
        $t->assertEquals(31834, planRows($paid)['P1']['balance'], 'Prelims 1,318.34 less 1,000.00 is 318.34');
        $t->assertEquals(0, planRows($paid)['P2']['paid'], 'Nothing reaches Midterm until Prelims is paid');
    },

    'there is no plan before the student is enrolled' => function (TestRunner $t): void {
        $t->assertNull(FinanceService::installments(planAssessment('10000.00', '0.00', '0.00', null), []));
    },

    'payments fill the obligations in order and carry forward' => function (TestRunner $t): void {
        // 10,000.00 due, 2,000.00 initial: 8,000.00 left, 2,000.00 per installment.
        $rows = planRows(FinanceService::installments(planAssessment('10000.00', '0.00', '5000.00', '2000.00'), [], [1, 2, 3, 4]));
        $t->assertEquals([200000, 100000, 0, 0], [$rows['P1']['paid'], $rows['P2']['paid'], $rows['P3']['paid'], $rows['P4']['paid']], '3,000.00 after the initial payment: one full installment and half of the next');
        $t->assertEquals(['PAID', 'DUE', 'DUE', 'DUE'], [$rows['P1']['status'], $rows['P2']['status'], $rows['P3']['status'], $rows['P4']['status']]);

        $paidOff = planRows(FinanceService::installments(planAssessment('10000.00', '0.00', '10000.00', '2000.00'), []));
        foreach (['INITIAL', 'P1', 'P2', 'P3', 'P4'] as $key) {
            $t->assertEquals(0, $paidOff[$key]['balance'], "{$key} is paid when the account is");
            $t->assertEquals('PAID', $paidOff[$key]['status']);
        }
    },

    'an odd centavo goes to the earliest installments, and a tiny remainder never goes negative' => function (TestRunner $t): void {
        foreach ([10001 => [2501, 2500, 2500, 2500], 10002 => [2501, 2501, 2500, 2500], 10003 => [2501, 2501, 2501, 2500], 2 => [1, 1, 0, 0], 0 => [0, 0, 0, 0]] as $remaining => $expected) {
            $total = FinanceService::money($remaining + 100000);
            $rows = planRows(FinanceService::installments(planAssessment($total, '0.00', '1000.00', '1000.00'), []));
            $t->assertEquals($expected, [$rows['P1']['due'], $rows['P2']['due'], $rows['P3']['due'], $rows['P4']['due']], "Splitting {$remaining} centavos");
            $t->assertEquals($remaining, $rows['P1']['due'] + $rows['P2']['due'] + $rows['P3']['due'] + $rows['P4']['due']);
        }
    },

    'an installment is Due once its exam period has opened, and the plan never blocks anything' => function (TestRunner $t): void {
        $assessment = planAssessment('10000.00', '0.00', '2000.00', '2000.00');

        $none = planRows(FinanceService::installments($assessment, [], []));
        $t->assertEquals('UPCOMING', $none['P1']['status'], 'No period has opened yet');

        $open = planRows(FinanceService::installments($assessment, [], [1, 2]));
        $t->assertEquals(['DUE', 'DUE', 'UPCOMING', 'UPCOMING'], [$open['P1']['status'], $open['P2']['status'], $open['P3']['status'], $open['P4']['status']]);

        // A term with fewer than four periods never flags an installment that has no period.
        $legacy = planRows(FinanceService::installments($assessment, [], [1, 2, 3]));
        $t->assertEquals('UPCOMING', $legacy['P4']['status']);
    },

    'the old account is due right away and is paid first, ahead of the initial payment' => function (TestRunner $t): void {
        // 12,000.00 due of which 2,000.00 is the old account; 3,000.00 initial payment; 3,000.00 paid.
        $plan = FinanceService::installments(planAssessment('10000.00', '2000.00', '3000.00', '3000.00'), [oldAccount('2000.00')], []);
        $rows = planRows($plan);

        $t->assertEquals(['OLD', 'INITIAL', 'P1', 'P2', 'P3', 'P4'], array_column($plan['rows'], 'key'), 'The old account is the first line');
        $t->assertEquals(200000, $rows['OLD']['due']);
        $t->assertEquals(200000, $rows['OLD']['paid'], 'The first 2,000.00 clears the old account');
        $t->assertEquals('PAID', $rows['OLD']['status']);
        $t->assertEquals(100000, $rows['INITIAL']['due'], 'Only the rest of the initial payment counts toward the current charges');
        $t->assertEquals(900000, $plan['remaining'], 'The old account is not split into the installments');
        $t->assertEquals(225000, $rows['P1']['due'], '9,000.00 left over four installments');

        $unpaid = FinanceService::installments(planAssessment('10000.00', '2000.00', '0.00', '0.00'), [oldAccount('2000.00')], []);
        $t->assertEquals('DUE', planRows($unpaid)['OLD']['status'], 'An unpaid old account is due even before any exam period');
    },

    'a reversal that cuts into the initial payment shows up as a shortfall, not a negative installment' => function (TestRunner $t): void {
        $rows = planRows(FinanceService::installments(planAssessment('10000.00', '0.00', '1500.00', '2000.00'), []));
        $t->assertEquals(50000, $rows['INITIAL']['balance'], 'The initial payment is 500.00 short');
        $t->assertEquals('DUE', $rows['INITIAL']['status']);
        $t->assertEquals(0, $rows['P1']['paid']);
        foreach ($rows as $row) {
            $t->assertTrue($row['balance'] >= 0 && $row['paid'] >= 0, $row['key'] . ' is never negative');
        }
    },

    'the initial payment is frozen when the student is enrolled, and later payments do not change it' => function (TestRunner $t): void {
        try {
            [, , $applicationId] = approvedApplication();
            $cashier = makeUser(User::ROLE_CASHIER);
            $assessment = Finance::assessmentForApplication($applicationId);
            $id = (int) $assessment['id'];

            $t->assertNull($assessment['initial_paid'], 'Not set before enrollment');
            $t->assertNull(Finance::installmentPlan($assessment), 'No plan before enrollment');

            // The down payment the policy asks for enrolls the student.
            $needed = App\Services\PaymentPolicy::requiredCents($assessment);
            $result = FinanceService::recordPayment($cashier, $id, ['amount' => FinanceService::money($needed), 'payment_method' => 'Cash']);
            $t->assertTrue($result['enrolled'], 'The down payment enrolled the student');

            $enrolled = Finance::assessment($id);
            $t->assertEquals(FinanceService::money($needed), $enrolled['initial_paid'], 'The initial payment is what was paid at enrollment');

            FinanceService::recordPayment($cashier, $id, ['amount' => '100', 'payment_method' => 'Cash']);
            $later = Finance::assessment($id);
            $t->assertEquals($enrolled['initial_paid'], $later['initial_paid'], 'A later payment leaves it alone');
            $t->assertEquals(10000, planRows(Finance::installmentPlan($later))['P1']['paid'], 'It goes to Prelims');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'bridging, OJT, and old account charges raise the amount due and split like the rest, except the old account' => function (TestRunner $t): void {
        try {
            [, , $applicationId] = approvedApplication();
            $cashier = makeUser(User::ROLE_CASHIER);
            $id = (int) Finance::assessmentForApplication($applicationId)['id'];
            $before = FinanceService::dueCents(Finance::assessment($id));

            foreach (['BRIDGING' => '300', 'OJT_FEE' => '200', 'OLD_ACCOUNT' => '1000'] as $type => $amount) {
                FinanceService::adjust($cashier, $id, ['type' => $type, 'amount' => $amount, 'reason' => 'Carried over from last term']);
            }
            $after = Finance::assessment($id);
            $t->assertEquals($before + 150000, FinanceService::dueCents($after), 'All three raise what is owed');

            // Enroll the student, then read the plan.
            FinanceService::recordPayment($cashier, $id, ['amount' => FinanceService::money(App\Services\PaymentPolicy::requiredCents($after)), 'payment_method' => 'Cash']);
            $plan = Finance::installmentPlan(Finance::assessment($id));
            $rows = planRows($plan);
            $t->assertEquals(100000, $rows['OLD']['due'], 'The old account is its own line, not split');
            $t->assertEquals(100000, $rows['OLD']['paid'], 'And the first money paid goes to it');
            $current = FinanceService::dueCents(Finance::assessment($id)) - 100000;
            $t->assertEquals($current - ($plan['initial'] - 100000), $plan['remaining'], 'Bridging and OJT are in what is split');

            $percent = fieldErrors(fn () => FinanceService::adjust($cashier, $id, ['type' => 'BRIDGING', 'percent' => '10', 'reason' => 'Bridging course']));
            $t->assertTrue(isset($percent['percent']), 'These are charges, so they cannot be a percentage');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },
];
