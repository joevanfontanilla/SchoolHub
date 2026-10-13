<?php

declare(strict_types=1);

namespace App\Controllers\Cashier;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\ValidationException;
use App\Models\DisbursementVoucher;
use App\Services\DisbursementService;
use App\Services\LedgerAccountService;

/**
 * Cash disbursements (docs/14): the cashier records money paid out on a numbered voucher.
 * The Cash Disbursements Journal is a report under Cashier reports.
 */
final class DisbursementController extends Controller
{
    public function index(Request $request): void
    {
        $this->appView('cashier/disbursements/index', [
            'title'    => 'Disbursements',
            'vouchers' => DisbursementVoucher::recent(),
            'accounts' => LedgerAccountService::expenseAccounts(),
        ]);
    }

    public function create(Request $request): void
    {
        $this->appView('cashier/disbursements/create', [
            'title'   => 'Record a disbursement',
            'methods' => DisbursementService::METHODS,
            'accounts' => LedgerAccountService::expenseAccounts(),
        ]);
    }

    public function store(Request $request): void
    {
        try {
            $voucher = DisbursementService::record(Auth::user(), $request->all());
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), '/cashier/disbursements/create');
            return;
        }
        $this->success('Voucher ' . $voucher['voucher_number'] . ' recorded.', '/cashier/disbursements');
    }

    /** Give a voucher recorded before categories existed its expense account, once (docs/14). */
    public function categorize(Request $request): void
    {
        try {
            LedgerAccountService::categorizeVoucher(Auth::user(), (int) $request->param('id'), (int) $request->input('expense_account_id', 0));
        } catch (ValidationException $e) {
            $this->failWith($e, [], '/cashier/disbursements');
            return;
        }
        $this->success('The voucher was categorized.', '/cashier/disbursements');
    }

    public function voucher(Request $request): void
    {
        $voucher = DisbursementVoucher::find((int) $request->param('id'));
        if ($voucher === null) {
            throw HttpException::notFound('That voucher was not found.');
        }
        $this->printView('print/voucher', [
            'title'   => 'Disbursement Voucher ' . $voucher['voucher_number'],
            'voucher' => $voucher,
            'back'    => '/cashier/disbursements',
        ]);
    }
}
