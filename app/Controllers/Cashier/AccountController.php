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
use App\Services\PaymentBreakdownService;
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
        $total = Finance::accountCount($q, $balance);
        $pages = max(1, (int) ceil($total / 50));
        $page = min($pages, max(1, (int) $request->input('page', 1)));
        $this->appView('cashier/accounts/index', [
            'title'    => 'Student Accounts',
            'q'        => $q,
            'balance'  => $balance,
            'accounts' => Finance::accounts($q, $balance, 50, ($page - 1) * 50),
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
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
            'adjustments' => Finance::adjustments($id),
            'submissions' => Finance::submissions($id),
            'belowPolicy' => Finance::belowPolicy($assessment),
            'plan'        => Finance::installmentPlan($assessment),
            'split'       => PaymentBreakdownService::forAssessment($id),
            'adjustmentTypes' => FinanceService::ADJUSTMENT_TYPES,
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

    /**
     * A discount, scholarship, waiver, charge, or correction. The form's fields are
     * prefixed so they don't collide with the payment form's old input and errors.
     */
    public function adjust(Request $request): void
    {
        $assessment = $this->assessment($request);
        $id = (int) $assessment['id'];
        $input = [
            'type'      => $request->input('adjustment_type'),
            'amount'    => $request->input('adjustment_amount'),
            'percent'   => $request->input('adjustment_percent'),
            'direction' => $request->input('adjustment_direction'),
            'reason'    => $request->input('adjustment_reason'),
        ];

        try {
            FinanceService::adjust(Auth::user(), $id, $input);
        } catch (ValidationException $e) {
            $errors = [];
            foreach ($e->errors() as $field => $messages) {
                $errors['adjustment_' . $field] = $messages;
            }
            $this->failWith(ValidationException::withErrors($errors, $e->getMessage()), $request->all(), "/cashier/accounts/{$id}#adjust");
            return;
        }

        $updated = Finance::assessment($id);
        $message = 'Adjustment applied. The amount due is now ' . money(FinanceService::money(FinanceService::dueCents($updated))) . '.';
        if ($assessment['enrollment_state'] !== 'ENROLLED' && $updated['enrollment_state'] === 'ENROLLED') {
            $message .= ' The student is now officially enrolled.';
        }
        $this->success($message, "/cashier/accounts/{$id}");
    }

    public function reverse(Request $request): void
    {
        $assessment = $this->assessment($request);
        $id = (int) $assessment['id'];
        $payment = Finance::payment((int) $request->param('payment'));
        if ($payment === null || (int) $payment['fee_assessment_id'] !== $id) {
            throw HttpException::notFound('That payment was not found on this account.');
        }

        try {
            FinanceService::reversePayment(Auth::user(), (int) $payment['id'], (string) $request->input('reason', ''));
        } catch (ValidationException $e) {
            $this->failWith($e, [], "/cashier/accounts/{$id}#payment-{$payment['id']}");
            return;
        }

        $message = "Receipt {$payment['receipt_number']} was reversed. The balance is now " . money(Finance::assessment($id)['balance']) . '.';
        if (Finance::belowPolicy(Finance::assessment($id))) {
            $message .= ' The student stays enrolled but is below the payment policy; the registrar was notified.';
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
