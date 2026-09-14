<?php
/**
 * Phase 5 finance rules (docs/14, docs/15): adjustments, payment reversals, the
 * payment policy on the amount due, cashier reports, and safe CSV export.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Models\Finance;
use App\Models\FinanceReport;
use App\Models\User;
use App\Services\CsvExport;
use App\Services\FinanceService;
use App\Services\PaymentPolicy;

/** @return array<string,mixed> the application's assessment, fresh from the database */
function financeAssessment(int $applicationId): array
{
    return Finance::assessmentForApplication($applicationId);
}

function adjustmentInput(string $type, string $amount, array $overrides = []): array
{
    return array_merge(['type' => $type, 'amount' => $amount, 'reason' => 'Scholarship grant for the term'], $overrides);
}

return [
    'an adjustment needs a known type, a positive amount, a reason, and a direction for corrections; only the cashier applies one' => function (TestRunner $t): void {
        try {
            [, , $id] = approvedApplication();
            $assessmentId = (int) financeAssessment($id)['id'];
            $cashier = makeUser(User::ROLE_CASHIER);

            $t->assertTrue(isset(fieldErrors(static fn () => FinanceService::adjust($cashier, $assessmentId, adjustmentInput('REFUND', '100')))['type']), 'Unknown type');
            $t->assertTrue(isset(fieldErrors(static fn () => FinanceService::adjust($cashier, $assessmentId, adjustmentInput('DISCOUNT', '0')))['amount']), 'Zero');
            $t->assertTrue(isset(fieldErrors(static fn () => FinanceService::adjust($cashier, $assessmentId, adjustmentInput('DISCOUNT', '-50')))['amount']), 'Negative');
            $t->assertTrue(isset(fieldErrors(static fn () => FinanceService::adjust($cashier, $assessmentId, adjustmentInput('DISCOUNT', '10.005')))['amount']), 'Three decimals');
            $t->assertTrue(isset(fieldErrors(static fn () => FinanceService::adjust($cashier, $assessmentId, adjustmentInput('DISCOUNT', '100', ['reason' => ' ok '])))['reason']), 'Reason too short');
            $t->assertTrue(isset(fieldErrors(static fn () => FinanceService::adjust($cashier, $assessmentId, adjustmentInput('CORRECTION', '100')))['direction']), 'Correction without a direction');

            $t->assertEquals(403, statusOf(static fn () => FinanceService::adjust(makeUser(User::ROLE_REGISTRAR), $assessmentId, adjustmentInput('DISCOUNT', '100'))));
            $t->assertEquals(403, statusOf(static fn () => FinanceService::adjust(makeUser(User::ROLE_STUDENT), $assessmentId, adjustmentInput('DISCOUNT', '100'))));
            $t->assertEquals(404, statusOf(static fn () => FinanceService::adjust($cashier, PHP_INT_MAX, adjustmentInput('DISCOUNT', '100'))));

            $t->assertEquals(0, (int) Database::scalar('SELECT COUNT(*) FROM financial_adjustments WHERE fee_assessment_id = ?', [$assessmentId]), 'Nothing refused was written');
            $t->assertEquals('0.00', financeAssessment($id)['total_adjustments']);
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'discounts lower and charges raise the amount due, keeping the original total; each is numbered, audited, and notified' => function (TestRunner $t): void {
        try {
            [$student, , $id] = approvedApplication();
            $before = financeAssessment($id);
            $assessmentId = (int) $before['id'];
            $total = FinanceService::cents($before['total_amount']);
            $cashier = makeUser(User::ROLE_CASHIER);

            $discount = FinanceService::adjust($cashier, $assessmentId, adjustmentInput('DISCOUNT', '1000.50', ['reason' => 'Sibling discount, 2nd child']));
            $after = financeAssessment($id);
            $t->assertEquals($before['total_amount'], $after['total_amount'], 'The assessment total is never rewritten');
            $t->assertEquals('-1000.50', $after['total_adjustments']);
            $t->assertEquals(FinanceService::money($total - 100050), $after['balance']);

            FinanceService::adjust($cashier, $assessmentId, adjustmentInput('ADDITIONAL_CHARGE', '250', ['reason' => 'Replacement ID card']));
            FinanceService::adjust($cashier, $assessmentId, adjustmentInput('CORRECTION', '50.50', ['direction' => 'increase', 'reason' => 'Lab fee was under-assessed']));
            FinanceService::adjust($cashier, $assessmentId, adjustmentInput('CORRECTION', '0.50', ['direction' => 'decrease', 'reason' => 'Rounding correction']));
            $after = financeAssessment($id);
            $t->assertEquals('-700.50', $after['total_adjustments'], '-1000.50 + 250 + 50.50 - 0.50');
            $t->assertEquals($total - 70050, FinanceService::dueCents($after));
            $t->assertEquals(FinanceService::money($total - 70050), $after['balance']);

            $row = Database::selectOne('SELECT * FROM financial_adjustments WHERE id = ?', [$discount]);
            $t->assertTrue((bool) preg_match('/^ADJ-\d{4}-\d{5}$/', $row['adjustment_number']), 'ADJ-YYYY-NNNNN');
            $t->assertEquals((int) $cashier->id, (int) $row['created_by']);
            $audit = Database::selectOne("SELECT * FROM audit_logs WHERE action = 'finance.adjusted' AND target_id = ? ORDER BY id DESC LIMIT 1", [$discount]);
            $t->assertNotNull($audit, 'Audited');
            $t->assertEquals('Sibling discount, 2nd child', $audit['remarks']);
            $t->assertEquals(4, (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'finance.adjusted'", [$student->id]));
            $t->assertEquals(4, count(Finance::adjustments($assessmentId)));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'an adjustment may not bring the amount due below what is already paid' => function (TestRunner $t): void {
        try {
            [, , $id] = approvedApplication();
            $assessment = financeAssessment($id);
            $assessmentId = (int) $assessment['id'];
            $total = FinanceService::cents($assessment['total_amount']);
            $cashier = makeUser(User::ROLE_CASHIER);

            FinanceService::recordPayment($cashier, $assessmentId, ['amount' => '2000', 'payment_method' => 'Cash']);
            $tooMuch = FinanceService::money($total - 200000 + 1);
            $t->assertTrue(isset(fieldErrors(static fn () => FinanceService::adjust($cashier, $assessmentId, adjustmentInput('SCHOLARSHIP', $tooMuch)))['amount']), 'One centavo below paid');
            $t->assertEquals(0, (int) Database::scalar('SELECT COUNT(*) FROM financial_adjustments WHERE fee_assessment_id = ?', [$assessmentId]));

            FinanceService::adjust($cashier, $assessmentId, adjustmentInput('SCHOLARSHIP', FinanceService::money($total - 200000)));
            $after = financeAssessment($id);
            $t->assertEquals('0.00', $after['balance'], 'Exactly what was paid is allowed');
            $t->assertEquals('PAID', $after['payment_status']);
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a full scholarship makes the amount due zero, meets any payment policy, and enrolls the student' => function (TestRunner $t): void {
        try {
            withSetting('enrollment_payment_policy', 'FULL', static function () use ($t): void {
                [, , $id] = approvedApplication();
                $assessment = financeAssessment($id);
                $t->assertEquals('APPROVED', Database::scalar('SELECT application_status FROM enrollment_applications WHERE id = ?', [$id]));

                FinanceService::adjust(makeUser(User::ROLE_CASHIER), (int) $assessment['id'], adjustmentInput('SCHOLARSHIP', $assessment['total_amount'], ['reason' => 'Full academic scholarship']));
                $after = financeAssessment($id);
                $t->assertEquals(0, FinanceService::dueCents($after));
                $t->assertEquals(0, PaymentPolicy::requiredCents($after));
                $t->assertEquals('PAID', $after['payment_status']);
                $t->assertEquals('ENROLLED', $after['enrollment_state'], 'Enrolled without paying anything');
            });
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the payment policy is measured against the amount due, not the original assessment' => function (TestRunner $t): void {
        withSetting('minimum_downpayment_percent', '20', static function () use ($t): void {
            $assessment = ['total_amount' => '10000.00', 'total_adjustments' => '-2000.00'];
            withSetting('enrollment_payment_policy', 'MINIMUM_DOWNPAYMENT', static fn () => $t->assertEquals(160000, PaymentPolicy::requiredCents($assessment), '20% of 8,000'));
            withSetting('enrollment_payment_policy', 'FULL', static fn () => $t->assertEquals(800000, PaymentPolicy::requiredCents($assessment)));
            withSetting('enrollment_payment_policy', 'NONE', static fn () => $t->assertEquals(0, PaymentPolicy::requiredCents($assessment)));
            withSetting('enrollment_payment_policy', 'MINIMUM_DOWNPAYMENT', static fn () => $t->assertEquals(120000, PaymentPolicy::requiredCents(['total_amount' => '5000.00', 'total_adjustments' => '1000.00']), 'A charge raises it: 20% of 6,000'));
            withSetting('enrollment_payment_policy', 'MINIMUM_DOWNPAYMENT', static fn () => $t->assertEquals(0, PaymentPolicy::requiredCents(['total_amount' => '5000.00', 'total_adjustments' => '-5000.00']), 'Nothing due, nothing required'));
        });
    },

    'a reversal is a new negative entry with no receipt; the original stays, and the balance comes back' => function (TestRunner $t): void {
        try {
            [$student, , $id] = approvedApplication();
            $assessment = financeAssessment($id);
            $assessmentId = (int) $assessment['id'];
            $cashier = makeUser(User::ROLE_CASHIER);

            $payment = FinanceService::recordPayment($cashier, $assessmentId, ['amount' => '1500.25', 'payment_method' => 'Cash']);
            $original = Database::selectOne('SELECT * FROM payments WHERE id = ?', [$payment['payment_id']]);
            $t->assertEquals('PARTIAL', financeAssessment($id)['payment_status']);

            $t->assertTrue(isset(fieldErrors(static fn () => FinanceService::reversePayment($cashier, $payment['payment_id'], 'oops'))['reason']), 'A reason of at least 5 characters');
            $t->assertEquals(403, statusOf(static fn () => FinanceService::reversePayment(makeUser(User::ROLE_REGISTRAR), $payment['payment_id'], 'Recorded on the wrong account')));
            $t->assertEquals(404, statusOf(static fn () => FinanceService::reversePayment($cashier, PHP_INT_MAX, 'Recorded on the wrong account')));

            $reversalId = FinanceService::reversePayment($cashier, $payment['payment_id'], 'Recorded on the wrong account');
            $reversal = Database::selectOne('SELECT * FROM payments WHERE id = ?', [$reversalId]);
            $t->assertEquals('-1500.25', $reversal['amount']);
            $t->assertNull($reversal['receipt_number'], 'No receipt for a reversal');
            $t->assertEquals((int) $payment['payment_id'], (int) $reversal['reversed_payment_id']);
            $t->assertEquals('Recorded on the wrong account', $reversal['remarks']);
            $t->assertEquals($original, Database::selectOne('SELECT * FROM payments WHERE id = ?', [$payment['payment_id']]), 'The original payment is untouched');

            $after = financeAssessment($id);
            $t->assertEquals('0.00', $after['total_paid']);
            $t->assertEquals($assessment['balance'], $after['balance'], 'Balance restored');
            $t->assertEquals('PENDING', $after['payment_status']);

            $t->assertThrows(static fn () => FinanceService::reversePayment($cashier, $payment['payment_id'], 'Trying a second time'), 'already reversed');
            $t->assertThrows(static fn () => FinanceService::reversePayment($cashier, $reversalId, 'Reverse the reversal'), 'itself a reversal');
            $t->assertEquals(2, (int) Database::scalar('SELECT COUNT(*) FROM payments WHERE fee_assessment_id = ?', [$assessmentId]));

            $t->assertNotNull(Database::selectOne("SELECT id FROM audit_logs WHERE action = 'finance.payment_reversed' AND target_id = ? AND remarks = ?", [$payment['payment_id'], 'Recorded on the wrong account']));
            $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'finance.payment_reversed'", [$student->id]));

            $rows = Finance::payments($assessmentId);
            $t->assertEquals($reversalId, (int) $rows[0]['reversal_id'], 'The payment knows it was reversed');
            $t->assertEquals($original['receipt_number'], $rows[1]['reversed_receipt_number'], 'The reversal names the receipt it cancels');
            $t->assertNull(Finance::payment($reversalId)['receipt_number']);

            // The student can pay again after a reversal, up to the restored balance.
            $again = FinanceService::recordPayment($cashier, $assessmentId, ['amount' => '1500.25', 'payment_method' => 'GCash', 'reference_number' => 'GC-REPAY']);
            $t->assertEquals('1500.25', financeAssessment($id)['total_paid']);
            $t->assertEquals(financeAssessment($id)['balance'], Finance::balanceAfter(Finance::payment($again['payment_id'])));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'reversing a payment after enrollment keeps the student enrolled, flags the account, and tells the registrar and cashier' => function (TestRunner $t): void {
        try {
            withSetting('enrollment_payment_policy', 'MINIMUM_DOWNPAYMENT', static function () use ($t): void {
                [, $registrar, $id] = approvedApplication();
                $assessment = financeAssessment($id);
                $assessmentId = (int) $assessment['id'];
                $cashier = makeUser(User::ROLE_CASHIER);

                $payment = FinanceService::recordPayment($cashier, $assessmentId, ['amount' => FinanceService::money(PaymentPolicy::requiredCents($assessment)), 'payment_method' => 'Cash']);
                $t->assertTrue($payment['enrolled']);
                $t->assertFalse(Finance::belowPolicy(financeAssessment($id)));

                FinanceService::reversePayment($cashier, $payment['payment_id'], 'Cheque bounced');
                $after = financeAssessment($id);
                $t->assertEquals('ENROLLED', $after['enrollment_state'], 'The enrollment stands');
                $t->assertEquals(1, (int) Database::scalar('SELECT COUNT(*) FROM enrollments WHERE application_id = ?', [$id]));
                $t->assertTrue(Finance::belowPolicy($after), 'Flagged below the payment policy');

                $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'finance.below_policy'", [$registrar->id]), 'The registrar is told');
                $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'finance.below_policy'", [$cashier->id]), 'The cashier is told');
            });
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a reversal before enrollment raises no policy alarm' => function (TestRunner $t): void {
        try {
            [, $registrar, $id] = approvedApplication();
            $assessmentId = (int) financeAssessment($id)['id'];
            $cashier = makeUser(User::ROLE_CASHIER);
            $payment = FinanceService::recordPayment($cashier, $assessmentId, ['amount' => '10', 'payment_method' => 'Cash']);
            FinanceService::reversePayment($cashier, $payment['payment_id'], 'Wrong student');
            $t->assertEquals(0, (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'finance.below_policy'", [$registrar->id]));
            $t->assertFalse(Finance::belowPolicy(financeAssessment($id)));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the receipt balance accounts for adjustments made before the payment' => function (TestRunner $t): void {
        try {
            [, , $id] = approvedApplication();
            $assessment = financeAssessment($id);
            $assessmentId = (int) $assessment['id'];
            $total = FinanceService::cents($assessment['total_amount']);
            $cashier = makeUser(User::ROLE_CASHIER);

            FinanceService::adjust($cashier, $assessmentId, adjustmentInput('DISCOUNT', '500'));
            $payment = FinanceService::recordPayment($cashier, $assessmentId, ['amount' => '1000', 'payment_method' => 'Cash']);
            $t->assertEquals(FinanceService::money($total - 50000 - 100000), Finance::balanceAfter(Finance::payment($payment['payment_id'])));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the collections report nets reversals, and its day, method, and grand totals agree' => function (TestRunner $t): void {
        try {
            [, , $id] = approvedApplication();
            $assessmentId = (int) financeAssessment($id)['id'];
            $cashier = makeUser(User::ROLE_CASHIER);
            $today = date('Y-m-d');

            $before = FinanceReport::collections($today, $today);
            FinanceService::recordPayment($cashier, $assessmentId, ['amount' => '1200', 'payment_method' => 'Cash']);
            $gcash = FinanceService::recordPayment($cashier, $assessmentId, ['amount' => '800.50', 'payment_method' => 'GCash', 'reference_number' => 'GC-1']);
            FinanceService::reversePayment($cashier, $gcash['payment_id'], 'Duplicate entry');
            $report = FinanceReport::collections($today, $today);

            $t->assertEquals($before['gross'] + 200050, $report['gross']);
            $t->assertEquals($before['reversed'] - 80050, $report['reversed']);
            $t->assertEquals($before['net'] + 120000, $report['net'], 'Only the cash payment remains');
            $t->assertEquals($before['count'] + 2, $report['count'], 'Reversals are not counted as payments');
            $t->assertEquals($report['net'], array_sum(array_column($report['by_day'], 'cents')));
            $t->assertEquals($report['net'], array_sum($report['by_method']));
            $t->assertEquals($report['net'], array_sum(array_map(static fn ($r) => FinanceService::signedCents($r['amount']), $report['rows'])));

            $mine = array_values(array_filter($report['rows'], static fn ($r) => (int) $r['fee_assessment_id'] === $assessmentId));
            $t->assertEquals(3, count($mine));
            $t->assertEquals('-800.50', $mine[2]['amount']);

            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $t->assertEquals([], array_filter(FinanceReport::collections($yesterday, $yesterday)['rows'], static fn ($r) => (int) $r['fee_assessment_id'] === $assessmentId), 'Outside the range');

            $week = FinanceReport::dailyTotals(7);
            $t->assertEquals(7, count($week));
            $t->assertEquals($report['net'], $week[$today]);
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'outstanding balances list only active accounts that still owe, filtered by term and program' => function (TestRunner $t): void {
        try {
            [, , $id] = approvedApplication();
            [, , $paidId] = approvedApplication();
            $application = Database::selectOne('SELECT * FROM enrollment_applications WHERE id = ?', [$id]);
            $owing = financeAssessment($id);
            $paid = financeAssessment($paidId);
            $cashier = makeUser(User::ROLE_CASHIER);
            FinanceService::adjust($cashier, (int) $owing['id'], adjustmentInput('DISCOUNT', '100'));
            FinanceService::adjust($cashier, (int) $paid['id'], adjustmentInput('WAIVER', $paid['total_amount']));

            $report = FinanceReport::outstanding((int) $application['term_id'], (int) $application['program_id']);
            $ids = array_map('intval', array_column($report['rows'], 'id'));
            $t->assertTrue(in_array((int) $owing['id'], $ids, true), 'The account with a balance is listed');
            $t->assertFalse(in_array((int) $paid['id'], $ids, true), 'A fully waived account is not');
            foreach ($report['rows'] as $row) {
                $t->assertEquals((int) $application['term_id'], (int) $row['term_id']);
                $t->assertEquals($application['program_id'], Database::scalar('SELECT program_id FROM enrollment_applications WHERE id = ?', [$row['application_id']]));
            }
            $mine = array_values(array_filter($report['rows'], static fn ($r) => (int) $r['id'] === (int) $owing['id']))[0];
            $t->assertEquals(FinanceService::cents($owing['total_amount']) - 10000, FinanceService::dueCents($mine), 'Amount due includes the discount');
            $t->assertEquals(array_sum(array_map(static fn ($r) => FinanceService::cents($r['balance']), $report['rows'])), $report['balance']);

            $otherProgram = (int) Database::scalar('SELECT id FROM programs WHERE id <> ? ORDER BY id LIMIT 1', [$application['program_id']]);
            $t->assertFalse(in_array((int) $owing['id'], array_map('intval', array_column(FinanceReport::outstanding(null, $otherProgram)['rows'], 'id')), true), 'Another program excludes it');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'CSV export neutralizes spreadsheet formulas but keeps numbers, including negative amounts' => function (TestRunner $t): void {
        $t->assertEquals("'=HYPERLINK(\"http://evil\")", CsvExport::cell('=HYPERLINK("http://evil")'));
        $t->assertEquals("'+63 912", CsvExport::cell('+63 912'));
        $t->assertEquals("'-cmd", CsvExport::cell('-cmd'));
        $t->assertEquals("'@SUM(A1)", CsvExport::cell('@SUM(A1)'));
        $t->assertEquals("'\tx", CsvExport::cell("\tx"));
        $t->assertEquals("'\rx", CsvExport::cell("\rx"));
        $t->assertEquals('-800.50', CsvExport::cell('-800.50'), 'A negative amount stays a number');
        $t->assertEquals('1200', CsvExport::cell(1200));
        $t->assertEquals('Dela Cruz, Juan', CsvExport::cell('Dela Cruz, Juan'));
        $t->assertEquals('', CsvExport::cell(null));

        $handle = fopen('php://memory', 'w+b');
        CsvExport::write($handle, ['Name', 'Amount'], [['=1+1', '-5.00'], ['Ana "Q" Cruz, Jr.', '10.00']]);
        rewind($handle);
        $t->assertEquals(['Name', 'Amount'], fgetcsv($handle));
        $t->assertEquals(["'=1+1", '-5.00'], fgetcsv($handle));
        $t->assertEquals(['Ana "Q" Cruz, Jr.', '10.00'], fgetcsv($handle), 'Quotes and commas survive the round trip');
        fclose($handle);
    },
];
