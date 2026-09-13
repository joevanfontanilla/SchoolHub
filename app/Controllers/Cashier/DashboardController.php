<?php

declare(strict_types=1);

namespace App\Controllers\Cashier;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Models\Announcement;

/**
 * Cashier dashboard (docs/14). Fees, payments, and receipts arrive in Phase 5.
 */
final class DashboardController extends Controller
{
    public function index(Request $request): void
    {
        $user = Auth::user();

        $this->appView('dashboards/cashier', [
            'title'         => 'Dashboard',
            'announcements' => Announcement::forUser($user, 5),
            'cards'         => [
                ['label' => "Today's collections", 'value' => money(0), 'hint' => 'No payments recorded', 'tone' => 'neutral'],
                ['label' => 'Payments today',      'value' => '0',      'hint' => 'No transactions',      'tone' => 'neutral'],
                ['label' => 'Outstanding balances', 'value' => money(0), 'hint' => 'No assessments yet',  'tone' => 'neutral'],
                ['label' => 'Assessments issued',  'value' => '0',      'hint' => 'None this term',       'tone' => 'neutral'],
            ],
        ]);
    }
}
