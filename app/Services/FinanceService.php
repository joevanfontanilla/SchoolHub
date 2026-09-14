<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\UploadedFile;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\User;

/**
 * Assessments and payments (docs/14, docs/26 section 3).
 *
 * - An assessment is generated once, when the registrar approves an application,
 *   from the subjects the student chose and the fee schedule at that moment.
 * - A student pays online by submitting the amount, reference number, and proof;
 *   the cashier approves (a payment and an official receipt are created) or
 *   rejects it with a reason. The cashier can also record a payment made at the
 *   window.
 * - total_paid, balance, and the application's payment_status are written ONLY
 *   by recalculate(), inside the same transaction as the change that caused it.
 * - When a payment brings the student past the enrollment payment policy, the
 *   student is enrolled automatically (EnrollmentConfirmationService::confirmIfReady).
 * - Payments are never edited or deleted.
 */
final class FinanceService
{
    public const METHODS_ONLINE = ['GCash', 'Maya', 'Bank deposit', 'Bank transfer', 'Other'];
    public const METHODS_CASHIER = ['Cash', 'GCash', 'Maya', 'Bank deposit', 'Bank transfer', 'Check', 'Other'];
    private const PROOF_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];
    private const PROOF_MAX_BYTES = 5 * 1024 * 1024;

    // -- Assessment --------------------------------------------------------------

    /**
     * The fee lines for a set of subjects under the current fee schedule.
     *
     * @param array<int,array<string,mixed>> $subjects rows with units and lab_units
     * @return array{items:array<int,array<string,mixed>>,total:string}
     */
    public static function computeFees(array $subjects): array
    {
        $units = array_sum(array_map(static fn ($s) => (float) $s['units'], $subjects));
        $labUnits = array_sum(array_map(static fn ($s) => (float) $s['lab_units'], $subjects));

        $items = [];
        $totalCents = 0;
        foreach (Database::select('SELECT * FROM fee_types WHERE is_active = 1 ORDER BY sort_order, id') as $fee) {
            $quantity = match ($fee['basis']) {
                'PER_UNIT'     => $units,
                'PER_LAB_UNIT' => $labUnits,
                default        => 1.0,
            };
            if ($quantity <= 0) {
                continue; // no lab units, no laboratory fee line
            }
            // Money in whole centavos, never floating point.
            // Units have at most one decimal place: (tenths × centavos + 5) ÷ 10 rounds to the centavo.
            $cents = intdiv((int) round($quantity * 10) * self::cents($fee['amount']) + 5, 10);
            $totalCents += $cents;

            $items[] = [
                'fee_type_id' => (int) $fee['id'],
                'description' => $fee['name'],
                'basis'       => $fee['basis'],
                'quantity'    => number_format($quantity, 2, '.', ''),
                'unit_amount' => $fee['amount'],
                'amount'      => self::money($cents),
                'sort_order'  => (int) $fee['sort_order'],
            ];
        }

        return ['items' => $items, 'total' => self::money($totalCents)];
    }

    /**
     * Generate the assessment of an approved application. Called from the
     * approval transition, inside its transaction.
     *
     * @param array<string,mixed> $application
     */
    public static function assess(array $application, User $actor): int
    {
        $applicationId = (int) $application['id'];
        $existing = Database::scalar('SELECT id FROM fee_assessments WHERE application_id = ?', [$applicationId]);
        if ($existing !== null) {
            return (int) $existing;
        }

        $fees = self::computeFees(SubjectSelectionService::chosen($applicationId));

        $assessmentId = Database::insert('fee_assessments', [
            'assessment_number' => SequenceService::next('assessment', 'ASM'),
            'application_id'    => $applicationId,
            'student_id'        => (int) $application['student_id'],
            'term_id'           => (int) $application['term_id'],
            'total_amount'      => $fees['total'],
            'total_paid'        => '0.00',
            'balance'           => $fees['total'],
            'status'            => 'ACTIVE',
            'assessed_by'       => $actor->id,
            'assessed_at'       => date('Y-m-d H:i:s'),
        ]);
        foreach ($fees['items'] as $item) {
            Database::insert('fee_items', $item + ['fee_assessment_id' => $assessmentId]);
        }

        self::recalculate($assessmentId);

        AuditService::log('finance.assessed', 'finance', 'FeeAssessment', $assessmentId, null, [
            'application_id' => $applicationId, 'total' => $fees['total'], 'items' => count($fees['items']),
        ], 'SUCCESS', null, $actor->id, $actor->role_name);

        return $assessmentId;
    }

    /**
     * Recompute an assessment's totals and its application's payment_status —
     * the single writer of both (docs/26 section 3).
     */
    public static function recalculate(int $assessmentId): void
    {
        $assessment = Database::selectOne('SELECT * FROM fee_assessments WHERE id = ? FOR UPDATE', [$assessmentId]);
        $paidCents = self::cents((string) Database::scalar('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE fee_assessment_id = ?', [$assessmentId]));
        $totalCents = self::cents($assessment['total_amount']);

        $status = match (true) {
            $assessment['status'] !== 'ACTIVE' => 'NOT_ASSESSED',
            $paidCents <= 0                     => 'PENDING',
            $paidCents < $totalCents            => 'PARTIAL',
            default                             => 'PAID',
        };

        Database::update('fee_assessments', [
            'total_paid' => self::money($paidCents),
            'balance'    => self::money(max(0, $totalCents - $paidCents)),
        ], ['id' => $assessmentId]);
        Database::update('enrollment_applications', ['payment_status' => $status], ['id' => $assessment['application_id']]);
    }

    // -- Online payment (student) -----------------------------------------------------

    /**
     * The student submits an online payment with its proof, for the cashier.
     *
     * @param array<string,mixed> $input amount, method, reference_number, paid_on
     * @return int the submission id
     */
    public static function submitPayment(User $student, int $assessmentId, array $input, ?UploadedFile $proof): int
    {
        $assessment = self::ownedAssessment($student, $assessmentId);

        $validator = Validator::make($input, [
            'amount'           => 'required|numeric',
            'method'           => 'required|in:' . implode(',', self::METHODS_ONLINE),
            'reference_number' => 'required|max:100',
            'paid_on'          => 'required|date',
        ], ['reference_number' => 'Reference number', 'paid_on' => 'Date paid']);

        $cents = self::cents((string) ($input['amount'] ?? '0'));
        $pending = self::cents((string) Database::scalar(
            "SELECT COALESCE(SUM(amount), 0) FROM payment_submissions WHERE fee_assessment_id = ? AND status = 'PENDING'",
            [$assessmentId]
        ));
        $open = self::cents($assessment['balance']) - $pending;

        if (!preg_match('/^\d+(\.\d{1,2})?$/', trim((string) ($input['amount'] ?? ''))) || $cents <= 0) {
            $validator->addError('amount', 'Enter the amount you paid, in pesos, with at most two decimal places.');
        } elseif ($cents > $open) {
            $validator->addError('amount', $open <= 0
                ? 'Nothing is left to pay once your pending payments are verified.'
                : 'That is more than your remaining balance of ' . money(self::money($open)) . ' (after payments still being verified).');
        }
        $paidOn = trim((string) ($input['paid_on'] ?? ''));
        if ($paidOn !== '' && ($paidOn > date('Y-m-d') || $paidOn < date('Y-m-d', strtotime('-60 days')))) {
            $validator->addError('paid_on', 'The date paid must be within the last 60 days, and not in the future.');
        }
        if ($assessment['status'] !== 'ACTIVE') {
            throw ValidationException::withMessage('This assessment is no longer active.');
        }
        if ($validator->fails()) {
            throw ValidationException::withErrors($validator->errors());
        }

        $extension = DocumentUploadService::assertGenuineFile($proof, self::PROOF_EXTENSIONS, self::PROOF_MAX_BYTES, 'your proof of payment');

        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
        $relativePath = 'uploads/payments/' . $assessmentId . '/' . $storedName;
        $fullPath = STORAGE_PATH . '/' . $relativePath;
        $meta = [
            'mime'     => $proof->detectedMimeType(),
            'size'     => $proof->size(),
            'checksum' => $proof->sha256(),
            'name'     => FileStreamer::sanitizeFilename($proof->clientName()),
        ];
        $proof->moveTo($fullPath);

        try {
            return Database::transaction(static function () use ($student, $assessment, $input, $cents, $storedName, $relativePath, $meta): int {
                $id = Database::insert('payment_submissions', [
                    'fee_assessment_id' => (int) $assessment['id'],
                    'student_id'        => (int) $assessment['student_id'],
                    'amount'            => self::money($cents),
                    'method'            => (string) $input['method'],
                    'reference_number'  => trim((string) $input['reference_number']),
                    'paid_on'           => (string) $input['paid_on'],
                    'original_filename' => $meta['name'],
                    'stored_filename'   => $storedName,
                    'storage_path'      => $relativePath,
                    'mime_type'         => $meta['mime'],
                    'file_size'         => $meta['size'],
                    'checksum'          => $meta['checksum'],
                    'status'            => 'PENDING',
                    'submitted_at'      => date('Y-m-d H:i:s'),
                ]);

                AuditService::log('finance.payment_submitted', 'finance', 'PaymentSubmission', $id, null, [
                    'assessment' => $assessment['assessment_number'], 'amount' => self::money($cents), 'method' => (string) $input['method'],
                ], 'SUCCESS', null, $student->id, $student->role_name);

                NotificationService::notifyRole(User::ROLE_CASHIER, 'finance.payment_submitted',
                    'Online payment of ' . money(self::money($cents)) . ' to verify', $assessment['assessment_number'], "/cashier/payments/{$id}");

                return $id;
            });
        } catch (\Throwable $e) {
            @unlink($fullPath);
            throw $e;
        }
    }

    // -- Cashier ------------------------------------------------------------------------

    /**
     * Approve an online payment: record the payment and its official receipt,
     * update the balance, and enroll the student if the payment policy is now met.
     *
     * @return array{payment_id:int,receipt_number:string,enrolled:bool}
     */
    public static function approveSubmission(User $cashier, int $submissionId, ?string $remarks = null): array
    {
        self::assertCashier($cashier);

        return Database::transaction(static function () use ($cashier, $submissionId, $remarks): array {
            $submission = Database::selectOne('SELECT * FROM payment_submissions WHERE id = ? FOR UPDATE', [$submissionId]);
            if ($submission === null) {
                throw HttpException::notFound('That payment was not found.');
            }
            if ($submission['status'] !== 'PENDING') {
                throw ValidationException::withMessage('This payment was already ' . strtolower($submission['status']) . '.');
            }

            $payment = self::createPayment($cashier, (int) $submission['fee_assessment_id'], $submission['amount'], $submission['method'],
                $submission['reference_number'], $submission['paid_on'] . ' ' . date('H:i:s'), $submissionId, $remarks);

            Database::update('payment_submissions', [
                'status' => 'APPROVED', 'reviewed_by' => $cashier->id, 'reviewed_at' => date('Y-m-d H:i:s'),
                'review_remarks' => trim((string) $remarks) ?: null,
            ], ['id' => $submissionId]);

            return $payment;
        });
    }

    /** Reject an online payment; the student is told why. */
    public static function rejectSubmission(User $cashier, int $submissionId, string $remarks): void
    {
        self::assertCashier($cashier);
        $remarks = trim($remarks);
        if ($remarks === '') {
            throw ValidationException::withErrors(['review_remarks' => ['Tell the student why the payment could not be verified.']], 'A reason is required to reject a payment.');
        }

        Database::transaction(static function () use ($cashier, $submissionId, $remarks): void {
            $submission = Database::selectOne(
                'SELECT ps.*, s.user_id FROM payment_submissions ps JOIN students s ON s.id = ps.student_id WHERE ps.id = ? FOR UPDATE',
                [$submissionId]
            );
            if ($submission === null) {
                throw HttpException::notFound('That payment was not found.');
            }
            if ($submission['status'] !== 'PENDING') {
                throw ValidationException::withMessage('This payment was already ' . strtolower($submission['status']) . '.');
            }

            Database::update('payment_submissions', [
                'status' => 'REJECTED', 'reviewed_by' => $cashier->id, 'reviewed_at' => date('Y-m-d H:i:s'), 'review_remarks' => $remarks,
            ], ['id' => $submissionId]);

            AuditService::log('finance.payment_rejected', 'finance', 'PaymentSubmission', $submissionId, ['status' => 'PENDING'],
                ['status' => 'REJECTED', 'amount' => $submission['amount']], 'SUCCESS', $remarks, $cashier->id, $cashier->role_name);

            NotificationService::notify((int) $submission['user_id'], 'finance.payment_rejected',
                'Your payment of ' . money($submission['amount']) . ' could not be verified', $remarks, '/student/account');
        });
    }

    /**
     * A payment made at the cashier's window.
     *
     * @param array<string,mixed> $input amount, payment_method, reference_number, remarks
     * @return array{payment_id:int,receipt_number:string,enrolled:bool}
     */
    public static function recordPayment(User $cashier, int $assessmentId, array $input): array
    {
        self::assertCashier($cashier);

        $validator = Validator::make($input, [
            'amount'           => 'required|numeric',
            'payment_method'   => 'required|in:' . implode(',', self::METHODS_CASHIER),
            'reference_number' => 'nullable|max:100',
            'remarks'          => 'nullable|max:500',
        ], ['payment_method' => 'Payment method']);
        if (!preg_match('/^\d+(\.\d{1,2})?$/', trim((string) ($input['amount'] ?? ''))) || self::cents((string) $input['amount']) <= 0) {
            $validator->addError('amount', 'Enter the amount received, in pesos, with at most two decimal places.');
        }
        if ($validator->fails()) {
            throw ValidationException::withErrors($validator->errors());
        }

        return Database::transaction(static fn (): array => self::createPayment(
            $cashier, $assessmentId, self::money(self::cents((string) $input['amount'])), (string) $input['payment_method'],
            trim((string) ($input['reference_number'] ?? '')) ?: null, date('Y-m-d H:i:s'), null, $input['remarks'] ?? null
        ));
    }

    // -- Shared ---------------------------------------------------------------------------

    /**
     * The assessment, if it belongs to this student (otherwise 404).
     *
     * @return array<string,mixed>
     */
    public static function ownedAssessment(User $student, int $assessmentId): array
    {
        $studentRow = RegistrationService::ensureStudentRecord($student);
        $assessment = Database::selectOne('SELECT * FROM fee_assessments WHERE id = ? AND student_id = ?', [$assessmentId, $studentRow['id']]);
        if ($assessment === null) {
            throw HttpException::notFound('That assessment was not found.');
        }
        return $assessment;
    }

    /** @return array{payment_id:int,receipt_number:string,enrolled:bool} */
    private static function createPayment(User $cashier, int $assessmentId, string $amount, string $method, ?string $reference, string $paidAt, ?int $submissionId, ?string $remarks): array
    {
        $assessment = Database::selectOne(
            'SELECT fa.*, s.user_id FROM fee_assessments fa JOIN students s ON s.id = fa.student_id WHERE fa.id = ? FOR UPDATE',
            [$assessmentId]
        );
        if ($assessment === null) {
            throw HttpException::notFound('That assessment was not found.');
        }
        if ($assessment['status'] !== 'ACTIVE') {
            throw ValidationException::withMessage('This assessment is no longer active.');
        }
        if (self::cents($amount) > self::cents($assessment['balance'])) {
            throw ValidationException::withErrors(
                ['amount' => ['That is more than the remaining balance of ' . money($assessment['balance']) . '.']],
                'The payment is more than the remaining balance of ' . money($assessment['balance']) . '.'
            );
        }

        $receipt = SequenceService::next('receipt', 'OR');
        $paymentId = Database::insert('payments', [
            'transaction_number'    => SequenceService::next('payment', 'PAY'),
            'receipt_number'        => $receipt,
            'fee_assessment_id'     => $assessmentId,
            'student_id'            => (int) $assessment['student_id'],
            'amount'                => $amount,
            'payment_method'        => $method,
            'reference_number'      => $reference,
            'paid_at'               => $paidAt,
            'cashier_user_id'       => $cashier->id,
            'payment_submission_id' => $submissionId,
            'remarks'               => trim((string) $remarks) ?: null,
        ]);

        self::recalculate($assessmentId);

        AuditService::log('finance.payment_recorded', 'finance', 'Payment', $paymentId, ['balance' => $assessment['balance']], [
            'receipt' => $receipt, 'amount' => $amount, 'method' => $method, 'assessment' => $assessment['assessment_number'],
        ], 'SUCCESS', null, $cashier->id, $cashier->role_name);

        NotificationService::notify((int) $assessment['user_id'], 'finance.payment_recorded',
            'Payment of ' . money($amount) . " received (receipt {$receipt})", null, "/receipts/{$paymentId}");

        $enrolled = EnrollmentConfirmationService::confirmIfReady((int) $assessment['application_id'], $cashier) !== null;

        return ['payment_id' => $paymentId, 'receipt_number' => $receipt, 'enrolled' => $enrolled];
    }

    private static function assertCashier(User $user): void
    {
        if (!$user->isCashier()) {
            throw HttpException::forbidden('Only the cashier can verify and record payments.');
        }
    }

    /** "1,234.50" or "1234.5" -> 123450 */
    public static function cents(string|int|float $amount): int
    {
        $text = str_replace(',', '', trim((string) $amount));
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?/', $text, $m)) {
            return 0;
        }
        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    /** 123450 -> "1234.50" */
    public static function money(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
