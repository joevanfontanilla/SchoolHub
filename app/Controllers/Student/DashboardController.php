<?php

declare(strict_types=1);

namespace App\Controllers\Student;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Models\Announcement;

/**
 * Student dashboard (docs/08).
 *
 * Enrollment, requirements, schedule, grades, and fees arrive in Phases 2-5;
 * this is the shell those modules will fill.
 */
final class DashboardController extends Controller
{
    public function index(Request $request): void
    {
        $user = Auth::user();

        $this->appView('dashboards/student', [
            'title'         => 'Dashboard',
            'announcements' => Announcement::forUser($user, 5),
            'cards'         => [
                ['label' => 'Enrollment status', 'value' => 'No application', 'hint' => 'Apply when enrollment opens', 'tone' => 'neutral'],
                ['label' => 'Academic year',     'value' => '—',              'hint' => 'Set after enrollment',        'tone' => 'neutral'],
                ['label' => 'Outstanding balance', 'value' => money(0),       'hint' => 'No assessment yet',           'tone' => 'neutral'],
                ['label' => 'Unread notifications', 'value' => '0',           'hint' => 'You are up to date',          'tone' => 'neutral'],
            ],
        ]);
    }
}
