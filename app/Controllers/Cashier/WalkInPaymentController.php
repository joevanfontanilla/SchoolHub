<?php

declare(strict_types=1);

namespace App\Controllers\Cashier;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\ValidationException;
use App\Models\Finance;
use App\Services\FinanceService;
use App\Services\PaymentPolicy;

/**
 * Payments received in person at the cashier's window (docs/14, Walk-in payments).
 *
 * A thin workspace over FinanceService::recordPayment, the same call the account page makes,
 * so the receipt, balance, and enrollment effects are identical.
 */
final class WalkInPaymentController extends Controller
{
    private const RESULTS = 10;

    public function index(Request $request): void
    {
        $q = trim((string) $request->input('q', ''));
        $account = null;
        $suggested = '';
        $quick = [];

        $accountId = (int) $request->input('account', 0);
        if ($accountId > 0) {
            $account = Finance::assessment($accountId);
            if ($account === null || $account['status'] !== 'ACTIVE') {
                throw HttpException::notFound('That account was not found.');
            }
            // Before enrollment: what is still needed to meet the payment policy.
            if ($account['enrollment_state'] !== 'ENROLLED') {
                $needed = PaymentPolicy::requiredCents($account) - FinanceService::cents($account['total_paid']);
                $suggested = $needed > 0 ? FinanceService::money($needed) : '';
            }
            $quick = FinanceService::quickAmounts($account, $suggested, Finance::installmentPlan($account));
        }

        $paid = (int) $request->input('paid', 0);

        $this->appView('cashier/walk-in-payments/index', [
            'title'     => 'Walk-in Payments',
            'q'         => $q,
            'results'   => $q !== '' ? Finance::accounts($q, 'open', self::RESULTS) : [],
            'account'   => $account,
            'suggested' => $suggested,
            'quick'     => $quick,
            'methods'   => FinanceService::METHODS_CASHIER,
            'received'  => $paid > 0 ? Finance::payment($paid) : null,
            'today'     => Finance::counterPaymentsOn(date('Y-m-d')),
        ]);
    }

    public function store(Request $request): void
    {
        $id = (int) $request->param('id');
        $account = Finance::assessment($id);
        if ($account === null) {
            throw HttpException::notFound('That account was not found.');
        }

        try {
            $result = FinanceService::recordPayment(Auth::user(), $id, $request->all());
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), "/cashier/walk-in-payments?account={$id}");
            return;
        }

        $message = "Payment recorded. Official receipt {$result['receipt_number']} was issued.";
        if ($result['enrolled']) {
            $message .= ' The student is now officially enrolled.';
        }
        $this->success($message, '/cashier/walk-in-payments?paid=' . $result['payment_id']);
    }
}
