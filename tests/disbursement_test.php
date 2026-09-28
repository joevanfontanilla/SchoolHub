<?php
/**
 * Cash disbursements and the Cash Disbursements Journal (docs/14).
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Models\DisbursementVoucher;
use App\Models\User;
use App\Services\DisbursementService as Disburse;

function voucherInput(array $overrides = []): array
{
    return array_merge([
        'payee'          => 'Meralco',
        'particulars'    => 'Electricity for June',
        'amount'         => '1500.50',
        'payment_method' => 'Cash',
        'reference_number' => '',
        'disbursed_on'   => date('Y-m-d'),
    ], $overrides);
}

return [
    'the cashier records a voucher: numbered, stored in centavos, and audited' => function (TestRunner $t): void {
        try {
            $cashier = makeUser(User::ROLE_CASHIER);
            $voucher = Disburse::record($cashier, voucherInput());

            $t->assertTrue(str_starts_with((string) $voucher['voucher_number'], 'DV-'), 'A DV voucher number is issued');
            $t->assertEquals('1500.50', $voucher['amount'], 'Amount stored to the centavo');
            $t->assertEquals('Meralco', $voucher['payee']);
            $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'disbursement.recorded' AND target_id = ?", [(int) $voucher['id']]));
        } finally {
            deleteTestUsers();
        }
    },

    'validation: payee, particulars, a positive amount, and a non-future date are required' => function (TestRunner $t): void {
        try {
            $cashier = makeUser(User::ROLE_CASHIER);
            $t->assertTrue(isset(fieldErrors(static fn () => Disburse::record($cashier, voucherInput(['payee' => ''])))['payee']));
            $t->assertTrue(isset(fieldErrors(static fn () => Disburse::record($cashier, voucherInput(['particulars' => ''])))['particulars']));
            $t->assertTrue(isset(fieldErrors(static fn () => Disburse::record($cashier, voucherInput(['amount' => '0'])))['amount']));
            $t->assertTrue(isset(fieldErrors(static fn () => Disburse::record($cashier, voucherInput(['amount' => 'abc'])))['amount']));
            $t->assertTrue(isset(fieldErrors(static fn () => Disburse::record($cashier, voucherInput(['payment_method' => 'Bitcoin'])))['payment_method']));
            $future = date('Y-m-d', strtotime('+2 days'));
            $t->assertTrue(isset(fieldErrors(static fn () => Disburse::record($cashier, voucherInput(['disbursed_on' => $future])))['disbursed_on']));
            $t->assertEquals(0, (int) Database::scalar("SELECT COUNT(*) FROM disbursement_vouchers WHERE recorded_by = ?", [$cashier->id]), 'Nothing stored on failure');
        } finally {
            deleteTestUsers();
        }
    },

    'only a user with disbursements.manage may record; others are refused' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $student = makeStudent();
            $t->assertEquals(403, statusOf(static fn () => Disburse::record($registrar, voucherInput())));
            $t->assertEquals(403, statusOf(static fn () => Disburse::record($student, voucherInput())));
        } finally {
            deleteTestUsers();
            deleteTestCatalog();
        }
    },

    'the disbursements journal lists vouchers in a date range and totals them' => function (TestRunner $t): void {
        try {
            $cashier = makeUser(User::ROLE_CASHIER);
            Disburse::record($cashier, voucherInput(['amount' => '1000.00', 'payee' => 'A', 'disbursed_on' => date('Y-m-d')]));
            Disburse::record($cashier, voucherInput(['amount' => '250.25', 'payee' => 'B', 'disbursed_on' => date('Y-m-d')]));

            $journal = DisbursementVoucher::journal(date('Y-m-d'), date('Y-m-d'));
            $t->assertEquals(2, $journal['count']);
            $t->assertEquals('1250.25', $journal['total']);

            $empty = DisbursementVoucher::journal('2000-01-01', '2000-01-02');
            $t->assertEquals(0, $empty['count'], 'A period with no vouchers is empty');
        } finally {
            deleteTestUsers();
        }
    },
];
