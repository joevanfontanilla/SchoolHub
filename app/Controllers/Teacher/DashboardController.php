<?php

declare(strict_types=1);

namespace App\Controllers\Teacher;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Models\Announcement;

/**
 * Teacher dashboard (docs/12). Classes, attendance, and grades arrive in Phase 4.
 */
final class DashboardController extends Controller
{
    public function index(Request $request): void
    {
        $user = Auth::user();

        $this->appView('dashboards/teacher', [
            'title'         => 'Dashboard',
            'announcements' => Announcement::forUser($user, 5),
            'cards'         => [
                ['label' => 'Assigned classes',  'value' => '0', 'hint' => 'Assigned by the registrar', 'tone' => 'neutral'],
                ['label' => "Today's schedule",  'value' => '0', 'hint' => 'No classes today',          'tone' => 'neutral'],
                ['label' => 'Attendance to record', 'value' => '0', 'hint' => 'Nothing pending',        'tone' => 'neutral'],
                ['label' => 'Grades to submit',  'value' => '0', 'hint' => 'No open grading period',    'tone' => 'neutral'],
            ],
        ]);
    }
}
