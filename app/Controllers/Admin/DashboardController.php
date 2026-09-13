<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Models\Announcement;

/**
 * Super Admin dashboard (docs/20). User management, audit viewer, and backups
 * arrive in Phase 7 — the counts here are real.
 */
final class DashboardController extends Controller
{
    public function index(Request $request): void
    {
        $user = Auth::user();

        $activeUsers = (int) Database::scalar("SELECT COUNT(*) FROM users WHERE status = 'Active'");
        $lockedUsers = (int) Database::scalar("SELECT COUNT(*) FROM users WHERE status = 'Locked'");
        $auditToday  = (int) Database::scalar('SELECT COUNT(*) FROM audit_logs WHERE DATE(created_at) = CURDATE()');
        $failedToday = (int) Database::scalar(
            "SELECT COUNT(*) FROM login_logs WHERE result = 'FAILED' AND DATE(attempted_at) = CURDATE()"
        );

        $this->appView('dashboards/admin', [
            'title'         => 'Dashboard',
            'announcements' => Announcement::forUser($user, 5),
            'cards'         => [
                ['label' => 'Active users',        'value' => (string) $activeUsers, 'hint' => 'Able to sign in',        'tone' => 'neutral'],
                ['label' => 'Locked accounts',     'value' => (string) $lockedUsers, 'hint' => $lockedUsers > 0 ? 'Needs attention' : 'None locked', 'tone' => $lockedUsers > 0 ? 'warning' : 'neutral'],
                ['label' => 'Audit entries today', 'value' => (string) $auditToday,  'hint' => 'Recorded activity',      'tone' => 'neutral'],
                ['label' => 'Failed sign-ins today', 'value' => (string) $failedToday, 'hint' => $failedToday > 0 ? 'Review the login log' : 'None', 'tone' => $failedToday > 5 ? 'warning' : 'neutral'],
            ],
            'health' => [
                'PHP version'         => PHP_VERSION,
                'Database'            => Database::scalar('SELECT VERSION()'),
                'Application version' => '1.0.0 (Phase 1)',
                'Storage writable'    => is_writable(STORAGE_PATH) ? 'Yes' : 'No',
            ],
        ]);
    }
}
