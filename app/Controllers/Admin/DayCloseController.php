<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Core\ValidationException;
use App\Services\DayCloseService;

/**
 * The days the cashier has closed, and reopening one (docs/14, Daily operations at the window).
 * Only the Super Admin can reopen a day; the cashier cannot reopen their own close.
 */
final class DayCloseController extends Controller
{
    public function index(Request $request): void
    {
        $this->appView('admin/day-closes/index', [
            'title'   => 'Closed Days',
            'history' => DayCloseService::history(60),
        ]);
    }

    public function reopen(Request $request): void
    {
        try {
            DayCloseService::reopen(Auth::user(), (int) $request->param('id'), (string) $request->input('reason', ''));
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), '/admin/day-closes');
            return;
        }

        $this->success('The day was reopened. The cashier can record again and close it with a new count.', '/admin/day-closes');
    }
}
