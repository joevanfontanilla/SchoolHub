<?php

declare(strict_types=1);

namespace App\Controllers\Registrar;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Models\Announcement;
use App\Models\RegistrarQueue;

/**
 * Registrar dashboard (docs/11): what needs attention, and the queue itself.
 */
final class DashboardController extends Controller
{
    public function index(Request $request): void
    {
        $counts = RegistrarQueue::counts();
        $needsAction = $counts['submitted'] + $counts['under_review'];

        $this->appView('dashboards/registrar', [
            'title'         => 'Dashboard',
            'announcements' => Announcement::forUser(Auth::user(), 5),
            'queue'         => RegistrarQueue::applications(['status' => 'action'], 1)['rows'],
            'cards'         => [
                ['icon' => 'inbox', 'label' => 'New applications', 'value' => (string) $counts['submitted'], 'hint' => 'Not yet opened', 'tone' => $counts['submitted'] > 0 ? 'warning' : 'neutral'],
                ['icon' => 'clock', 'label' => 'Under review', 'value' => (string) $counts['under_review'], 'hint' => 'Review in progress', 'tone' => 'neutral'],
                ['icon' => 'send', 'label' => 'Returned to students', 'value' => (string) $counts['returned'], 'hint' => 'Waiting for resubmission', 'tone' => 'neutral'],
                ['icon' => 'calendar-check', 'label' => 'Awaiting confirmation', 'value' => (string) $counts['awaiting_confirmation'], 'hint' => 'Approved, not yet enrolled', 'tone' => $counts['awaiting_confirmation'] > 0 ? 'warning' : 'neutral'],
            ],
            'needsAction'   => $needsAction,
            'enrolled'      => $counts['enrolled'],
        ]);
    }
}
