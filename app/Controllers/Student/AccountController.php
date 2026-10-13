<?php

declare(strict_types=1);

namespace App\Controllers\Student;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Core\UploadedFile;
use App\Core\ValidationException;
use App\Models\Finance;
use App\Models\SystemSetting;
use App\Services\FinanceService;
use App\Services\PaymentPolicy;
use App\Services\RegistrationService;

/**
 * Payments & Accounts: the student's own assessments, online payments, and
 * receipts (docs/08, docs/14). Only the signed-in student's records.
 */
final class AccountController extends Controller
{
    public function index(Request $request): void
    {
        $student = RegistrationService::ensureStudentRecord(Auth::user());
        $assessments = Finance::assessmentsForStudent((int) $student['id']);

        $wanted = (int) $request->input('assessment', 0);
        $assessment = $assessments[0] ?? null;
        foreach ($assessments as $candidate) {
            if ((int) $candidate['id'] === $wanted) {
                $assessment = $candidate;
            }
        }

        $this->appView('student/account', [
            'title'        => 'Payments & Accounts',
            'assessments'  => $assessments,
            'assessment'   => $assessment,
            'items'        => $assessment === null ? [] : Finance::items((int) $assessment['id']),
            'payments'     => $assessment === null ? [] : Finance::payments((int) $assessment['id']),
            'adjustments'  => $assessment === null ? [] : Finance::adjustments((int) $assessment['id']),
            'plan'         => $assessment === null ? null : Finance::installmentPlan($assessment),
            'submissions'  => $assessment === null ? [] : Finance::submissions((int) $assessment['id']),
            'required'     => $assessment === null ? 0 : PaymentPolicy::requiredCents($assessment),
            'policy'       => PaymentPolicy::current(),
            'instructions' => (string) SystemSetting::get('payment_instructions', ''),
            'methods'      => FinanceService::METHODS_ONLINE,
        ]);
    }

    public function submitPayment(Request $request): void
    {
        $assessmentId = (int) $request->param('id');
        $back = '/student/account?assessment=' . $assessmentId . '#pay';

        try {
            FinanceService::submitPayment(Auth::user(), $assessmentId, $request->all(), UploadedFile::fromRequest($_FILES['proof'] ?? []));
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), $back);
            return;
        }

        $this->success('Payment sent to the cashier for verification. You will be notified once it is checked, and your receipt will appear here.', '/student/account?assessment=' . $assessmentId);
    }
}
