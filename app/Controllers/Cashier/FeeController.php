<?php

declare(strict_types=1);

namespace App\Controllers\Cashier;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\ValidationException;
use App\Models\Finance;
use App\Services\FeeScheduleService;

/**
 * The fee schedule (docs/14).
 */
final class FeeController extends Controller
{
    public function index(Request $request): void
    {
        $this->appView('cashier/fees/index', [
            'title' => 'Fee Schedule',
            'fees'  => Finance::feeTypes(),
            'bases' => FeeScheduleService::BASES,
        ]);
    }

    public function create(Request $request): void
    {
        $this->appView('cashier/fees/form', ['title' => 'New fee', 'fee' => null, 'bases' => FeeScheduleService::BASES]);
    }

    public function edit(Request $request): void
    {
        $fee = null;
        foreach (Finance::feeTypes() as $row) {
            if ((int) $row['id'] === (int) $request->param('id')) {
                $fee = $row;
            }
        }
        if ($fee === null) {
            throw HttpException::notFound('That fee was not found.');
        }
        $this->appView('cashier/fees/form', ['title' => 'Edit ' . $fee['name'], 'fee' => $fee, 'bases' => FeeScheduleService::BASES]);
    }

    public function save(Request $request): void
    {
        $id = $request->param('id') !== null ? (int) $request->param('id') : null;
        $back = $id === null ? '/cashier/fees/create' : "/cashier/fees/{$id}/edit";

        try {
            FeeScheduleService::save(Auth::user(), $id, $request->all());
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), $back);
            return;
        }
        $this->success($id === null ? 'Fee added. It applies to assessments issued from now on.' : 'Fee saved. Assessments already issued keep their amounts.', '/cashier/fees');
    }
}
