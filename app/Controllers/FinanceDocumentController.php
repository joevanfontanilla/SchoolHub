<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Finance;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AcademicRecordService;
use App\Services\AuditService;
use App\Services\FileStreamer;
use App\Services\RegistrationService;

/**
 * Printable finance documents and payment proofs (docs/15): official receipts,
 * statements of account, and proof files.
 *
 * Who may open one:
 *   - the student it belongs to (another student's is a 404);
 *   - the cashier, and the registrar for receipts and statements;
 *   - nobody else (403).
 * Printouts are HTML with print styles; the browser saves them as PDF.
 */
final class FinanceDocumentController extends Controller
{
    public function receipt(Request $request): void
    {
        $payment = Finance::payment((int) $request->param('id'));
        $this->authorize(isset($payment['user_id']) ? (int) $payment['user_id'] : null, $payment !== null, 'receipt', [User::ROLE_CASHIER, User::ROLE_REGISTRAR]);

        $this->printView('print/receipt', [
            'title'        => 'Official Receipt ' . $payment['receipt_number'],
            'payment'      => $payment,
            'balanceAfter' => Finance::balanceAfter($payment),
            'back'         => $this->accountBack((int) $payment['fee_assessment_id']),
        ]);
    }

    public function statement(Request $request): void
    {
        $assessment = Finance::assessment((int) $request->param('id'));
        $this->authorize(isset($assessment['user_id']) ? (int) $assessment['user_id'] : null, $assessment !== null, 'statement of account', [User::ROLE_CASHIER, User::ROLE_REGISTRAR]);

        $this->printView('print/statement', [
            'title'      => 'Statement of Account ' . $assessment['assessment_number'],
            'assessment' => $assessment,
            'items'      => Finance::items((int) $assessment['id']),
            'payments'   => Finance::payments((int) $assessment['id']),
            'subjects'   => \App\Services\SubjectSelectionService::chosen((int) $assessment['application_id']),
            'back'       => $this->accountBack((int) $assessment['id']),
        ]);
    }

    /** The signed-in student's class schedule, ready to print (docs/08). */
    public function schedule(Request $request): void
    {
        $user = Auth::user();
        if ($user === null || !$user->isStudent()) {
            throw HttpException::forbidden('Only students can print their own schedule here.');
        }
        $student = RegistrationService::ensureStudentRecord($user);
        $enrollments = AcademicRecordService::enrollmentsOf((int) $student['id']);

        $enrollment = $enrollments[0] ?? null;
        foreach ($enrollments as $candidate) {
            if ((int) $candidate['term_id'] === (int) $request->input('term_id', 0)) {
                $enrollment = $candidate;
            }
        }
        if ($enrollment === null) {
            throw HttpException::notFound('You have no enrolled term to print a schedule for.');
        }

        $classes = array_values(array_filter(
            AcademicRecordService::classesForEnrollment((int) $enrollment['id']),
            static fn ($c) => $c['enlistment_status'] === 'ENLISTED'
        ));

        $this->printView('print/schedule', [
            'title'      => 'Class Schedule · ' . $enrollment['term_name'] . ' ' . $enrollment['academic_year_code'],
            'enrollment' => $enrollment,
            'student'    => $student + ['name' => $user->fullName()],
            'classes'    => $classes,
            'blocks'     => \App\Models\ClassSchedule::blocks($classes, true),
            'back'       => '/student/schedule?term_id=' . $enrollment['term_id'],
        ]);
    }

    public function proof(Request $request): void
    {
        $submission = Finance::submission((int) $request->param('id'));
        $this->authorize(isset($submission['owner_user_id']) ? (int) $submission['owner_user_id'] : null, $submission !== null, 'payment proof', [User::ROLE_CASHIER]);

        AuditService::log('finance.proof_viewed', 'finance', 'PaymentSubmission', (int) $submission['id']);
        FileStreamer::send($submission['storage_path'], $submission['original_filename'], $submission['mime_type']);
    }

    /** @param array<int,string> $staffRoles roles that may open any record of this kind */
    private function authorize(?int $ownerUserId, bool $exists, string $what, array $staffRoles): void
    {
        \App\Services\FinanceAccessPolicy::check(Auth::user(), $ownerUserId, $exists, $what, $staffRoles);
    }

    /** Back to the account page this document came from, for the viewer's role. */
    private function accountBack(int $assessmentId): string
    {
        return match (Auth::user()?->role_name) {
            User::ROLE_STUDENT   => '/student/account?assessment=' . $assessmentId,
            User::ROLE_CASHIER   => '/cashier/accounts/' . $assessmentId,
            default              => '/registrar/applications',
        };
    }

    /** @param array<string,mixed> $data */
    private function printView(string $template, array $data): void
    {
        $data += ['school' => SystemSetting::publicSettings(), 'user' => Auth::user()];
        Response::html(View::render($template, $data, 'layouts/print'));
    }
}
