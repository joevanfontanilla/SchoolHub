<?php

declare(strict_types=1);

namespace App\Controllers\Cashier;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Models\Announcement;
use App\Models\Finance;
use App\Models\FinanceReport;
use App\Services\DayCloseService;
use App\Services\FinanceService;

/**
 * Cashier dashboard (docs/14): payments to verify and today's collections.
 */
final class DashboardController extends Controller
{
    public function index(Request $request): void
    {
        $user = Auth::user();
        $stats = Finance::cashierStats();
        $totals = FinanceReport::dailyTotals(7);
        $today = date('Y-m-d');
        $summary = DayCloseService::summary($today);
        $closed = DayCloseService::isClosed($today);
        $dueAccounts = [];
        foreach (FinanceReport::accountList(null, null, null, true)['groups'] as $group) {
            foreach ($group['rows'] as $account) {
                $dueAccounts[] = $account;
            }
        }
        usort($dueAccounts, static fn (array $a, array $b): int => $b['due_now'] <=> $a['due_now']);

        $this->appView('dashboards/cashier', [
            'title'         => 'Dashboard',
            'announcements' => Announcement::forUser($user, 5),
            'pendingQueue'  => array_slice(Finance::submissionQueue('PENDING'), 0, 6),
            'recent'        => Finance::recentPayments(),
            'summary'       => $summary,
            'closed'        => $closed,
            'dueAccounts'   => array_slice($dueAccounts, 0, 6),
            'dueCount'      => count($dueAccounts),
            'week'          => array_map(static fn (string $day, int $cents): array => [
                'label'    => date('D', strtotime($day)),
                'sublabel' => date('M j', strtotime($day)),
                'value'    => max(0, $cents) / 100,
                'display'  => ReportController::short($cents),
            ], array_keys($totals), $totals),
            'weekTotal'     => array_sum($totals),
            'cards'         => [
                ['icon' => 'inbox', 'label' => 'Payments to verify', 'value' => (string) $stats['pending'], 'hint' => $stats['pending'] === 0 ? 'Queue is clear' : 'Submitted online by students', 'tone' => $stats['pending'] > 0 ? 'warning' : 'neutral', 'href' => '/cashier/payments'],
                ['icon' => 'banknotes', 'label' => "Today's collections", 'value' => money($stats['collected_today']), 'hint' => $stats['payments_today'] . ' payment' . ($stats['payments_today'] === 1 ? '' : 's') . ' today', 'tone' => 'success', 'href' => '/cashier/reports/collections?from=' . date('Y-m-d') . '&to=' . date('Y-m-d')],
                ['icon' => 'credit-card', 'label' => 'Outstanding balances', 'value' => money($stats['outstanding']), 'hint' => 'Across active assessments', 'tone' => 'neutral', 'href' => '/cashier/reports/outstanding'],
                ['icon' => 'alert', 'label' => 'Installments due', 'value' => (string) count($dueAccounts), 'hint' => $dueAccounts === [] ? 'Nobody is behind' : 'Accounts with an unpaid exam period', 'tone' => $dueAccounts === [] ? 'neutral' : 'warning', 'href' => '/cashier/reports/account-list?due=1'],
                ['icon' => 'calendar-check', 'label' => 'Day close', 'value' => $closed ? 'Closed' : 'Open', 'hint' => $closed ? 'Today is signed off' : 'Expected cash ' . money(FinanceService::money($summary['expectedCash'])), 'tone' => $closed ? 'success' : 'neutral', 'href' => '/cashier/day-close'],
                ['icon' => 'document', 'label' => 'Active assessments', 'value' => (string) $stats['assessments'], 'hint' => 'Issued at approval', 'tone' => 'neutral', 'href' => '/cashier/accounts'],
            ],
        ]);
    }
}
