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
 * Student accounts: assessments, balances, and payments made at the window (docs/14).
 */
final class AccountController extends Controller
{
    public function index(Request $request): void
    {
        $q = trim((string) $request->input('q', ''));
        $balance = (string) $request->input('balance', '');
        $this->appView('cashier/accounts/index', [
            'title'    => 'Student Accounts',
            'q'        => $q,
            'balance'  => $balance,
            'accounts' => Finance::accounts($q, $balance),
        ]);
    }

    public function show(Request $request): void
    {
        $assessment = $this->assessment($request);
        $id = (int) $assessment['id'];

        $this->appView('cashier/accounts/show', [
            'title'       => 'Account ' . $assessment['assessment_number'],
            'assessment'  => $assessment,
            'items'       => Finance::items($id),
            'payments'    => Finance::payments($id),
            'submissions' => Finance::submissions($id),
            'required'    => PaymentPolicy::requiredCents($assessment),
            'policy'      => PaymentPolicy::current(),
            'methods'     => FinanceService::METHODS_CASHIER,
        ]);
    }

    public function recordPayment(Request $request): void
    {
        $assessment = $this->assessment($request);
        $id = (int) $assessment['id'];

        try {
            $result = FinanceService::recordPayment(Auth::user(), $id, $request->all());
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), "/cashier/accounts/{$id}#record");
            return;
        }

        $message = "Payment recorded. Official receipt {$result['receipt_number']} was issued.";
        if ($result['enrolled']) {
            $message .= ' The student is now officially enrolled.';
        }
        $this->success($message, "/cashier/accounts/{$id}");
    }

    /** @return array<string,mixed> */
    private function assessment(Request $request): array
    {
        $assessment = Finance::assessment((int) $request->param('id'));
        if ($assessment === null) {
            throw HttpException::notFound('That account was not found.');
        }
        return $assessment;
    }
}
