<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\User;

/**
 * The chart of accounts for the cashier's general ledger (docs/14). The ledger itself is computed by
 * LedgerService; this holds the accounts: the ones the ledger depends on, one revenue account per fee
 * line of the fee schedule, and the expense categories the cashier chooses for a voucher.
 */
final class LedgerAccountService
{
    public const ASSET = 'ASSET';
    public const EQUITY = 'EQUITY';
    public const REVENUE = 'REVENUE';
    public const CONTRA_REVENUE = 'CONTRA_REVENUE';
    public const EXPENSE = 'EXPENSE';

    public const TYPES = [
        self::ASSET          => 'Assets',
        self::EQUITY         => 'Equity',
        self::REVENUE        => 'Revenue',
        self::CONTRA_REVENUE => 'Contra-revenue',
        self::EXPENSE        => 'Expenses',
    ];

    // The accounts the ledger posts to by code.
    public const CASH = '1010';
    public const BANK = '1020';
    public const RECEIVABLES = '1100';
    public const OPENING_BALANCES = '3010';
    public const BRIDGING = '4800';
    public const OJT = '4810';
    public const OTHER_CHARGES = '4890';
    public const DISCOUNTS = '4950';
    public const UNCATEGORIZED = '5990';

    /** code, name, type, system? Accounts the ledger depends on, and the starting expense categories. */
    private const STARTER = [
        [self::CASH, 'Cash on Hand', self::ASSET, true],
        [self::BANK, 'Cash in Bank and E-wallets', self::ASSET, true],
        [self::RECEIVABLES, 'Student Receivables', self::ASSET, true],
        [self::OPENING_BALANCES, 'Opening Balances (Old Accounts)', self::EQUITY, true],
        [self::BRIDGING, 'Bridging Fees', self::REVENUE, true],
        [self::OJT, 'OJT Fees', self::REVENUE, true],
        [self::OTHER_CHARGES, 'Other Charges and Fees', self::REVENUE, true],
        [self::DISCOUNTS, 'Scholarships and Discounts', self::CONTRA_REVENUE, true],
        ['5010', 'Salaries and Wages', self::EXPENSE, false],
        ['5020', 'Utilities', self::EXPENSE, false],
        ['5030', 'Office Supplies', self::EXPENSE, false],
        ['5040', 'Repairs and Maintenance', self::EXPENSE, false],
        ['5050', 'Communication', self::EXPENSE, false],
        ['5060', 'Transportation', self::EXPENSE, false],
        ['5070', 'Student Activities', self::EXPENSE, false],
        ['5900', 'Miscellaneous Expense', self::EXPENSE, false],
        [self::UNCATEGORIZED, 'Uncategorized Expense', self::EXPENSE, true],
    ];

    /** Debit-normal types; the rest are credit-normal. */
    public static function isDebitNormal(string $type): bool
    {
        return in_array($type, [self::ASSET, self::EXPENSE, self::CONTRA_REVENUE], true);
    }

    /**
     * Create what is missing: the starter accounts, and a revenue account for every fee line of the
     * fee schedule. Re-runnable; an account is never changed or removed. Returns how many were added.
     */
    public static function ensureAccounts(): int
    {
        $added = 0;
        foreach (self::STARTER as $sort => [$code, $name, $type, $system]) {
            $added += Database::query(
                'INSERT IGNORE INTO ledger_accounts (code, name, type, is_system, is_active) VALUES (?, ?, ?, ?, 1)',
                [$code, $name, $type, $system ? 1 : 0]
            )->rowCount();
        }

        return $added + self::syncFeeAccounts();
    }

    /** Revenue accounts for fee lines that have none yet. Returns how many were created. */
    public static function syncFeeAccounts(): int
    {
        $created = 0;
        foreach (Database::select(
            'SELECT ft.fee_key, ft.name FROM fee_types ft
              WHERE ft.program_id IS NULL AND ft.year_level_id IS NULL
                AND NOT EXISTS (SELECT 1 FROM ledger_accounts la WHERE la.fee_key = ft.fee_key)
              ORDER BY ft.sort_order, ft.id'
        ) as $fee) {
            if (self::ensureFeeAccount((string) $fee['fee_key'], (string) $fee['name']) !== null) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * The revenue account of a fee line, created on first use. Returns the new id, or null when the
     * fee line already had an account.
     */
    public static function ensureFeeAccount(string $feeKey, string $name): ?int
    {
        if ((int) Database::scalar('SELECT COUNT(*) FROM ledger_accounts WHERE fee_key = ?', [$feeKey]) > 0) {
            return null;
        }

        // 4100, 4110, ...: the next free code in the fee-line range.
        $code = (int) Database::scalar("SELECT COALESCE(MAX(CAST(code AS UNSIGNED)), 4090) FROM ledger_accounts WHERE type = 'REVENUE' AND code BETWEEN '4100' AND '4799'") + 10;
        while ((int) Database::scalar('SELECT COUNT(*) FROM ledger_accounts WHERE code = ?', [(string) $code]) > 0) {
            $code += 10;
        }

        return Database::insert('ledger_accounts', [
            'code' => (string) $code, 'name' => $name, 'type' => self::REVENUE, 'fee_key' => $feeKey, 'is_system' => 1, 'is_active' => 1,
        ]);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM ledger_accounts WHERE id = ?', [$id]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        return Database::select('SELECT * FROM ledger_accounts ORDER BY code');
    }

    /**
     * The expense accounts a voucher can be charged to. Uncategorized Expense is where old vouchers
     * wait, so it is never a choice.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function expenseAccounts(bool $activeOnly = true): array
    {
        return Database::select(
            "SELECT * FROM ledger_accounts WHERE type = 'EXPENSE' AND code <> ?" . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY code',
            [self::UNCATEGORIZED]
        );
    }

    /** Whether an account can be chosen for a voucher: an active expense account other than Uncategorized. */
    public static function isVoucherAccount(?array $account): bool
    {
        return $account !== null && $account['type'] === self::EXPENSE && (int) $account['is_active'] === 1 && $account['code'] !== self::UNCATEGORIZED;
    }

    /**
     * Add an expense account (docs/14). Code 5000 to 5899; 5990 is reserved for Uncategorized Expense.
     *
     * @param array<string,mixed> $input code, name
     * @throws ValidationException|HttpException
     */
    public static function addExpenseAccount(User $user, array $input): int
    {
        self::assertManager($user);
        $code = trim((string) ($input['code'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));

        $validator = Validator::make(['code' => $code, 'name' => $name], [
            'code' => 'required',
            'name' => 'required|min:3|max:100',
        ], ['code' => 'Code', 'name' => 'Name']);
        if ($code !== '' && (!preg_match('/^5\d{3}$/', $code) || (int) $code > 5899)) {
            $validator->addError('code', 'An expense account code has four digits, from 5000 to 5899.');
        } elseif ($code !== '' && (int) Database::scalar('SELECT COUNT(*) FROM ledger_accounts WHERE code = ?', [$code]) > 0) {
            $validator->addError('code', "The code {$code} is already in use.");
        }
        if ($name !== '' && (int) Database::scalar("SELECT COUNT(*) FROM ledger_accounts WHERE type = 'EXPENSE' AND LOWER(name) = LOWER(?)", [$name]) > 0) {
            $validator->addError('name', 'There is already an expense account with that name.');
        }
        if ($validator->fails()) {
            throw ValidationException::withErrors($validator->errors());
        }

        return Database::transaction(static function () use ($user, $code, $name): int {
            $id = Database::insert('ledger_accounts', ['code' => $code, 'name' => $name, 'type' => self::EXPENSE, 'is_system' => 0, 'is_active' => 1]);
            AuditService::log('ledger.account_added', 'finance', 'LedgerAccount', $id, null, ['code' => $code, 'name' => $name, 'type' => self::EXPENSE], 'SUCCESS', null, $user->id, $user->role_name);

            return $id;
        });
    }

    /** Switch an expense account on or off. System and fee-line accounts cannot be switched off. */
    public static function setActive(User $user, int $accountId, bool $active): void
    {
        self::assertManager($user);
        $account = self::find($accountId) ?? throw HttpException::notFound('That account was not found.');
        if ((int) $account['is_system'] === 1 || $account['type'] !== self::EXPENSE) {
            throw ValidationException::withMessage('This account is part of the ledger itself and cannot be switched off.');
        }

        Database::transaction(static function () use ($user, $account, $active): void {
            Database::update('ledger_accounts', ['is_active' => $active ? 1 : 0], ['id' => $account['id']]);
            AuditService::log('ledger.account_' . ($active ? 'enabled' : 'disabled'), 'finance', 'LedgerAccount', (int) $account['id'], null, ['code' => $account['code']], 'SUCCESS', null, $user->id, $user->role_name);
        });
    }

    /**
     * Give a voucher recorded before categories existed its expense account. Allowed once: a categorized
     * voucher is permanent, like the rest of it.
     */
    public static function categorizeVoucher(User $user, int $voucherId, int $accountId): void
    {
        self::assertManager($user);

        Database::transaction(static function () use ($user, $voucherId, $accountId): void {
            $voucher = Database::selectOne('SELECT * FROM disbursement_vouchers WHERE id = ? FOR UPDATE', [$voucherId])
                ?? throw HttpException::notFound('That voucher was not found.');
            if ($voucher['expense_account_id'] !== null) {
                throw ValidationException::withMessage('This voucher already has an expense account. Vouchers are permanent.');
            }
            $account = self::find($accountId);
            if (!self::isVoucherAccount($account)) {
                throw ValidationException::withErrors(['expense_account_id' => ['Choose an expense account from the list.']], 'Choose an expense account from the list.');
            }

            Database::update('disbursement_vouchers', ['expense_account_id' => $accountId], ['id' => $voucherId]);
            AuditService::log('disbursement.categorized', 'finance', 'DisbursementVoucher', $voucherId, null, [
                'voucher' => $voucher['voucher_number'], 'account' => $account['code'],
            ], 'SUCCESS', null, $user->id, $user->role_name);
        });
    }

    private static function assertManager(User $user): void
    {
        if (!$user->hasPermission('disbursements.manage')) {
            throw HttpException::forbidden('You do not have permission to change the chart of accounts.');
        }
    }
}
