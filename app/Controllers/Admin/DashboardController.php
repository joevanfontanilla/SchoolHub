<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\BackupService;
use App\Services\SystemHealth;

/**
 * Super Admin dashboard (docs/20): accounts, activity, backups, and health at a glance.
 */
final class DashboardController extends Controller
{
    public function index(Request $request): void
    {
        $user = Auth::user();
        $counts = User::statusCounts();
        $today = date('Y-m-d');
        $checks = SystemHealth::checks();
        $lastBackup = array_values(array_filter(BackupService::history(20), static fn ($b) => $b['status'] === 'COMPLETED'))[0] ?? null;
        $failedToday = AuditLog::loginAttemptCount(['from' => $today, 'to' => $today, 'result' => 'FAILED']);
        $locked = $counts[User::STATUS_LOCKED] ?? 0;

        $this->appView('dashboards/admin', [
            'title'         => 'Dashboard',
            'announcements' => Announcement::forUser($user, 5),
            'cards'         => [
                ['icon' => 'users', 'label' => 'Active users', 'value' => (string) ($counts[User::STATUS_ACTIVE] ?? 0), 'hint' => ($counts[User::STATUS_PENDING] ?? 0) . ' pending setup · ' . ($counts[User::STATUS_DISABLED] ?? 0) . ' disabled', 'tone' => 'neutral'],
                ['icon' => 'lock', 'label' => 'Locked accounts', 'value' => (string) $locked, 'hint' => $locked > 0 ? 'Needs attention' : 'None locked', 'tone' => $locked > 0 ? 'warning' : 'neutral'],
                ['icon' => 'shield', 'label' => 'Failed sign-ins today', 'value' => (string) $failedToday, 'hint' => $failedToday > 0 ? 'See sign-in activity' : 'None', 'tone' => $failedToday > 5 ? 'warning' : 'neutral'],
                ['icon' => 'database', 'label' => 'Latest backup', 'value' => $lastBackup ? d($lastBackup['completed_at']) : 'None', 'hint' => $lastBackup ? SystemHealth::bytes((int) $lastBackup['size_bytes']) : 'Make the first backup', 'tone' => $lastBackup ? 'neutral' : 'warning'],
            ],
            'checks'   => array_values(array_filter($checks, static fn ($c) => $c['status'] !== 'OK')),
            'overall'  => SystemHealth::overall($checks),
            'activity' => AuditLog::search([], 8),
        ]);
    }
}
