<?php

declare(strict_types=1);

namespace App\Controllers\Registrar;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Models\Announcement;
use App\Models\Program;

/**
 * Registrar dashboard (docs/11). The application queue arrives in Phase 3.
 */
final class DashboardController extends Controller
{
    public function index(Request $request): void
    {
        $user = Auth::user();

        $this->appView('dashboards/registrar', [
            'title'         => 'Dashboard',
            'announcements' => Announcement::forUser($user, 5),
            'cards'         => [
                ['label' => 'New applications',     'value' => '0', 'hint' => 'Awaiting review',            'tone' => 'neutral'],
                ['label' => 'Pending review',       'value' => '0', 'hint' => 'In progress',                'tone' => 'neutral'],
                ['label' => 'Incomplete requirements', 'value' => '0', 'hint' => 'Returned to students',    'tone' => 'neutral'],
                ['label' => 'Active programs',      'value' => (string) Program::count(), 'hint' => 'Offered this year', 'tone' => 'neutral'],
            ],
        ]);
    }
}
