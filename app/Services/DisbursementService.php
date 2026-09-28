<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Core\ValidationException;
use App\Models\DisbursementVoucher;
use App\Models\User;

/**
 * Cash disbursements (docs/14): the money-out side of the accounting cycle. The cashier
 * records each payment on a numbered voucher (`disbursements.manage`); vouchers feed the
 * Cash Disbursements Journal. Amounts are whole centavos like the rest of finance, and a
 * voucher is append-only — a mistake is fixed with a reversing voucher, never an edit.
 */
final class DisbursementService
{
    public const METHODS = ['Cash', 'Check', 'Bank Transfer', 'GCash'];

    /**
     * Record a disbursement and return the created voucher row.
     *
     * @param array<string,mixed> $input payee, particulars, amount, payment_method, reference_number, disbursed_on
     * @return array<string,mixed>
     */
    public static function record(User $cashier, array $input): array
    {
        self::assertCashier($cashier);

        $validator = Validator::make($input, [
            'payee'          => 'required|max:150',
            'particulars'    => 'required|max:255',
            'amount'         => 'required|numeric',
            'payment_method' => 'required|in:' . implode(',', self::METHODS),
            'reference_number' => 'nullable|max:100',
            'disbursed_on'   => 'required|date',
        ], ['reference_number' => 'Reference number', 'disbursed_on' => 'Date disbursed', 'payment_method' => 'Payment method']);

        $cents = FinanceService::cents((string) ($input['amount'] ?? '0'));
        if (!preg_match('/^\d+(\.\d{1,2})?$/', trim((string) ($input['amount'] ?? ''))) || $cents <= 0) {
            $validator->addError('amount', 'Enter the amount, in pesos, with at most two decimal places.');
        }
        $disbursedOn = trim((string) ($input['disbursed_on'] ?? ''));
        if ($disbursedOn !== '' && $disbursedOn > date('Y-m-d')) {
            $validator->addError('disbursed_on', 'The date disbursed cannot be in the future.');
        }
        if ($validator->fails()) {
            throw ValidationException::withErrors($validator->errors());
        }

        return Database::transaction(static function () use ($cashier, $input, $cents, $disbursedOn): array {
            $reference = trim((string) ($input['reference_number'] ?? ''));
            $id = Database::insert('disbursement_vouchers', [
                'voucher_number'   => SequenceService::next('voucher', 'DV'),
                'payee'            => trim((string) $input['payee']),
                'particulars'      => trim((string) $input['particulars']),
                'amount'           => FinanceService::money($cents),
                'payment_method'   => (string) $input['payment_method'],
                'reference_number' => $reference !== '' ? $reference : null,
                'disbursed_at'     => $disbursedOn . ' ' . date('H:i:s'),
                'recorded_by'      => $cashier->id,
            ]);

            $voucher = DisbursementVoucher::find($id) ?? [];
            AuditService::log('disbursement.recorded', 'finance', 'DisbursementVoucher', $id, null, [
                'voucher' => $voucher['voucher_number'] ?? '', 'payee' => $voucher['payee'] ?? '',
                'amount' => $voucher['amount'] ?? '', 'method' => $voucher['payment_method'] ?? '',
            ], 'SUCCESS', null, $cashier->id, $cashier->role_name);

            return $voucher;
        });
    }

    private static function assertCashier(User $user): void
    {
        if (!$user->hasPermission('disbursements.manage')) {
            throw HttpException::forbidden('You do not have permission to record disbursements.');
        }
    }
}
