<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Request;
use App\Services\SystemHealth;

/** System health (docs/20). Read-only. */
final class HealthController extends Controller
{
    public function index(Request $request): void
    {
        $checks = SystemHealth::checks();
        $this->appView('admin/health/index', [
            'title'   => 'System Health',
            'checks'  => $checks,
            'overall' => SystemHealth::overall($checks),
            'errors'  => SystemHealth::recentErrors(30),
        ]);
    }
}
