<?php

declare(strict_types=1);

namespace App\Controllers\Cashier;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\ValidationException;
use App\Services\AuditService;
use App\Services\CsvExport;
use App\Services\FinanceService;
use App\Services\LedgerAccountService;
use App\Services\LedgerService;
use App\Services\ReportRange;

/**
 * The cashier's general ledger (docs/14): a trial balance for a date range, the ledger of each
 * account, and the chart of accounts. Computed from the books by LedgerService; read-only apart from
 * the chart of accounts. Exports are audited, like every report.
 */
final class LedgerController extends Controller
{
    // -- Trial balance ------------------------------------------------------------

    public function index(Request $request): void
    {
        [$from, $to, $error] = $this->range($request);
        $this->appView('cashier/ledger/index', [
            'title'   => 'General Ledger',
            'from'    => $from,
            'to'      => $to,
            'error'   => $error,
            'report'  => LedgerService::trialBalance($from, $to),
        ]);
    }

    public function print(Request $request): void
    {
        [$from, $to] = $this->range($request);
        $this->printView('print/ledger-trial-balance', [
            'title'  => 'Trial Balance ' . $from . ' to ' . $to,
            'from'   => $from,
            'to'     => $to,
            'report' => LedgerService::trialBalance($from, $to),
            'back'   => '/cashier/ledger?' . http_build_query(['from' => $from, 'to' => $to]),
        ]);
    }

    public function export(Request $request): void
    {
        [$from, $to] = $this->range($request);
        $report = LedgerService::trialBalance($from, $to);

        AuditService::log('reports.exported', 'reports', null, null, null, [
            'report' => 'trial-balance', 'from' => $from, 'to' => $to, 'rows' => count($report['rows']),
        ], 'SUCCESS', null, Auth::user()->id, Auth::user()->role_name);

        $money = static fn (int $cents): string => $cents === 0 ? '' : FinanceService::money($cents);
        CsvExport::send("trial-balance-{$from}-to-{$to}.csv",
            ['Code', 'Account', 'Type', 'Opening debit', 'Opening credit', 'Debits', 'Credits', 'Closing debit', 'Closing credit'],
            array_map(static fn (array $row): array => [
                $row['code'], $row['name'], LedgerAccountService::TYPES[$row['type']] ?? $row['type'],
                $money(max(0, $row['opening'])), $money(max(0, -$row['opening'])),
                $money($row['debit']), $money($row['credit']),
                $money(max(0, $row['closing'])), $money(max(0, -$row['closing'])),
            ], $report['rows'])
        );
    }

    // -- One account ----------------------------------------------------------------

    public function account(Request $request): void
    {
        [$from, $to, $error] = $this->range($request);
        $this->appView('cashier/ledger/account', [
            'title'  => 'Account ledger',
            'from'   => $from,
            'to'     => $to,
            'error'  => $error,
            'ledger' => $this->ledger($request, $from, $to),
        ]);
    }

    public function accountPrint(Request $request): void
    {
        [$from, $to] = $this->range($request);
        $ledger = $this->ledger($request, $from, $to);
        $this->printView('print/ledger-account', [
            'title'  => 'Ledger ' . $ledger['account']['code'],
            'from'   => $from,
            'to'     => $to,
            'ledger' => $ledger,
            'back'   => '/cashier/ledger/accounts/' . $ledger['account']['id'] . '?' . http_build_query(['from' => $from, 'to' => $to]),
        ]);
    }

    public function accountExport(Request $request): void
    {
        [$from, $to] = $this->range($request);
        $ledger = $this->ledger($request, $from, $to);

        AuditService::log('reports.exported', 'reports', 'LedgerAccount', (int) $ledger['account']['id'], null, [
            'report' => 'account-ledger', 'account' => $ledger['account']['code'], 'from' => $from, 'to' => $to, 'rows' => count($ledger['rows']),
        ], 'SUCCESS', null, Auth::user()->id, Auth::user()->role_name);

        $money = static fn (int $cents): string => $cents === 0 ? '' : FinanceService::money($cents);
        $balance = static fn (int $net): string => FinanceService::money(abs($net)) . ' ' . LedgerService::side($net);
        $rows = [['', '', 'Opening balance', '', '', $balance($ledger['opening'])]];
        foreach ($ledger['rows'] as $row) {
            $rows[] = [substr($row['at'], 0, 10), $row['ref'], $row['particulars'], $money($row['debit']), $money($row['credit']), $balance($row['balance'])];
        }
        $rows[] = ['', '', 'Closing balance', $money($ledger['debit']), $money($ledger['credit']), $balance($ledger['closing'])];

        CsvExport::send("ledger-{$ledger['account']['code']}-{$from}-to-{$to}.csv", ['Date', 'Ref', 'Particulars', 'Debit', 'Credit', 'Balance'], $rows);
    }

    // -- The chart of accounts --------------------------------------------------------

    public function chart(Request $request): void
    {
        $this->appView('cashier/ledger/chart', [
            'title'    => 'Chart of Accounts',
            'accounts' => LedgerAccountService::all(),
            'types'    => LedgerAccountService::TYPES,
        ]);
    }

    public function addAccount(Request $request): void
    {
        try {
            LedgerAccountService::addExpenseAccount(Auth::user(), $request->all());
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), '/cashier/ledger/chart#add');
            return;
        }
        $this->success('Expense account added. It can now be chosen on a disbursement voucher.', '/cashier/ledger/chart');
    }

    public function toggle(Request $request): void
    {
        $id = (int) $request->param('id');
        $account = LedgerAccountService::find($id) ?? throw HttpException::notFound('That account was not found.');
        try {
            LedgerAccountService::setActive(Auth::user(), $id, (int) $account['is_active'] !== 1);
        } catch (ValidationException $e) {
            $this->failWith($e, [], '/cashier/ledger/chart');
            return;
        }
        $this->success($account['name'] . ((int) $account['is_active'] === 1 ? ' was switched off.' : ' was switched on.'), '/cashier/ledger/chart');
    }

    // -- Helpers ------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function ledger(Request $request, string $from, string $to): array
    {
        return LedgerService::accountLedger((int) $request->param('id'), $from, $to)
            ?? throw HttpException::notFound('That account was not found.');
    }

    /**
     * The date range: this year to date by default. A bad or reversed range falls back to it with a
     * message. Unlike the collections reports there is no one-year limit: a ledger spans years.
     *
     * @return array{0:string,1:string,2:?string} from, to, message
     */
    private function range(Request $request): array
    {
        $defaultFrom = date('Y-01-01');
        $defaultTo = date('Y-m-d');
        $from = $request->input('from');
        $to = $request->input('to');
        $from = is_string($from) && $from !== '' ? $from : $defaultFrom;
        $to = is_string($to) && $to !== '' ? $to : $defaultTo;

        if (!ReportRange::isDate($from) || !ReportRange::isDate($to)) {
            return [$defaultFrom, $defaultTo, 'Enter dates as YYYY-MM-DD. Showing this year to date instead.'];
        }
        if ($from > $to) {
            return [$defaultFrom, $defaultTo, 'The start date is after the end date. Showing this year to date instead.'];
        }

        return [$from, $to, null];
    }
}
