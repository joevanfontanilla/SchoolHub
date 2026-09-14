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
        if ($validator->fails()) {
            throw ValidationException::withErrors($validator->errors());
        }

        $data = [
            'code'        => $input['code'],
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
