<?php

declare(strict_types=1);

namespace App\Controllers\Cashier;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\ValidationException;
use App\Models\Catalog;
use App\Models\Finance;
use App\Models\Program;
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
        $this->appView('cashier/fees/form', ['title' => 'New fee', 'fee' => null] + $this->formData());
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
        $this->appView('cashier/fees/form', ['title' => 'Edit ' . $fee['name'], 'fee' => $fee] + $this->formData());
    }

    /** @return array<string,mixed> */
    private function formData(): array
    {
        return [
            'bases'      => FeeScheduleService::BASES,
            'programs'   => Program::active(),
            'yearLevels' => Catalog::yearLevels(),
            // The fees a scoped line can replace: one entry per fee key, named after its general line.
            'keys'       => array_column(array_filter(Finance::feeTypes(), static fn (array $f) => $f['program_id'] === null && $f['year_level_id'] === null && (int) $f['is_active'] === 1), 'name', 'fee_key'),
        ];
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
