<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Core\ValidationException;
use App\Models\User;

/**
 * The school's bank accounts that students pay into (docs/14). Kept by the cashier or the Super Admin; deactivated, never deleted, since payments
 * refer to them.
 */
final class BankAccountService
{
    /** The payment methods that go through a bank, and so name a school bank account. */
    public const BANK_METHODS = ['Bank deposit', 'Bank transfer'];

    public static function isBankMethod(string $method): bool
    {
        return in_array($method, self::BANK_METHODS, true);
    }

    /** @return array<int,array<string,mixed>> every account, active first */
    public static function all(): array
    {
        return Database::select('SELECT * FROM school_bank_accounts ORDER BY is_active DESC, sort_order, bank_name, account_number');
    }

    /** @return array<int,array<string,mixed>> the accounts students can pay into */
    public static function active(): array
    {
        return Database::select('SELECT * FROM school_bank_accounts WHERE is_active = 1 ORDER BY sort_order, bank_name, account_number');
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM school_bank_accounts WHERE id = ?', [$id]);
    }

    /**
     * A bank payment's school account, checked: required once any account is active, and it must be an active one. Null (no account) is fine
     * before the school has set any up, or for a method that does not go through a bank.
     *
     * @return array{id:?int,error:?string}
     */
    public static function chosenFor(string $method, mixed $raw): array
    {
        if (!self::isBankMethod($method)) {
            return ['id' => null, 'error' => null];
        }
        $id = (int) $raw;
        if (self::active() === []) {
            return ['id' => null, 'error' => null];
        }
        if ($id <= 0) {
            return ['id' => null, 'error' => 'Choose the school bank account the payment was made to.'];
        }
        $account = self::find($id);
        if ($account === null || (int) $account['is_active'] !== 1) {
            return ['id' => null, 'error' => 'That bank account is not available. Choose one from the list.'];
        }

        return ['id' => $id, 'error' => null];
    }

    /**
     * Has this reference number already been used for this bank account, by a pending or verified submission or by a recorded payment?
     * A rejected submission does not count.
     */
    public static function referenceUsed(int $bankAccountId, string $reference, ?int $exceptSubmissionId = null): bool
    {
        $reference = trim($reference);
        if ($reference === '') {
            return false;
        }

        return Database::scalar(
            "SELECT 1 FROM payment_submissions WHERE bank_account_id = ? AND reference_number = ? AND status <> 'REJECTED' AND id <> ? LIMIT 1",
            [$bankAccountId, $reference, $exceptSubmissionId ?? 0]
        ) !== null || Database::scalar(
            'SELECT 1 FROM payments WHERE bank_account_id = ? AND reference_number = ? AND payment_submission_id IS NULL LIMIT 1',
            [$bankAccountId, $reference]
        ) !== null;
    }

    /**
     * @param array<string,mixed> $input bank_name, account_name, account_number, branch, note, is_active, sort_order
     * @throws ValidationException|HttpException
     */
    public static function save(User $user, ?int $id, array $input): int
    {
        self::assertAllowed($user);
        $clean = [
            'bank_name'      => trim((string) ($input['bank_name'] ?? '')),
            'account_name'   => trim((string) ($input['account_name'] ?? '')),
            'account_number' => trim((string) ($input['account_number'] ?? '')),
            'branch'         => trim((string) ($input['branch'] ?? '')),
            'note'           => trim((string) ($input['note'] ?? '')),
            'sort_order'     => trim((string) ($input['sort_order'] ?? '0')),
        ];

        $validator = Validator::make($clean, [
            'bank_name'      => 'required|max:100',
            'account_name'   => 'required|max:150',
            'account_number' => 'required|max:40',
            'branch'         => 'nullable|max:100',
            'note'           => 'nullable|max:255',
        ], ['bank_name' => 'Bank', 'account_name' => 'Account name', 'account_number' => 'Account number']);
        if ($clean['account_number'] !== '' && !preg_match('/^[0-9][0-9 \-]{3,38}[0-9]$/', $clean['account_number'])) {
            $validator->addError('account_number', 'The account number has digits only, with spaces or dashes if the bank prints them that way.');
        }
        if ($clean['sort_order'] !== '' && !preg_match('/^-?\d{1,4}$/', $clean['sort_order'])) {
            $validator->addError('sort_order', 'The order is a whole number, such as 1 or 2.');
        }
        if ($clean['bank_name'] !== '' && $clean['account_number'] !== ''
            && Database::scalar('SELECT 1 FROM school_bank_accounts WHERE bank_name = ? AND account_number = ? AND id <> ?', [$clean['bank_name'], $clean['account_number'], $id ?? 0]) !== null) {
            $validator->addError('account_number', 'That bank account is already on the list.');
        }
        if ($validator->fails()) {
            throw ValidationException::withErrors($validator->errors());
        }

        $data = [
            'bank_name' => $clean['bank_name'], 'account_name' => $clean['account_name'], 'account_number' => $clean['account_number'],
            'branch' => $clean['branch'] !== '' ? $clean['branch'] : null, 'note' => $clean['note'] !== '' ? $clean['note'] : null,
            'sort_order' => (int) ($clean['sort_order'] === '' ? 0 : $clean['sort_order']),
        ];

        return Database::transaction(static function () use ($user, $id, $data, $input): int {
            if ($id === null) {
                $newId = Database::insert('school_bank_accounts', $data + ['is_active' => !empty($input['is_active']) || !array_key_exists('is_active', $input) ? 1 : 0]);
                AuditService::log('finance.bank_account_created', 'finance', 'BankAccount', $newId, null, $data, 'SUCCESS', null, $user->id, $user->role_name);

                return $newId;
            }
            $before = self::find($id);
            if ($before === null) {
                throw HttpException::notFound('That bank account was not found.');
            }
            Database::update('school_bank_accounts', $data, ['id' => $id]);
            $old = array_intersect_key($before, $data);
            $changed = array_filter($old, static fn ($v, $k) => (string) $v !== (string) $data[$k], ARRAY_FILTER_USE_BOTH);
            if ($changed !== []) {
                AuditService::log('finance.bank_account_updated', 'finance', 'BankAccount', $id, $changed, array_intersect_key($data, $changed), 'SUCCESS', null, $user->id, $user->role_name);
            }

            return $id;
        });
    }

    /** Turn an account on or off. A switched-off account stays on the payments already made to it. @throws HttpException */
    public static function setActive(User $user, int $id, bool $active): void
    {
        self::assertAllowed($user);
        $account = self::find($id);
        if ($account === null) {
            throw HttpException::notFound('That bank account was not found.');
        }
        if ((int) $account['is_active'] === ($active ? 1 : 0)) {
            return;
        }
        Database::transaction(static function () use ($user, $id, $active, $account): void {
            Database::update('school_bank_accounts', ['is_active' => $active ? 1 : 0], ['id' => $id]);
            AuditService::log($active ? 'finance.bank_account_activated' : 'finance.bank_account_deactivated', 'finance', 'BankAccount', $id,
                ['is_active' => (int) $account['is_active']], ['is_active' => $active ? 1 : 0, 'bank' => $account['bank_name']], 'SUCCESS', null, $user->id, $user->role_name);
        });
    }

    private static function assertAllowed(User $user): void
    {
        if (!($user->isCashier() && $user->hasPermission('fees.manage')) && !($user->isSuperAdmin())) {
            throw HttpException::forbidden('Only the cashier or the administrator keeps the school\'s bank accounts.');
        }
    }
}