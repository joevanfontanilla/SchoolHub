<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * The cash book (docs/14): one month of one cash account, laid out like the school's paper book. Cash
 * Inflow on the left starting with Balance C/F, Cash Outflow on the right, then a summary. Computed from
 * LedgerService's postings to Cash on Hand (1010) or Cash in Bank and E-wallets (1020), so it always
 * agrees with the ledger, the Cash Receipts Journal, and the Cash Disbursements Journal. Centavos.
 */
final class CashBookService
{
    /**
     * @param string $month   Y-m
     * @param string $account CASH or BANK
     * @return array{month:string,account:string,from:string,to:string,carriedForward:int,inflows:array<int,array<string,mixed>>,outflows:array<int,array<string,mixed>>,totalInflow:int,totalOutflow:int,closing:int,counted:?array<string,mixed>}
     */
    public static function month(string $month, string $account): array
    {
        $from = $month . '-01';
        $to = date('Y-m-t', strtotime($from));
        $code = $account === 'BANK' ? LedgerAccountService::BANK : LedgerAccountService::CASH;
        $accountId = (int) Database::scalar('SELECT id FROM ledger_accounts WHERE code = ?', [$code]);
        $start = $from . ' 00:00:00';

        $carried = 0;
        $inflows = $outflows = [];
        foreach (LedgerService::postings($to) as $posting) {
            if ($posting['account_id'] !== $accountId) {
                continue;
            }
            $net = $posting['debit'] - $posting['credit'];

            // Everything before the month, and an opening balance dated on the first, is carried forward.
            if ($posting['at'] < $start || ($posting['source'] === 'OPENING' && $posting['at'] <= $start)) {
                $carried += $net;
                continue;
            }

            $row = ['at' => $posting['at'], 'ref' => $posting['ref'], 'particulars' => $posting['particulars']];
            if ($posting['debit'] > 0) {
                $inflows[] = $row + ['amount' => $posting['debit']];
            } elseif ($posting['source'] === 'PAYMENT') {
                // A reversed payment is a negative receipt on the day it was reversed, like the CRJ.
                $inflows[] = $row + ['amount' => -$posting['credit']];
            } else {
                $outflows[] = $row + ['amount' => $posting['credit']];
            }
        }

        $totalInflow = $carried + array_sum(array_column($inflows, 'amount'));
        $totalOutflow = array_sum(array_column($outflows, 'amount'));

        return [
            'month'          => $month,
            'account'        => $account,
            'from'           => $from,
            'to'             => $to,
            'carriedForward' => $carried,
            'inflows'        => $inflows,
            'outflows'       => $outflows,
            'totalInflow'    => $totalInflow,
            'totalOutflow'   => $totalOutflow,
            'closing'        => $totalInflow - $totalOutflow,
            'counted'        => $account === 'CASH' ? self::counted($from, $to) : null,
        ];
    }

    /**
     * What Close the Day recorded in the period: the cash counted, the over/short, and the days that
     * had cash activity but were not closed.
     *
     * @return array{counted:int,difference:int,closedDays:int,openDays:int}
     */
    private static function counted(string $from, string $to): array
    {
        $closed = Database::selectOne(
            "SELECT COUNT(*) AS days, COALESCE(SUM(counted_cash), 0) AS counted, COALESCE(SUM(difference), 0) AS difference
               FROM day_closes WHERE status = 'CLOSED' AND close_date BETWEEN ? AND ?",
            [$from, $to]
        );

        // A day had cash activity when a cash payment, a cash voucher, or a bank move was recorded on it.
        $active = Database::select(
            "SELECT d FROM (
                SELECT DATE(created_at) AS d FROM payments WHERE payment_method = 'Cash'
                UNION SELECT DATE(created_at) FROM disbursement_vouchers WHERE payment_method = 'Cash'
                UNION SELECT DATE(entered_at) FROM cash_movements WHERE movement_type <> 'OPENING'
             ) days WHERE d BETWEEN ? AND ?",
            [$from, $to]
        );
        $closedDates = array_column(Database::select("SELECT close_date FROM day_closes WHERE status = 'CLOSED' AND close_date BETWEEN ? AND ?", [$from, $to]), 'close_date');
        $open = count(array_diff(array_column($active, 'd'), $closedDates));

        return [
            'counted'    => FinanceService::cents((string) $closed['counted']),
            'difference' => FinanceService::signedCents((string) $closed['difference']),
            'closedDays' => (int) $closed['days'],
            'openDays'   => $open,
        ];
    }

    /** A valid Y-m, or this month. */
    public static function normalizeMonth(mixed $month): string
    {
        $month = is_string($month) ? $month : '';
        $parsed = \DateTime::createFromFormat('!Y-m', $month);

        return $parsed !== false && $parsed->format('Y-m') === $month && $month <= date('Y-m') ? $month : date('Y-m');
    }

    public static function normalizeAccount(mixed $account): string
    {
        return $account === 'BANK' ? 'BANK' : 'CASH';
    }
}
