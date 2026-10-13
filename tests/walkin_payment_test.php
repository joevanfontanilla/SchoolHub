<?php
/**
 * Walk-in payments (docs/14): the cashier's counter workspace and its day list.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\Router;
use App\Models\Finance;
use App\Models\User;
use App\Services\FinanceService;
use App\Services\PaymentPolicy;

/** @return array<int,int> payment ids in today's counter list */
function counterIds(): array
{
    return array_map('intval', array_column(Finance::counterPaymentsOn(date('Y-m-d')), 'id'));
}

return [
    'a payment recorded at the window appears in today\'s counter list, with its receipt' => function (TestRunner $t): void {
        try {
            [, , $applicationId] = approvedApplication();
            $cashier = makeUser(User::ROLE_CASHIER);
            $assessment = Finance::assessmentForApplication($applicationId);

            $result = FinanceService::recordPayment($cashier, (int) $assessment['id'], ['amount' => '100', 'payment_method' => 'Cash']);

            $t->assertTrue(in_array($result['payment_id'], counterIds(), true), 'The payment is listed');
            $row = current(array_filter(Finance::counterPaymentsOn(date('Y-m-d')), static fn ($p) => (int) $p['id'] === $result['payment_id']));
            $t->assertEquals($result['receipt_number'], $row['receipt_number']);
            $t->assertEquals('Cash', $row['payment_method']);
            $t->assertEquals('100.00', $row['amount']);
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'online verifications, reversals, and reversed payments are not counter payments' => function (TestRunner $t): void {
        try {
            [$student, , $applicationId] = approvedApplication();
            $cashier = makeUser(User::ROLE_CASHIER);
            $assessmentId = (int) Finance::assessmentForApplication($applicationId)['id'];

            // An online payment the cashier verified.
            $submission = FinanceService::submitPayment($student, $assessmentId, [
                'amount' => '50', 'method' => 'GCash', 'reference_number' => 'GC' . random_int(100000, 999999), 'paid_on' => date('Y-m-d'),
            ], App\Core\UploadedFile::forTesting(fixtureFile('png'), 'proof.png'));
            $online = FinanceService::approveSubmission($cashier, $submission);

            // One window payment that is then reversed, and one that stands.
            $reversed = FinanceService::recordPayment($cashier, $assessmentId, ['amount' => '20', 'payment_method' => 'Cash']);
            $reversalId = FinanceService::reversePayment($cashier, $reversed['payment_id'], 'Recorded on the wrong account');
            $kept = FinanceService::recordPayment($cashier, $assessmentId, ['amount' => '30', 'payment_method' => 'Check', 'reference_number' => 'CHK-1']);

            $ids = counterIds();
            $t->assertTrue(in_array($kept['payment_id'], $ids, true), 'The standing window payment is listed');
            $t->assertFalse(in_array((int) $online['payment_id'], $ids, true), 'An online verification is not');
            $t->assertFalse(in_array($reversed['payment_id'], $ids, true), 'A reversed payment is not');
            $t->assertFalse(in_array($reversalId, $ids, true), 'The reversal row is not');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'only today\'s payments are listed' => function (TestRunner $t): void {
        try {
            [, , $applicationId] = approvedApplication();
            $cashier = makeUser(User::ROLE_CASHIER);
            $result = FinanceService::recordPayment($cashier, (int) Finance::assessmentForApplication($applicationId)['id'], ['amount' => '10', 'payment_method' => 'Cash']);

            $t->assertTrue(in_array($result['payment_id'], array_map('intval', array_column(Finance::counterPaymentsOn(date('Y-m-d')), 'id')), true));
            $t->assertFalse(in_array($result['payment_id'], array_map('intval', array_column(Finance::counterPaymentsOn(date('Y-m-d', strtotime('-1 day'))), 'id')), true), 'Not yesterday');
            $t->assertFalse(in_array($result['payment_id'], array_map('intval', array_column(Finance::counterPaymentsOn(date('Y-m-d', strtotime('+1 day'))), 'id')), true), 'Not tomorrow');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a student who has not met the policy is told how much more is needed' => function (TestRunner $t): void {
        try {
            [, , $applicationId] = approvedApplication();
            $assessment = Finance::assessmentForApplication($applicationId);

            $needed = PaymentPolicy::requiredCents($assessment) - FinanceService::cents($assessment['total_paid']);
            $t->assertTrue($needed > 0, 'The default policy asks for a down payment');
            $t->assertEquals('NOT_ENROLLED', $assessment['enrollment_state']);
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the walk-in payment routes are the cashier\'s alone, behind payments.record' => function (TestRunner $t): void {
        $router = new Router();
        require BASE_PATH . '/routes/web.php';

        $found = 0;
        foreach ($router->routes() as $route) {
            if (str_starts_with($route['pattern'], '/cashier/walk-in-payments')) {
                $found++;
                $t->assertTrue(in_array('role:' . User::ROLE_CASHIER, $route['middleware'], true), $route['pattern'] . ' is gated to the Cashier role');
                $t->assertTrue(in_array('can:payments.record', $route['middleware'], true), $route['pattern'] . ' needs payments.record');
            }
        }
        $t->assertEquals(2, $found, 'A page and a form post');
    },
];
