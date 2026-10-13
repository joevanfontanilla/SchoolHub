<?php

declare(strict_types=1);

namespace App\Controllers\Cashier;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\ValidationException;
use App\Services\DayCloseService;

/**
 * Closing the cashier's day (docs/14, Daily operations at the window): count the drawer, compare
 * it with what the records expect, sign off, and print the turnover slip.
 */
final class DayCloseController extends Controller
{
    public function index(Request $request): void
    {
        $date = $this->date($request);

        $this->appView('cashier/day-close/index', [
            'title'   => 'Close the Day',
            'date'    => $date,
            'summary' => DayCloseService::summary($date),
            'close'   => DayCloseService::find($date),
            'history' => DayCloseService::history(10),
        ]);
    }

    public function close(Request $request): void
    {
        $date = (string) $request->input('date', date('Y-m-d'));

        try {
            DayCloseService::close(Auth::user(), $date, (string) $request->input('counted_cash', ''), (string) $request->input('explanation', ''));
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), '/cashier/day-close?date=' . urlencode($date));
            return;
        }

        $this->success('The day was closed. Print the turnover slip for your records.', '/cashier/day-close?date=' . urlencode($date));
    }

    public function print(Request $request): void
    {
        $date = $this->date($request);
        $close = DayCloseService::find($date);
        if ($close === null || $close['status'] !== 'CLOSED') {
            throw HttpException::notFound('That day has not been closed yet.');
        }

        $this->printView('print/day-close', [
            'title'   => 'Turnover Slip ' . $date,
            'close'   => $close,
            'summary' => DayCloseService::summary($date),
            'back'    => '/cashier/day-close?date=' . $date,
        ]);
    }

    private function date(Request $request): string
    {
        $date = (string) $request->input('date', '');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && strtotime($date) !== false && $date <= date('Y-m-d') ? $date : date('Y-m-d');
    }
}
