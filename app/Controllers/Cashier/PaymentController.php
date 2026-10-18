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
 * Verifying students' online payments (docs/14).
 */
final class PaymentController extends Controller
{
    public function index(Request $request): void
    {
        $status = strtoupper((string) $request->input('status', 'PENDING'));
        $this->appView('cashier/payments/index', [
            'title'       => 'Online Payments',
            'status'      => in_array($status, ['PENDING', 'APPROVED', 'REJECTED'], true) ? $status : 'PENDING',
            'submissions' => Finance::submissionQueue($status),
            'pending'     => Finance::cashierStats()['pending'],
        ]);
    }

    public function show(Request $request): void
    {
        $submission = $this->submission($request);
        $assessment = Finance::assessment((int) $submission['fee_assessment_id']);

        $this->appView('cashier/payments/show', [
            'title'      => 'Verify payment',
            'submission' => $submission,
            'assessment' => $assessment,
            'payments'   => Finance::payments((int) $assessment['id']),
            'required'   => PaymentPolicy::requiredCents($assessment),
            'policy'     => PaymentPolicy::current(),
            'bankAccount' => $submission['bank_account_id'] !== null ? \App\Services\BankAccountService::find((int) $submission['bank_account_id']) : null,
        ]);
    }

    public function approve(Request $request): void
    {
        $submission = $this->submission($request);
        $id = (int) $submission['id'];

        try {
            $result = FinanceService::approveSubmission(Auth::user(), $id, (string) $request->input('review_remarks', ''));
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), "/cashier/payments/{$id}");
            return;
        }

        $message = "Payment verified. Official receipt {$result['receipt_number']} was issued.";
        if ($result['enrolled']) {
            $message .= ' The student has met the payment policy and is now officially enrolled.';
        }
        $this->success($message, "/cashier/payments/{$id}");
    }

    public function reject(Request $request): void
    {
        $submission = $this->submission($request);
        $id = (int) $submission['id'];

        try {
            FinanceService::rejectSubmission(Auth::user(), $id, (string) $request->input('review_remarks', ''));
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), "/cashier/payments/{$id}");
            return;
        }
        $this->success('Payment rejected. The student was told why.', '/cashier/payments');
    }

    /** @return array<string,mixed> */
    private function submission(Request $request): array
    {
        $submission = Finance::submission((int) $request->param('id'));
        if ($submission === null) {
            throw HttpException::notFound('That payment was not found.');
        }
        return $submission;
    }
}
