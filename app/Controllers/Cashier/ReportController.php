<?php

declare(strict_types=1);

namespace App\Controllers\Cashier;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Catalog;
use App\Models\FinanceReport;
use App\Models\Program;
use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\CsvExport;
use App\Services\FinanceService;

/**
 * Cashier reports (docs/15): collections over a date range and outstanding
 * balances, each on screen, printable, and as a CSV export. Exports are audited
 * because they take student financial data out of the system.
 */
final class ReportController extends Controller
{
    /** Longest range a collections report covers, so a typo can't scan years of payments. */
    private const MAX_DAYS = 366;

    /** Rows shown per page on screen; print and CSV always include every row. */
    private const PER_PAGE = 100;

    public function index(Request $request): void
    {
        Response::redirect('/cashier/reports/collections');
    }

    public function collections(Request $request): void
    {
        [$from, $to, $error] = $this->range($request);
        $report = FinanceReport::collections($from, $to);
        $pages = max(1, (int) ceil(count($report['rows']) / self::PER_PAGE));
        $page = min($pages, max(1, (int) $request->input('page', 1)));

        $this->appView('cashier/reports/collections', [
            'title'  => 'Collections Report',
            'from'   => $from,
            'to'     => $to,
            'error'  => $error,
            'report' => $report,
            'rows'   => array_slice($report['rows'], ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'page'   => $page,
            'pages'  => $pages,
            'chart'  => $this->chart($report['by_day'], $from, $to),
            'daily'  => self::daily($from, $to),
        ]);
    }

    public function collectionsPrint(Request $request): void
    {
        [$from, $to] = $this->range($request);
        $this->printView('print/collections', [
            'title'  => 'Collections Report ' . $from . ' to ' . $to,
            'from'   => $from,
            'to'     => $to,
            'report' => FinanceReport::collections($from, $to),
            'back'   => '/cashier/reports/collections?' . http_build_query(['from' => $from, 'to' => $to]),
        ]);
    }

    public function collectionsExport(Request $request): void
    {
        [$from, $to] = $this->range($request);
        $report = FinanceReport::collections($from, $to);

        AuditService::log('reports.exported', 'reports', null, null, null, [
            'report' => 'collections', 'from' => $from, 'to' => $to, 'rows' => count($report['rows']),
        ], 'SUCCESS', null, Auth::user()->id, Auth::user()->role_name);

        CsvExport::send("collections-{$from}-to-{$to}.csv",
            ['Recorded', 'Transaction No.', 'Receipt No.', 'Type', 'Student No.', 'Student', 'Assessment', 'Method', 'Reference', 'Amount', 'Cashier', 'Remarks'],
            array_map(static fn (array $row): array => [
                $row['created_at'],
                $row['transaction_number'],
                $row['receipt_number'] ?? '',
                $row['reversed_payment_id'] !== null ? 'Reversal of ' . $row['reversed_receipt_number'] : 'Payment',
                $row['student_number'] ?? '',
                $row['last_name'] . ', ' . $row['first_name'],
                $row['assessment_number'],
                $row['payment_method'],
                $row['reference_number'] ?? '',
                FinanceService::signedMoney(FinanceService::signedCents($row['amount'])),
                $row['cashier_name'],
                $row['remarks'] ?? '',
            ], $report['rows'])
        );
    }

    public function outstanding(Request $request): void
    {
        [$termId, $programId] = $this->filters($request);
        $report = FinanceReport::outstanding($termId, $programId);
        $pages = max(1, (int) ceil(count($report['rows']) / self::PER_PAGE));
        $page = min($pages, max(1, (int) $request->input('page', 1)));
        $this->appView('cashier/reports/outstanding', [
            'title'     => 'Outstanding Balances',
            'termId'    => $termId,
            'programId' => $programId,
            'terms'     => Catalog::allTerms(),
            'programs'  => Program::active(),
            'report'    => $report,
            'rows'      => array_slice($report['rows'], ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'page'      => $page,
            'pages'     => $pages,
        ]);
    }

    public function outstandingPrint(Request $request): void
    {
        [$termId, $programId] = $this->filters($request);
        $this->printView('print/outstanding', [
            'title'  => 'Outstanding Balances',
            'scope'  => $this->scopeLabel($termId, $programId),
            'report' => FinanceReport::outstanding($termId, $programId),
            'back'   => '/cashier/reports/outstanding?' . http_build_query(array_filter(['term_id' => $termId, 'program_id' => $programId])),
        ]);
    }

    public function outstandingExport(Request $request): void
    {
        [$termId, $programId] = $this->filters($request);
        $report = FinanceReport::outstanding($termId, $programId);

        AuditService::log('reports.exported', 'reports', null, null, null, [
            'report' => 'outstanding', 'term_id' => $termId, 'program_id' => $programId, 'rows' => count($report['rows']),
        ], 'SUCCESS', null, Auth::user()->id, Auth::user()->role_name);

        CsvExport::send('outstanding-balances-' . date('Y-m-d') . '.csv',
            ['Assessment', 'Student No.', 'Student', 'Email', 'Program', 'Year level', 'Section', 'Term', 'Amount due', 'Paid', 'Balance', 'Payment status', 'Enrollment', 'Last payment'],
            array_map(static fn (array $row): array => [
                $row['assessment_number'],
                $row['student_number'] ?? '',
                $row['last_name'] . ', ' . $row['first_name'],
                $row['email'],
                $row['program_code'],
                $row['year_level_name'],
                $row['section_code'] ?? '',
                $row['term_name'] . ' ' . $row['academic_year_code'],
                FinanceService::money(max(0, FinanceService::dueCents($row))),
                $row['total_paid'],
                $row['balance'],
                $row['payment_status'],
                $row['enrollment_state'],
                $row['last_payment_at'] ?? '',
            ], $report['rows'])
        );
    }

    /**
     * The requested date range, defaulting to this month so far. A bad or reversed
     * range falls back to the default with a message rather than an error page.
     *
     * @return array{0:string,1:string,2:?string}
     */
    private function range(Request $request): array
    {
        $defaultFrom = date('Y-m-01');
        $defaultTo = date('Y-m-d');
        $from = (string) $request->input('from', $defaultFrom);
        $to = (string) $request->input('to', $defaultTo);

        $valid = static fn (string $d): bool => ($parsed = \DateTime::createFromFormat('!Y-m-d', $d)) !== false && $parsed->format('Y-m-d') === $d;
        if (!$valid($from) || !$valid($to)) {
            return [$defaultFrom, $defaultTo, 'Enter dates as YYYY-MM-DD. Showing this month instead.'];
        }
        if ($from > $to) {
            return [$defaultFrom, $defaultTo, 'The start date is after the end date. Showing this month instead.'];
        }
        if ((new \DateTime($from))->diff(new \DateTime($to))->days >= self::MAX_DAYS) {
            return [$defaultFrom, $defaultTo, 'A report can cover at most one year. Showing this month instead.'];
        }
        return [$from, $to, null];
    }

    /** @return array{0:?int,1:?int} */
    private function filters(Request $request): array
    {
        $term = (int) $request->input('term_id', 0);
        $program = (int) $request->input('program_id', 0);
        return [$term > 0 ? $term : null, $program > 0 ? $program : null];
    }

    private function scopeLabel(?int $termId, ?int $programId): string
    {
        $parts = [];
        foreach (Catalog::allTerms() as $term) {
            if ((int) $term['id'] === $termId) {
                $parts[] = $term['name'] . ', A.Y. ' . $term['academic_year_code'];
            }
        }
        foreach (Program::active() as $program) {
            if ((int) $program['id'] === $programId) {
                $parts[] = $program['code'];
            }
        }
        return $parts === [] ? 'All terms and programs' : implode(' · ', $parts);
    }

    /**
     * Net collections for the column chart: one bar per day for up to 31 days,
     * otherwise one per month. Days with nothing collected still get a bar.
     *
     * @param array<string,array{count:int,cents:int}> $byDay
     * @return array<int,array{label:string,value:float,display:string,sublabel?:string}>
     */
    private function chart(array $byDay, string $from, string $to): array
    {
        $daily = self::daily($from, $to);
        $buckets = [];
        for ($day = new \DateTime($from); $day->format('Y-m-d') <= $to; $day->modify('+1 day')) {
            $key = $daily ? $day->format('Y-m-d') : $day->format('Y-m');
            $buckets[$key] ??= ['label' => $daily ? $day->format('M j') : $day->format('M Y'), 'cents' => 0, 'sublabel' => $daily ? $day->format('D') : ''];
            $buckets[$key]['cents'] += $byDay[$day->format('Y-m-d')]['cents'] ?? 0;
        }

        return array_values(array_map(static fn (array $b): array => [
            'label'    => $b['label'],
            'sublabel' => $b['sublabel'],
            'value'    => max(0, $b['cents']) / 100,
            'display'  => self::short($b['cents']),
        ], $buckets));
    }

    private static function daily(string $from, string $to): bool
    {
        return (new \DateTime($from))->diff(new \DateTime($to))->days < 31;
    }

    /** ₱12.5k style labels that fit above a bar. */
    public static function short(int $cents): string
    {
        $pesos = $cents / 100;
        $sign = $pesos < 0 ? '−' : '';
        $pesos = abs($pesos);
        return $sign . '₱' . match (true) {
            $pesos >= 1_000_000 => rtrim(rtrim(number_format($pesos / 1_000_000, 1), '0'), '.') . 'M',
            $pesos >= 1_000     => rtrim(rtrim(number_format($pesos / 1_000, 1), '0'), '.') . 'k',
            default             => number_format($pesos, 0),
        };
    }

}
