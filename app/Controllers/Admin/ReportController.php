<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Models\AdminReport;
use App\Models\User;
use App\Services\AuditService;
use App\Services\CsvExport;
use App\Services\FinanceService;
use App\Services\ReportRange;
use App\Services\SystemHealth;

/**
 * Administrative reports for the Super Admin (docs/04, docs/19, docs/20): the account
 * review, the sign-in and activity summaries, and system usage. Each is on screen,
 * printable, and a CSV export; exports are audited.
 */
final class ReportController extends Controller
{
    /** Rows shown per page on screen; print and CSV always include every row. */
    private const PER_PAGE = 100;

    private const ROLES = [User::ROLE_STUDENT, User::ROLE_TEACHER, User::ROLE_REGISTRAR, User::ROLE_CASHIER, User::ROLE_SUPER_ADMIN];

    public function index(Request $request): void
    {
        Response::redirect('/admin/reports/accounts');
    }

    // Account review --------------------------------------------------------------

    public function accounts(Request $request): void
    {
        $filters = $this->accountFilters($request);
        $report = AdminReport::accounts($filters['role'], $filters['status'], $filters['flagged']);
        $pages = max(1, (int) ceil(count($report['rows']) / self::PER_PAGE));
        $page = min($pages, max(1, (int) $request->input('page', 1)));

        $this->appView('admin/reports/accounts', [
            'title'   => 'Account Review',
            'filters' => $filters,
            'roles'   => self::ROLES,
            'report'  => $report,
            'rows'    => array_slice($report['rows'], ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'page'    => $page,
            'pages'   => $pages,
        ]);
    }

    public function accountsPrint(Request $request): void
    {
        $filters = $this->accountFilters($request);
        $this->printView('print/admin-accounts', [
            'title'  => 'Account Review',
            'scope'  => implode(' · ', array_filter([$filters['role'] ?? 'All roles', $filters['status'] ?? 'all statuses', $filters['flagged'] ? 'flagged only' : null])),
            'report' => AdminReport::accounts($filters['role'], $filters['status'], $filters['flagged']),
            'back'   => '/admin/reports/accounts?' . http_build_query(array_filter($filters)),
        ]);
    }

    public function accountsExport(Request $request): void
    {
        $filters = $this->accountFilters($request);
        $report = AdminReport::accounts($filters['role'], $filters['status'], $filters['flagged']);
        $this->audit('accounts', array_filter($filters), count($report['rows']));

        CsvExport::send('account-review-' . date('Y-m-d') . '.csv',
            ['Username', 'Last name', 'First name', 'Email', 'Role', 'Status', 'Last sign-in', 'Created', 'Failed attempts', 'Review'],
            array_map(static fn (array $row): array => [
                $row['username'], $row['last_name'], $row['first_name'], $row['email'], $row['role_name'], $row['status'],
                $row['last_login_at'] ?? '', $row['created_at'], (int) $row['failed_login_attempts'],
                implode('; ', array_map(static fn (string $flag) => AdminReport::FLAG_LABELS[$flag], $row['flags'])),
            ], $report['rows'])
        );
    }

    // Sign-in summary -------------------------------------------------------------

    public function signIns(Request $request): void
    {
        [$from, $to, $error] = $this->range($request);
        $report = AdminReport::signIns($from, $to);
        $this->appView('admin/reports/sign-ins', [
            'title'  => 'Sign-in Summary',
            'from'   => $from,
            'to'     => $to,
            'error'  => $error,
            'report' => $report,
            'chart'  => $this->dailyChart($report['days'], 'failed'),
        ]);
    }

    public function signInsPrint(Request $request): void
    {
        [$from, $to] = $this->range($request);
        $this->printView('print/admin-sign-ins', [
            'title'  => 'Sign-in Summary',
            'from'   => $from,
            'to'     => $to,
            'report' => AdminReport::signIns($from, $to),
            'back'   => '/admin/reports/sign-ins?' . http_build_query(['from' => $from, 'to' => $to]),
        ]);
    }

    public function signInsExport(Request $request): void
    {
        [$from, $to] = $this->range($request);
        $report = AdminReport::signIns($from, $to);
        $this->audit('sign-ins', ['from' => $from, 'to' => $to], count($report['days']));

        CsvExport::send("sign-ins-{$from}-to-{$to}.csv",
            ['Date', 'Successful', 'Failed'],
            array_map(static fn (array $day): array => [$day['day'], $day['success'], $day['failed']], $report['days'])
        );
    }

    // Activity summary ------------------------------------------------------------

    public function activity(Request $request): void
    {
        [$from, $to, $error] = $this->range($request);
        $report = AdminReport::activity($from, $to);
        $this->appView('admin/reports/activity', [
            'title'  => 'Activity Summary',
            'from'   => $from,
            'to'     => $to,
            'error'  => $error,
            'report' => $report,
            'chart'  => $this->dailyChart($report['days'], 'entries'),
        ]);
    }

    public function activityPrint(Request $request): void
    {
        [$from, $to] = $this->range($request);
        $this->printView('print/admin-activity', [
            'title'  => 'Activity Summary',
            'from'   => $from,
            'to'     => $to,
            'report' => AdminReport::activity($from, $to),
            'back'   => '/admin/reports/activity?' . http_build_query(['from' => $from, 'to' => $to]),
        ]);
    }

    public function activityExport(Request $request): void
    {
        [$from, $to] = $this->range($request);
        $report = AdminReport::activity($from, $to);
        $this->audit('activity', ['from' => $from, 'to' => $to], count($report['actions']));

        // Counts per module, then per role and per action, in one sheet with a "Grouping" column.
        $lines = [];
        foreach ($report['modules'] as $row) {
            $lines[] = ['Module', $row['module'], (int) $row['entries'], (int) $row['failures']];
        }
        foreach ($report['roles'] as $row) {
            $lines[] = ['Role', $row['actor_role'], (int) $row['entries'], (int) $row['failures']];
        }
        foreach ($report['actions'] as $row) {
            $lines[] = ['Action (top 15)', $row['action'], (int) $row['entries'], (int) $row['failures']];
        }
        CsvExport::send("activity-{$from}-to-{$to}.csv", ['Grouping', 'Name', 'Entries', 'Failures'], $lines);
    }

    // System usage ----------------------------------------------------------------

    public function usage(Request $request): void
    {
        $this->appView('admin/reports/usage', ['title' => 'System Usage', 'report' => AdminReport::usage()]);
    }

    public function usagePrint(Request $request): void
    {
        $this->printView('print/admin-usage', ['title' => 'System Usage', 'report' => AdminReport::usage(), 'back' => '/admin/reports/usage']);
    }

    public function usageExport(Request $request): void
    {
        $report = AdminReport::usage();
        $lines = [];
        foreach ($report['roles'] as $role => $count) {
            $lines[] = ['Accounts', $role, $count];
        }
        foreach ($report['terms'] as $term) {
            $label = $term['name'] . ' ' . $term['academic_year_code'];
            $lines[] = ['Term', $label . ': applications', (int) $term['applications']];
            $lines[] = ['Term', $label . ': enrolled', (int) $term['enrolled']];
            $lines[] = ['Term', $label . ': classes', (int) $term['classes']];
            $lines[] = ['Term', $label . ': collected (PHP)', FinanceService::money($term['collected_cents'])];
        }
        foreach ($report['communication'] as $name => $count) {
            $lines[] = ['Communication', $name, $count];
        }
        foreach ($report['records'] as $name => $count) {
            $lines[] = ['Records', $name, $count];
        }
        foreach ($report['storage'] as $folder => $usage) {
            $lines[] = ['Storage', "storage/{$folder} files", $usage['files']];
            $lines[] = ['Storage', "storage/{$folder} bytes", $usage['bytes']];
        }
        $this->audit('usage', [], count($lines));
        CsvExport::send('system-usage-' . date('Y-m-d') . '.csv', ['Area', 'Measure', 'Value'], $lines);
    }

    // Helpers ---------------------------------------------------------------------

    /** @return array{role:?string,status:?string,flagged:bool} */
    private function accountFilters(Request $request): array
    {
        $role = (string) $request->input('role', '');
        $status = (string) $request->input('status', '');
        return [
            'role'    => in_array($role, self::ROLES, true) ? $role : null,
            'status'  => in_array($status, AdminReport::STATUSES, true) ? $status : null,
            'flagged' => (string) $request->input('flagged', '') === '1',
        ];
    }

    /** @return array{0:string,1:string,2:?string} the last 30 days unless another range is asked for */
    private function range(Request $request): array
    {
        return ReportRange::parse($request->input('from'), $request->input('to'), date('Y-m-d', strtotime('-29 days')), date('Y-m-d'), 'the last 30 days');
    }

    /**
     * One bar per day for up to 31 days, otherwise per month.
     *
     * @param array<int,array<string,mixed>> $days
     * @return array<int,array{label:string,value:float,display:string,sublabel:string}>
     */
    private function dailyChart(array $days, string $key): array
    {
        $daily = count($days) <= 31;
        $buckets = [];
        foreach ($days as $day) {
            $time = strtotime($day['day']);
            $bucket = $daily ? $day['day'] : date('Y-m', $time);
            $buckets[$bucket] ??= ['label' => $daily ? date('M j', $time) : date('M Y', $time), 'sublabel' => $daily ? date('D', $time) : '', 'value' => 0];
            $buckets[$bucket]['value'] += (int) $day[$key];
        }
        return array_values(array_map(static fn (array $b) => $b + ['display' => number_format($b['value'])], $buckets));
    }

    private function audit(string $report, array $filters, int $rows): void
    {
        AuditService::log('reports.exported', 'reports', null, null, null,
            ['report' => 'admin.' . $report, 'filters' => $filters, 'rows' => $rows],
            'SUCCESS', null, Auth::user()->id, Auth::user()->role_name);
    }
}
