<?php

declare(strict_types=1);

namespace App\Controllers\Cashier;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Core\ValidationException;
use App\Services\AuditService;
use App\Services\CashBookService;
use App\Services\CashMovementService;
use App\Services\CsvExport;
use App\Services\FinanceService;

/**
 * The cash book (docs/14): a month of Cash on Hand or of Bank and E-wallets, laid out like the
 * school's paper book, plus the entries that feed it: opening balances and bank deposits/withdrawals.
 * Computed by CashBookService; exports are audited, like every report.
 */
final class CashBookController extends Controller
{
    public function index(Request $request): void
    {
        [$month, $account] = $this->selection($request);

        $this->appView('cashier/reports/cash-book', [
            'title'     => 'Cash Book',
            'book'      => CashBookService::month($month, $account),
            'openings'  => CashMovementService::openings(),
            'recent'    => CashMovementService::recent(8),
            'canRecord' => Auth::can('disbursements.manage'),
        ]);
    }

    public function print(Request $request): void
    {
        [$month, $account] = $this->selection($request);

        $this->printView('print/cash-book', [
            'title' => 'Cash Book ' . $month . ' ' . ($account === 'BANK' ? 'Bank' : 'Cash'),
            'book'  => CashBookService::month($month, $account),
            'back'  => '/cashier/reports/cash-book?' . http_build_query(['month' => $month, 'account' => $account]),
        ]);
    }

    public function export(Request $request): void
    {
        [$month, $account] = $this->selection($request);
        $book = CashBookService::month($month, $account);

        AuditService::log('reports.exported', 'reports', null, null, null, [
            'report' => 'cash-book', 'month' => $month, 'account' => $account, 'rows' => count($book['inflows']) + count($book['outflows']),
        ], 'SUCCESS', null, Auth::user()->id, Auth::user()->role_name);

        $money = static fn (int $cents): string => FinanceService::money($cents);
        $rows = [['Inflow', $book['from'], 'Balance C/F', 'Balance carried forward', $money($book['carriedForward'])]];
        foreach ($book['inflows'] as $row) {
            $rows[] = ['Inflow', substr($row['at'], 0, 10), $row['ref'], $row['particulars'], $money($row['amount'])];
        }
        foreach ($book['outflows'] as $row) {
            $rows[] = ['Outflow', substr($row['at'], 0, 10), $row['ref'], $row['particulars'], $money($row['amount'])];
        }
        $rows[] = ['Total inflow', '', '', '', $money($book['totalInflow'])];
        $rows[] = ['Total outflow', '', '', '', $money($book['totalOutflow'])];
        $rows[] = ['Closing balance', '', '', '', $money($book['closing'])];
        if ($book['counted'] !== null) {
            $rows[] = ['Counted at day close', '', '', '', $money($book['counted']['counted'])];
            $rows[] = ['Over/short', '', '', '', $money($book['counted']['difference'])];
        }

        CsvExport::send("cash-book-{$month}-" . strtolower($account) . '.csv', ['Side', 'Date', 'Reference', 'Transaction details', 'Amount'], $rows);
    }

    /** A deposit to, or a withdrawal from, the bank. */
    public function movement(Request $request): void
    {
        $back = '/cashier/reports/cash-book?' . http_build_query(['account' => (string) $request->input('return_account', 'CASH')]);
        try {
            $movement = CashMovementService::record(Auth::user(), $request->all());
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), $back . '#record');
            return;
        }

        $this->success("Recorded {$movement['movement_number']}: " . ($movement['movement_type'] === CashMovementService::DEPOSIT ? 'deposit to the bank' : 'withdrawal from the bank') . '.', $back);
    }

    /** The opening balance of Cash on Hand or of the bank. */
    public function opening(Request $request): void
    {
        $back = '/cashier/reports/cash-book?' . http_build_query(['account' => (string) $request->input('account', 'CASH')]);
        // The page has two amount fields; this form's is called opening_amount so each shows only its own error.
        $input = $request->all();
        $input['amount'] = $input['opening_amount'] ?? '';
        try {
            CashMovementService::setOpening(Auth::user(), $input);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            if (isset($errors['amount'])) {
                $errors['opening_amount'] = $errors['amount'];
                unset($errors['amount']);
            }
            $this->failWith(ValidationException::withErrors($errors, $e->getMessage()), $request->all(), $back . '#opening');
            return;
        }

        $this->success('The opening balance was saved.', $back);
    }

    /** @return array{0:string,1:string} month (Y-m) and account (CASH|BANK) */
    private function selection(Request $request): array
    {
        return [CashBookService::normalizeMonth($request->input('month')), CashBookService::normalizeAccount($request->input('account'))];
    }
}
