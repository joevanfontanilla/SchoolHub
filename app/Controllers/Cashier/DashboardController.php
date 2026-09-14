<?php

declare(strict_types=1);

namespace App\Controllers\Cashier;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Models\Announcement;
use App\Models\Finance;

/**
 * Cashier dashboard (docs/14): payments to verify and today's collections.
 */
final class DashboardController extends Controller
{
    public function index(Request $request): void
    {
        $user = Auth::user();
        $stats = Finance::cashierStats();

        $this->appView('dashboards/cashier', [
            'title'         => 'Dashboard',
            'announcements' => Announcement::forUser($user, 5),
            'pendingQueue'  => array_slice(Finance::submissionQueue('PENDING'), 0, 6),
            'recent'        => Finance::recentPayments(),
            'cards'         => [
                ['icon' => 'inbox', 'label' => 'Payments to verify', 'value' => (string) $stats['pending'], 'hint' => $stats['pending'] === 0 ? 'Queue is clear' : 'Submitted online by students', 'tone' => $stats['pending'] > 0 ? 'warning' : 'neutral'],
                ['icon' => 'banknotes', 'label' => "Today's collections", 'value' => money($stats['collected_today']), 'hint' => $stats['payments_today'] . ' payment' . ($stats['payments_today'] === 1 ? '' : 's') . ' today', 'tone' => 'success'],
                ['icon' => 'credit-card', 'label' => 'Outstanding balances', 'value' => money($stats['outstanding']), 'hint' => 'Across active assessments', 'tone' => 'neutral'],
                ['icon' => 'document', 'label' => 'Active assessments', 'value' => (string) $stats['assessments'], 'hint' => 'Issued at approval', 'tone' => 'neutral'],
            ],
        ]);
    }
}
