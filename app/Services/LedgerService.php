<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * The general ledger (docs/14), computed from the records that already exist: fee assessments,
 * adjustments, payments and their reversals, and disbursement vouchers. Nothing is stored; every
 * posting is a balanced debit and credit, so the trial balance always balances. Money in centavos.
 *
 * Accrual basis: an assessment debits Student Receivables and credits revenue, a payment debits cash
 * and credits Student Receivables. A payment is dated by when the cashier recorded it, like the
 * Collections report and the Cash Receipts Journal; a voucher by its disbursement date. Opening cash
 * balances and bank deposits/withdrawals (cash_movements) are posted when they were entered.
 *
 * No code voids an assessment today, so every assessment is posted as it stands. If voiding is ever
 * added, it must post the reversal here.
 */
final class LedgerService
{
    /**
     * Every posting up to and including the end of $to, oldest first.
     *
     * @return array<int,array{at:string,ref:string,particulars:string,account_id:int,debit:int,credit:int,source:string}>
     */
    public static function postings(string $to): array
    {
        $end = $to . ' 23:59:59';
        $ids = self::accountIds();
        $out = [];

        $cashFor = static fn (string $method): int => $method === 'Cash' ? $ids['code'][LedgerAccountService::CASH] : $ids['code'][LedgerAccountService::BANK];
        $post = static function (string $at, string $ref, string $particulars, int $debit, int $credit, int $cents, string $source) use (&$out): void {
            if ($cents === 0) {
                return;
            }
            $out[] = ['at' => $at, 'ref' => $ref, 'particulars' => $particulars, 'account_id' => $debit, 'debit' => $cents, 'credit' => 0, 'source' => $source];
            $out[] = ['at' => $at, 'ref' => $ref, 'particulars' => $particulars, 'account_id' => $credit, 'debit' => 0, 'credit' => $cents, 'source' => $source];
        };
        $receivables = $ids['code'][LedgerAccountService::RECEIVABLES];

        // Assessments: each fee line.
        foreach (Database::select(
            "SELECT fi.description, fi.amount, fa.assessment_number, fa.assessed_at, ft.fee_key, u.first_name, u.last_name
               FROM fee_items fi
               JOIN fee_assessments fa ON fa.id = fi.fee_assessment_id
               JOIN students st ON st.id = fa.student_id
               JOIN users u ON u.id = st.user_id
          LEFT JOIN fee_types ft ON ft.id = fi.fee_type_id
              WHERE fa.assessed_at <= ?
              ORDER BY fa.assessed_at, fa.id, fi.sort_order, fi.id",
            [$end]
        ) as $item) {
            $revenue = $item['fee_key'] !== null && isset($ids['fee'][$item['fee_key']]) ? $ids['fee'][$item['fee_key']] : $ids['code'][LedgerAccountService::OTHER_CHARGES];
            $post((string) $item['assessed_at'], (string) $item['assessment_number'], self::who($item) . ' · ' . $item['description'],
                $receivables, $revenue, FinanceService::cents($item['amount']), 'ASSESSMENT');
        }

        // Adjustments.
        foreach (Database::select(
            "SELECT adj.type, adj.amount, adj.reason, adj.adjustment_number, adj.created_at, u.first_name, u.last_name
               FROM financial_adjustments adj
               JOIN fee_assessments fa ON fa.id = adj.fee_assessment_id
               JOIN students st ON st.id = fa.student_id
               JOIN users u ON u.id = st.user_id
              WHERE adj.created_at <= ?
              ORDER BY adj.created_at, adj.id",
            [$end]
        ) as $adjustment) {
            $signed = FinanceService::signedCents($adjustment['amount']);
            $label = FinanceService::ADJUSTMENT_TYPES[$adjustment['type']] ?? (string) $adjustment['type'];
            $particulars = self::who($adjustment) . ' · ' . $label . ': ' . $adjustment['reason'];
            if ($signed < 0) {
                // Less owed: a discount-type adjustment is a contra-revenue; a correction undoes a charge.
                $debit = $adjustment['type'] === 'CORRECTION' ? $ids['code'][LedgerAccountService::OTHER_CHARGES] : $ids['code'][LedgerAccountService::DISCOUNTS];
                $post((string) $adjustment['created_at'], (string) $adjustment['adjustment_number'], $particulars, $debit, $receivables, -$signed, 'ADJUSTMENT');
            } else {
                $credit = match ($adjustment['type']) {
                    'BRIDGING'    => $ids['code'][LedgerAccountService::BRIDGING],
                    'OJT_FEE'     => $ids['code'][LedgerAccountService::OJT],
                    // Carried over from before: not revenue earned now.
                    'OLD_ACCOUNT' => $ids['code'][LedgerAccountService::OPENING_BALANCES],
                    default       => $ids['code'][LedgerAccountService::OTHER_CHARGES],
                };
                $post((string) $adjustment['created_at'], (string) $adjustment['adjustment_number'], $particulars, $receivables, $credit, $signed, 'ADJUSTMENT');
            }
        }

        // Payments and their reversals.
        foreach (Database::select(
            "SELECT pay.amount, pay.payment_method, pay.receipt_number, pay.created_at, pay.reversed_payment_id,
                    orig.receipt_number AS reversed_receipt_number, u.first_name, u.last_name
               FROM payments pay
               JOIN students st ON st.id = pay.student_id
               JOIN users u ON u.id = st.user_id
          LEFT JOIN payments orig ON orig.id = pay.reversed_payment_id
              WHERE pay.created_at <= ?
              ORDER BY pay.created_at, pay.id",
            [$end]
        ) as $payment) {
            $signed = FinanceService::signedCents($payment['amount']);
            $cash = $cashFor((string) $payment['payment_method']);
            if ($payment['reversed_payment_id'] === null) {
                $post((string) $payment['created_at'], (string) $payment['receipt_number'], self::who($payment) . ' · Payment, ' . $payment['payment_method'], $cash, $receivables, $signed, 'PAYMENT');
            } else {
                $post((string) $payment['created_at'], 'Rev. ' . $payment['reversed_receipt_number'], self::who($payment) . ' · Reversal of ' . $payment['reversed_receipt_number'], $receivables, $cash, -$signed, 'PAYMENT');
            }
        }

        // Disbursements.
        foreach (Database::select(
            'SELECT voucher_number, payee, particulars, amount, payment_method, disbursed_at, expense_account_id
               FROM disbursement_vouchers WHERE disbursed_at <= ? ORDER BY disbursed_at, id',
            [$end]
        ) as $voucher) {
            $expense = $voucher['expense_account_id'] !== null ? (int) $voucher['expense_account_id'] : $ids['code'][LedgerAccountService::UNCATEGORIZED];
            $post((string) $voucher['disbursed_at'], (string) $voucher['voucher_number'], $voucher['payee'] . ': ' . $voucher['particulars'],
                $expense, $cashFor((string) $voucher['payment_method']), FinanceService::cents($voucher['amount']), 'VOUCHER');
        }

        // Opening cash balances and moves between the cash and bank accounts.
        $cash = $ids['code'][LedgerAccountService::CASH];
        $bank = $ids['code'][LedgerAccountService::BANK];
        foreach (Database::select(
            'SELECT movement_number, movement_type, opening_key, entered_at, amount, reference_number FROM cash_movements WHERE entered_at <= ? ORDER BY entered_at, id',
            [$end]
        ) as $movement) {
            $cents = FinanceService::cents($movement['amount']);
            $ref = (string) $movement['movement_number'];
            $slip = $movement['reference_number'] !== null ? ' (' . $movement['reference_number'] . ')' : '';
            if ($movement['movement_type'] === 'OPENING') {
                $target = $movement['opening_key'] === 'BANK' ? $bank : $cash;
                $post((string) $movement['entered_at'], $ref, 'Opening balance, ' . ($movement['opening_key'] === 'BANK' ? 'Bank and E-wallets' : 'Cash on Hand'),
                    $target, $ids['code'][LedgerAccountService::OPENING_CASH], $cents, 'OPENING');
            } elseif ($movement['movement_type'] === 'DEPOSIT') {
                $post((string) $movement['entered_at'], $ref, 'Deposit to bank' . $slip, $bank, $cash, $cents, 'CASH_MOVE');
            } else {
                $post((string) $movement['entered_at'], $ref, 'Withdrawal from bank' . $slip, $cash, $bank, $cents, 'CASH_MOVE');
            }
        }

        // Stable order: by time, then the order the postings were made in.
        foreach ($out as $position => &$posting) {
            $posting['position'] = $position;
        }
        unset($posting);
        usort($out, static fn (array $a, array $b) => [$a['at'], $a['position']] <=> [$b['at'], $b['position']]);

        return $out;
    }

    /**
     * Every account's opening balance, debits, credits, and closing balance for a date range: an
     * extended trial balance. Balances are net debit (a credit balance is negative).
     *
     * @return array{rows:array<int,array<string,mixed>>,totals:array<string,int>,balanced:bool}
     */
    public static function trialBalance(string $from, string $to): array
    {
        $start = $from . ' 00:00:00';
        $accounts = [];
        foreach (LedgerAccountService::all() as $account) {
            $accounts[(int) $account['id']] = $account + ['opening' => 0, 'debit' => 0, 'credit' => 0, 'closing' => 0, 'active_postings' => 0];
        }

        foreach (self::postings($to) as $posting) {
            $row = &$accounts[$posting['account_id']];
            if ($posting['at'] < $start) {
                $row['opening'] += $posting['debit'] - $posting['credit'];
            } else {
                $row['debit'] += $posting['debit'];
                $row['credit'] += $posting['credit'];
                $row['active_postings']++;
            }
            unset($row);
        }

        $rows = [];
        $totals = ['openingDr' => 0, 'openingCr' => 0, 'debit' => 0, 'credit' => 0, 'closingDr' => 0, 'closingCr' => 0];
        foreach ($accounts as $account) {
            $account['closing'] = $account['opening'] + $account['debit'] - $account['credit'];
            // An account with nothing in it, and switched off, only adds clutter.
            if ((int) $account['is_active'] === 0 && $account['opening'] === 0 && $account['debit'] === 0 && $account['credit'] === 0) {
                continue;
            }
            $rows[] = $account;
            $totals['openingDr'] += max(0, $account['opening']);
            $totals['openingCr'] += max(0, -$account['opening']);
            $totals['debit'] += $account['debit'];
            $totals['credit'] += $account['credit'];
            $totals['closingDr'] += max(0, $account['closing']);
            $totals['closingCr'] += max(0, -$account['closing']);
        }

        return [
            'rows'     => $rows,
            'totals'   => $totals,
            'balanced' => $totals['openingDr'] === $totals['openingCr'] && $totals['debit'] === $totals['credit'] && $totals['closingDr'] === $totals['closingCr'],
        ];
    }

    /**
     * One account's ledger for a date range: the opening balance, then each posting with the running
     * balance. Balances are net debit (a credit balance is negative).
     *
     * @return array{account:array<string,mixed>,opening:int,rows:array<int,array<string,mixed>>,debit:int,credit:int,closing:int}|null
     */
    public static function accountLedger(int $accountId, string $from, string $to): ?array
    {
        $account = LedgerAccountService::find($accountId);
        if ($account === null) {
            return null;
        }

        $start = $from . ' 00:00:00';
        $opening = 0;
        $rows = [];
        $debit = $credit = 0;
        foreach (self::postings($to) as $posting) {
            if ($posting['account_id'] !== $accountId) {
                continue;
            }
            if ($posting['at'] < $start) {
                $opening += $posting['debit'] - $posting['credit'];
                continue;
            }
            $debit += $posting['debit'];
            $credit += $posting['credit'];
            $rows[] = $posting + ['balance' => $opening + $debit - $credit];
        }

        return ['account' => $account, 'opening' => $opening, 'rows' => $rows, 'debit' => $debit, 'credit' => $credit, 'closing' => $opening + $debit - $credit];
    }

    /** "Dr" or "Cr" for a net-debit balance. */
    public static function side(int $net): string
    {
        return $net >= 0 ? 'Dr' : 'Cr';
    }

    /** @param array<string,mixed> $row */
    private static function who(array $row): string
    {
        return $row['last_name'] . ', ' . $row['first_name'];
    }

    /**
     * The account ids by code and, for revenue accounts, by fee key.
     *
     * @return array{code:array<string,int>,fee:array<string,int>}
     */
    private static function accountIds(): array
    {
        $ids = ['code' => [], 'fee' => []];
        foreach (Database::select('SELECT id, code, fee_key FROM ledger_accounts') as $account) {
            $ids['code'][(string) $account['code']] = (int) $account['id'];
            if ($account['fee_key'] !== null) {
                $ids['fee'][(string) $account['fee_key']] = (int) $account['id'];
            }
        }
        foreach ([LedgerAccountService::CASH, LedgerAccountService::BANK, LedgerAccountService::RECEIVABLES, LedgerAccountService::OPENING_BALANCES,
                  LedgerAccountService::OPENING_CASH, LedgerAccountService::BRIDGING, LedgerAccountService::OJT, LedgerAccountService::OTHER_CHARGES, LedgerAccountService::DISCOUNTS,
                  LedgerAccountService::UNCATEGORIZED] as $code) {
            if (!isset($ids['code'][$code])) {
                throw new \RuntimeException("The ledger account {$code} is missing. Run: php database/seed.php");
            }
        }

        return $ids;
    }
}
