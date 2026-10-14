<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Core\ValidationException;
use App\Models\User;

/**
 * Opening cash balances and bank deposits/withdrawals (docs/14, The Cash Book). Entered by the
 * cashier (`disbursements.manage`). A deposit or withdrawal is append-only, like a voucher: a mistake
 * is fixed with the opposite entry. An opening balance can be corrected, and every change is audited.
 * Money in whole centavos.
 */
final class CashMovementService
{
    public const DEPOSIT = 'DEPOSIT';
    public const WITHDRAWAL = 'WITHDRAWAL';
    public const OPENING = 'OPENING';

    /** The two cash accounts, by the key an opening balance is stored under. */
    public const ACCOUNTS = ['CASH' => 'Cash on Hand', 'BANK' => 'Bank and E-wallets'];

    /**
     * Record a deposit to the bank (cash out, bank in) or a withdrawal (bank out, cash in), now.
     *
     * @param array<string,mixed> $input kind (DEPOSIT|WITHDRAWAL), amount, reference_number, remarks
     * @return array<string,mixed> the stored movement
     */
    public static function record(User $cashier, array $input): array
    {
        self::assertCashier($cashier);

        $validator = Validator::make($input, [
            'kind'             => 'required|in:' . self::DEPOSIT . ',' . self::WITHDRAWAL,
            'amount'           => 'required|numeric',
            'reference_number' => 'nullable|max:100',
            'remarks'          => 'nullable|max:500',
        ], ['kind' => 'Type', 'reference_number' => 'Reference number']);
        $cents = self::amountCents($input['amount'] ?? '', $validator);
        if ($validator->fails()) {
            throw ValidationException::withErrors($validator->errors());
        }

        return Database::transaction(static function () use ($cashier, $input, $cents): array {
            DayCloseService::assertOpen();

            $id = Database::insert('cash_movements', [
                'movement_number'  => SequenceService::next('cash_movement', 'CM'),
                'movement_type'    => (string) $input['kind'],
                'entered_at'       => date('Y-m-d H:i:s'),
                'amount'           => FinanceService::money($cents),
                'reference_number' => trim((string) ($input['reference_number'] ?? '')) ?: null,
                'remarks'          => trim((string) ($input['remarks'] ?? '')) ?: null,
                'recorded_by'      => $cashier->id,
            ]);
            $movement = Database::selectOne('SELECT * FROM cash_movements WHERE id = ?', [$id]) ?? [];

            AuditService::log('cash.movement_recorded', 'finance', 'CashMovement', $id, null, [
                'number' => $movement['movement_number'] ?? '', 'type' => $input['kind'], 'amount' => $movement['amount'] ?? '',
            ], 'SUCCESS', null, $cashier->id, $cashier->role_name);

            return $movement;
        });
    }

    /**
     * Set (or correct) the opening balance of Cash on Hand or Bank: what the school held on a date.
     *
     * @param array<string,mixed> $input account (CASH|BANK), amount, as_of (Y-m-d)
     * @return array<string,mixed>
     */
    public static function setOpening(User $cashier, array $input): array
    {
        self::assertCashier($cashier);

        $validator = Validator::make($input, [
            'account' => 'required|in:' . implode(',', array_keys(self::ACCOUNTS)),
            'amount'  => 'required|numeric',
            'as_of'   => 'required|date',
        ], ['account' => 'Account', 'as_of' => 'Date']);
        $cents = self::amountCents($input['amount'] ?? '', $validator, true);
        $asOf = trim((string) ($input['as_of'] ?? ''));
        if ($asOf !== '' && $asOf > date('Y-m-d')) {
            $validator->addError('as_of', 'The opening balance cannot be dated in the future.');
        }
        if ($validator->fails()) {
            throw ValidationException::withErrors($validator->errors());
        }

        return Database::transaction(static function () use ($cashier, $input, $cents, $asOf): array {
            $key = (string) $input['account'];
            $existing = Database::selectOne('SELECT * FROM cash_movements WHERE opening_key = ? FOR UPDATE', [$key]);
            $row = ['entered_at' => $asOf . ' 00:00:00', 'amount' => FinanceService::money($cents), 'recorded_by' => $cashier->id];

            if ($existing === null) {
                $id = Database::insert('cash_movements', $row + [
                    'movement_number' => SequenceService::next('cash_movement', 'CM'),
                    'movement_type'   => self::OPENING,
                    'opening_key'     => $key,
                ]);
                AuditService::log('cash.opening_set', 'finance', 'CashMovement', $id, null,
                    ['account' => $key, 'amount' => $row['amount'], 'as_of' => $asOf], 'SUCCESS', null, $cashier->id, $cashier->role_name);
            } else {
                $id = (int) $existing['id'];
                Database::update('cash_movements', $row, ['id' => $id]);
                AuditService::log('cash.opening_changed', 'finance', 'CashMovement', $id,
                    ['amount' => $existing['amount'], 'as_of' => substr((string) $existing['entered_at'], 0, 10)],
                    ['account' => $key, 'amount' => $row['amount'], 'as_of' => $asOf], 'SUCCESS', null, $cashier->id, $cashier->role_name);
            }

            return Database::selectOne('SELECT * FROM cash_movements WHERE id = ?', [$id]) ?? [];
        });
    }

    /** @return array<string,array<string,mixed>> the opening balances stored, by CASH / BANK */
    public static function openings(): array
    {
        $out = [];
        foreach (Database::select("SELECT * FROM cash_movements WHERE movement_type = 'OPENING'") as $row) {
            $out[(string) $row['opening_key']] = $row;
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> the most recent deposits and withdrawals, newest first */
    public static function recent(int $limit = 10): array
    {
        return Database::select(
            "SELECT cm.*, CONCAT(u.first_name, ' ', u.last_name) AS recorded_by_name
               FROM cash_movements cm JOIN users u ON u.id = cm.recorded_by
              WHERE cm.movement_type <> 'OPENING' ORDER BY cm.entered_at DESC, cm.id DESC LIMIT " . max(1, $limit)
        );
    }

    private static function amountCents(mixed $amount, Validator $validator, bool $zeroAllowed = false): int
    {
        $text = trim((string) $amount);
        $cents = FinanceService::cents($text);
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $text) || ($cents <= 0 && !$zeroAllowed)) {
            $validator->addError('amount', $zeroAllowed
                ? 'Enter the amount, in pesos, with at most two decimal places. Enter 0 if there was none.'
                : 'Enter the amount, in pesos, with at most two decimal places.');
        }

        return $cents;
    }

    private static function assertCashier(User $user): void
    {
        if (!$user->isCashier()) {
            throw HttpException::forbidden('Only the cashier can record cash movements.');
        }
    }
}
