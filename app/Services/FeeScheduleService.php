<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\User;

/**
 * The fee schedule (docs/14), maintained by the cashier. A change applies to
 * assessments issued from then on; an issued assessment keeps the amounts it was
 * issued with. Fees are deactivated, never deleted.
 */
final class FeeScheduleService
{
    public const BASES = [
        'PER_UNIT'     => 'Per unit (all units)',
        'PER_LAB_UNIT' => 'Per laboratory unit',
        'FIXED'        => 'Fixed, once per term',
    ];

    /** @param array<string,mixed> $input */
    public static function save(User $user, ?int $id, array $input): int
    {
        if (!$user->isCashier()) {
            throw HttpException::forbidden('Only the cashier can change the fee schedule.');
        }
        $input['code'] = strtoupper(trim((string) ($input['code'] ?? '')));
        $amount = trim((string) ($input['amount'] ?? ''));
        $programId = (int) ($input['program_id'] ?? 0) ?: null;
        $yearLevelId = (int) ($input['year_level_id'] ?? 0) ?: null;
        $replaces = strtoupper(trim((string) ($input['replaces'] ?? '')));

        $validator = Validator::make($input, [
            'code'        => 'required|max:30|alpha_dash',
            'name'        => 'required|max:100',
            'description' => 'nullable|max:255',
            'basis'       => 'required|in:' . implode(',', array_keys(self::BASES)),
            'amount'      => 'required',
            'sort_order'  => 'nullable|integer',
        ], ['basis' => 'Charged']);

        if ($amount !== '' && (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $amount) || FinanceService::cents($amount) <= 0)) {
            $validator->addError('amount', 'Enter an amount in pesos greater than zero, with at most two decimal places.');
        }
        $taken = (int) Database::scalar('SELECT COUNT(*) FROM fee_types WHERE code = ? AND id <> ?', [$input['code'], $id ?? 0]) > 0;
        if ($taken) {
            $validator->addError('code', "The code {$input['code']} is already in use.");
        }
        if ($programId !== null && (int) Database::scalar('SELECT COUNT(*) FROM programs WHERE id = ?', [$programId]) === 0) {
            $validator->addError('program_id', 'Choose a program from the list.');
        }
        if ($yearLevelId !== null && (int) Database::scalar('SELECT COUNT(*) FROM year_levels WHERE id = ?', [$yearLevelId]) === 0) {
            $validator->addError('year_level_id', 'Choose a year level from the list.');
        }
        $scoped = $programId !== null || $yearLevelId !== null;
        if ($replaces !== '' && !$scoped) {
            $validator->addError('replaces', 'A fee that applies to everyone cannot replace another one. Choose a program or year level, or leave this blank.');
        }
        if ($replaces !== '' && (int) Database::scalar('SELECT COUNT(*) FROM fee_types WHERE fee_key = ?', [$replaces]) === 0) {
            $validator->addError('replaces', 'Choose a fee from the list.');
        }

        // The fee this line belongs to. A scoped line replaces the one it names (or stands alone);
        // a line for everyone keeps its key when edited, so lines that replace it stay attached.
        $existing = $id !== null ? Database::selectOne('SELECT * FROM fee_types WHERE id = ?', [$id]) : null;
        $feeKey = match (true) {
            $scoped && $replaces !== '' => $replaces,
            !$scoped && $existing !== null && $existing['program_id'] === null && $existing['year_level_id'] === null => (string) $existing['fee_key'],
            default => $input['code'],
        };
        $duplicate = (int) Database::scalar(
            'SELECT COUNT(*) FROM fee_types WHERE fee_key = ? AND program_id <=> ? AND year_level_id <=> ? AND id <> ?',
            [$feeKey, $programId, $yearLevelId, $id ?? 0]
        ) > 0;
        if ($duplicate) {
            $validator->addError('replaces', 'There is already a line for that fee in the same program and year level. Edit it instead.');
        }
        if ($validator->fails()) {
            throw ValidationException::withErrors($validator->errors());
        }

        $data = [
            'code'        => $input['code'],
            'fee_key'     => $feeKey,
            'program_id'  => $programId,
            'year_level_id' => $yearLevelId,
            'name'        => trim((string) $input['name']),
            'description' => trim((string) ($input['description'] ?? '')) ?: null,
            'basis'       => (string) $input['basis'],
            'amount'      => FinanceService::money(FinanceService::cents($amount)),
            'sort_order'  => (int) ($input['sort_order'] ?? 0),
            'is_active'   => !empty($input['is_active']) ? 1 : 0,
        ];

        return Database::transaction(static function () use ($user, $id, $data): int {
            if ($id === null) {
                $newId = Database::insert('fee_types', $data);
                // Every fee line has a revenue account in the general ledger (docs/14).
                if ($data['program_id'] === null && $data['year_level_id'] === null) {
                    LedgerAccountService::ensureFeeAccount($data['fee_key'], $data['name']);
                }
                AuditService::log('finance.fee_created', 'finance', 'FeeType', $newId, null, $data, 'SUCCESS', null, $user->id, $user->role_name);
                return $newId;
            }
            $before = Database::selectOne('SELECT * FROM fee_types WHERE id = ? FOR UPDATE', [$id]);
            if ($before === null) {
                throw HttpException::notFound('That fee was not found.');
            }
            Database::update('fee_types', $data, ['id' => $id]);
            AuditService::log('finance.fee_updated', 'finance', 'FeeType', $id, array_intersect_key($before, $data), $data, 'SUCCESS', null, $user->id, $user->role_name);
            return $id;
        });
    }
}
