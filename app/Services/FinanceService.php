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
     * Lines are limited to the application's program and year level when the schedule says so:
     * for each fee key the most specific applicable line wins (docs/14).
     *
     * @param array<int,array<string,mixed>> $subjects rows with units and lab_units
     * @return array{items:array<int,array<string,mixed>>,total:string}
     */
    public static function computeFees(array $subjects, ?int $programId = null, ?int $yearLevelId = null): array
    {
        $units = array_sum(array_map(static fn ($s) => (float) $s['units'], $subjects));
        $labUnits = array_sum(array_map(static fn ($s) => (float) $s['lab_units'], $subjects));

        $items = [];
        $totalCents = 0;
        foreach (self::applicableFees($programId, $yearLevelId) as $fee) {
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
     * The fee lines that apply to a program and year level: per fee key, the most specific one
     * (program and year, then program, then year, then general); the lowest id breaks a tie.
     *
     * @return array<int,array<string,mixed>> ordered for the statement
     */
    public static function applicableFees(?int $programId, ?int $yearLevelId): array
    {
        $chosen = [];
        foreach (Database::select('SELECT * FROM fee_types WHERE is_active = 1 ORDER BY id') as $fee) {
            $forProgram = $fee['program_id'] !== null;
            $forYear = $fee['year_level_id'] !== null;
            if (($forProgram && (int) $fee['program_id'] !== (int) $programId) || ($forYear && (int) $fee['year_level_id'] !== (int) $yearLevelId)) {
                continue;
            }
            $score = ($forProgram ? 2 : 0) + ($forYear ? 1 : 0);
            if (!isset($chosen[$fee['fee_key']]) || $score > $chosen[$fee['fee_key']]['score']) {
                $chosen[$fee['fee_key']] = ['score' => $score, 'fee' => $fee];
            }
        }
        $fees = array_column($chosen, 'fee');
        usort($fees, static fn (array $a, array $b) => [(int) $a['sort_order'], (int) $a['id']] <=> [(int) $b['sort_order'], (int) $b['id']]);

        return $fees;
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

        $fees = self::computeFees(SubjectSelectionService::chosen($applicationId), (int) $application['program_id'], (int) $application['year_level_id']);

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
        // Reversals are negative payments, and adjustments are signed, so plain sums are right.
        $paidCents = self::signedCents((string) Database::scalar('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE fee_assessment_id = ?', [$assessmentId]));
        $adjustmentCents = self::signedCents((string) Database::scalar('SELECT COALESCE(SUM(amount), 0) FROM financial_adjustments WHERE fee_assessment_id = ?', [$assessmentId]));
        $dueCents = self::cents($assessment['total_amount']) + $adjustmentCents;

        $status = match (true) {
            $assessment['status'] !== 'ACTIVE'  => 'NOT_ASSESSED',
            $dueCents <= 0                       => 'PAID',
            $paidCents <= 0                      => 'PENDING',
            $paidCents < $dueCents               => 'PARTIAL',
            default                              => 'PAID',
        };

        Database::update('fee_assessments', [
            'total_adjustments' => self::signedMoney($adjustmentCents),
            'total_paid'        => self::money(max(0, $paidCents)),
            'balance'           => self::money(max(0, $dueCents - $paidCents)),
        ], ['id' => $assessmentId]);
        Database::update('enrollment_applications', ['payment_status' => $status], ['id' => $assessment['application_id']]);
    }

    // -- Online payment (student) -----------------------------------------------------

    /**
     * The student submits an online payment with its proof, for the cashier.
     *
     * @param array<string,mixed> $input amount, method, reference_number, paid_on, bank_account_id (for a bank payment)
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
        // A bank payment names the school's bank account it went to, and the same reference is used for it only once.
        $bank = BankAccountService::chosenFor((string) ($input['method'] ?? ''), $input['bank_account_id'] ?? null);
        if ($bank['error'] !== null) {
            $validator->addError('bank_account_id', $bank['error']);
        } elseif ($bank['id'] !== null && BankAccountService::referenceUsed($bank['id'], (string) ($input['reference_number'] ?? ''))) {
            $validator->addError('reference_number', 'That reference number was already used for this bank account. Check it, or contact the cashier if it is yours.');
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
            return Database::transaction(static function () use ($student, $assessment, $input, $cents, $storedName, $relativePath, $meta, $bank): int {
                $id = Database::insert('payment_submissions', [
                    'fee_assessment_id' => (int) $assessment['id'],
                    'student_id'        => (int) $assessment['student_id'],
                    'amount'            => self::money($cents),
                    'method'            => (string) $input['method'],
                    'bank_account_id'   => $bank['id'],
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

            if ($submission['bank_account_id'] !== null && BankAccountService::referenceUsed((int) $submission['bank_account_id'], (string) $submission['reference_number'], $submissionId)) {
                throw ValidationException::withMessage('Another payment already uses this reference number for this bank account. Reject this one, or check the reference first.');
            }

            $payment = self::createPayment($cashier, (int) $submission['fee_assessment_id'], $submission['amount'], $submission['method'],
                $submission['reference_number'], $submission['paid_on'] . ' ' . date('H:i:s'), $submissionId, $remarks,
                $submission['bank_account_id'] !== null ? (int) $submission['bank_account_id'] : null);

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
     * @param array<string,mixed> $input amount, payment_method, reference_number, remarks, bank_account_id (for a bank payment)
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
        // A bank payment at the window names the school bank account and carries its reference number, used only once.
        $method = (string) ($input['payment_method'] ?? '');
        $reference = trim((string) ($input['reference_number'] ?? ''));
        $bank = BankAccountService::chosenFor($method, $input['bank_account_id'] ?? null);
        if (BankAccountService::isBankMethod($method) && $reference === '') {
            $validator->addError('reference_number', 'Enter the bank reference number or the deposit slip number.');
        }
        if ($bank['error'] !== null) {
            $validator->addError('bank_account_id', $bank['error']);
        } elseif ($bank['id'] !== null && BankAccountService::referenceUsed($bank['id'], $reference)) {
            $validator->addError('reference_number', 'That reference number was already used for this bank account.');
        }
        if ($validator->fails()) {
            throw ValidationException::withErrors($validator->errors());
        }

        return Database::transaction(static fn (): array => self::createPayment(
            $cashier, $assessmentId, self::money(self::cents((string) $input['amount'])), $method,
            $reference !== '' ? $reference : null, date('Y-m-d H:i:s'), null, $input['remarks'] ?? null, $bank['id']
        ));
    }

    // -- Installments ----------------------------------------------------------------------

    public const INSTALLMENT_LABELS = [1 => 'Prelims', 2 => 'Midterm', 3 => 'Semi-Final', 4 => 'Finals'];

    /**
     * The exam-period installment plan (docs/14), or null before the student is enrolled.
     *
     * Obligations, in the order payments fill them: old account, initial payment, then Prelims,
     * Midterm, Semi-Final, and Finals. What remains after the old account and the initial payment
     * is split into four equal parts, the earliest taking any odd centavo. Money in centavos.
     *
     * @param array<string,mixed>            $assessment      with initial_paid, total_amount, total_adjustments, total_paid
     * @param array<int,array<string,mixed>> $adjustments     Finance::adjustments() rows
     * @param array<int,int>                 $openedSequences sequence numbers of the term's grading periods that have opened
     * @return array{rows:array<int,array<string,mixed>>,oldAccount:int,initial:int,remaining:int}|null
     */
    public static function installments(array $assessment, array $adjustments, array $openedSequences = []): ?array
    {
        if ($assessment['initial_paid'] === null) {
            return null;
        }

        $due = max(0, self::dueCents($assessment));
        $old = 0;
        foreach ($adjustments as $adjustment) {
            if ($adjustment['type'] === 'OLD_ACCOUNT' && self::signedCents($adjustment['amount']) > 0) {
                $old += self::signedCents($adjustment['amount']);
            }
        }
        $old = min($old, $due);

        $initial = self::cents($assessment['initial_paid']);
        $current = $due - $old;                              // tuition, fees, bridging, OJT, other charges
        $initialOnCurrent = min(max(0, $initial - $old), $current);
        $remaining = $current - $initialOnCurrent;

        $base = intdiv($remaining, 4);
        $extra = $remaining % 4;

        $obligations = [];
        if ($old > 0) {
            $obligations[] = ['key' => 'OLD', 'label' => 'Old account', 'sequence' => null, 'due' => $old];
        }
        $obligations[] = ['key' => 'INITIAL', 'label' => 'Initial payment', 'sequence' => null, 'due' => $initialOnCurrent];
        foreach (self::INSTALLMENT_LABELS as $sequence => $label) {
            $obligations[] = ['key' => 'P' . $sequence, 'label' => $label, 'sequence' => $sequence, 'due' => $base + ($sequence <= $extra ? 1 : 0)];
        }

        $pool = max(0, self::cents($assessment['total_paid']));
        $rows = [];
        foreach ($obligations as $obligation) {
            $paid = min($obligation['due'], $pool);
            $pool -= $paid;
            $balance = $obligation['due'] - $paid;
            $rows[] = $obligation + [
                'paid'    => $paid,
                'balance' => $balance,
                // Unpaid money is Due once its exam period has opened (the old account and the
                // initial payment are due from the start); it never blocks anything.
                'status'  => $balance === 0 ? 'PAID' : ($obligation['sequence'] === null || in_array($obligation['sequence'], $openedSequences, true) ? 'DUE' : 'UPCOMING'),
            ];
        }

        return ['rows' => $rows, 'oldAccount' => $old, 'initial' => $initial, 'remaining' => $remaining];
    }

    /**
     * The installments that are due now: unpaid and past their opening (docs/14). Display and
     * reminders only; nothing is blocked by them.
     *
     * @param array<string,mixed>|null $plan installments()
     * @return array<int,array<string,mixed>>
     */
    public static function dueRows(?array $plan): array
    {
        return $plan === null ? [] : array_values(array_filter($plan['rows'], static fn (array $row): bool => $row['status'] === 'DUE'));
    }

    /**
     * The amounts the walk-in payment buttons fill in (docs/14): what is still needed to enroll,
     * the next unpaid installment, and the full balance. They only fill the field.
     *
     * @param array<string,mixed>      $account   the assessment (balance)
     * @param string                   $suggested pesos still needed to meet the payment policy, or ''
     * @param array<string,mixed>|null $plan      installments(), or null before enrollment
     * @return array<int,array{label:string,amount:string}>
     */
    public static function quickAmounts(array $account, string $suggested, ?array $plan): array
    {
        $balance = self::cents($account['balance']);
        if ($balance <= 0) {
            return [];
        }

        $quick = [];
        if ($suggested !== '') {
            $quick[] = ['label' => 'Required to enroll', 'amount' => $suggested];
        }
        foreach ($plan['rows'] ?? [] as $row) {
            if ($row['balance'] > 0) {
                $quick[] = ['label' => 'Pay ' . strtolower($row['label']), 'amount' => self::money(min($row['balance'], $balance))];
                break;
            }
        }
        $quick[] = ['label' => 'Full balance', 'amount' => self::money($balance)];

        return $quick;
    }

    // -- The ledger card -------------------------------------------------------------------

    /**
     * A student's ledger in the school's own layout (docs/14): the assessment block and the
     * Date / Ref / Particulars / Debit / Credit / Balance rows. Money in whole centavos.
     *
     * Rows, oldest first after the opening ACCOUNT row: adjustments (a credit when they lower what
     * is owed, a debit when they raise it) and payments (a credit, Ref = the official receipt; a
     * reversal is a debit that points at the receipt it cancels).
     *
     * @param array<string,mixed>            $assessment
     * @param array<int,array<string,mixed>> $items       fee_items rows
     * @param array<int,array<string,mixed>> $payments    Finance::payments() rows
     * @param array<int,array<string,mixed>> $adjustments Finance::adjustments() rows
     * @param array<int,array<string,mixed>> $subjects    rows with units
     * @return array{units:float,rate:?string,tuition:int,misc:int,miscItems:array<int,array<string,mixed>>,discount:int,discountPercent:?float,rows:array<int,array<string,mixed>>,balance:int}
     */
    public static function ledger(array $assessment, array $items, array $payments, array $adjustments, array $subjects): array
    {
        $tuition = $misc = 0;
        $rate = null;
        $miscItems = [];
        foreach ($items as $item) {
            if ($item['basis'] === 'PER_UNIT') {
                $tuition += self::cents($item['amount']);
                $rate ??= $item['unit_amount'];
            } else {
                $misc += self::cents($item['amount']);
                $miscItems[] = $item;
            }
        }

        $discount = 0;
        $entries = [];
        foreach ($adjustments as $adjustment) {
            $signed = self::signedCents($adjustment['amount']);
            if ($signed < 0 && in_array($adjustment['type'], ['DISCOUNT', 'SCHOLARSHIP'], true)) {
                $discount += -$signed;
            }
            $entries[] = [
                'date'        => (string) $adjustment['created_at'],
                'order'       => 1,
                'ref'         => (string) $adjustment['adjustment_number'],
                'particulars' => (self::ADJUSTMENT_TYPES[$adjustment['type']] ?? (string) $adjustment['type']) . ': ' . $adjustment['reason'],
                'amount'      => $signed,
            ];
        }
        foreach ($payments as $payment) {
            $signed = self::signedCents($payment['amount']);
            $reversal = $payment['reversed_payment_id'] !== null;
            $entries[] = [
                'date'        => (string) $payment['paid_at'],
                'order'       => 2,
                'ref'         => $reversal ? 'Rev. ' . $payment['reversed_receipt_number'] : (string) $payment['receipt_number'],
                'particulars' => $reversal
                    ? 'Reversal: ' . $payment['remarks']
                    : 'Payment, ' . $payment['payment_method'] . ($payment['reference_number'] ? ' (ref. ' . $payment['reference_number'] . ')' : ''),
                // A payment lowers the balance (a credit); a reversal puts it back (a debit).
                'amount'      => -$signed,
            ];
        }
        usort($entries, static fn (array $a, array $b) => [$a['date'], $a['order']] <=> [$b['date'], $b['order']]);

        $balance = self::cents($assessment['total_amount']);
        $rows = [[
            'date' => (string) $assessment['assessed_at'], 'ref' => (string) $assessment['assessment_number'],
            'particulars' => 'Account (tuition and miscellaneous fees)', 'debit' => $balance, 'credit' => 0, 'balance' => $balance,
        ]];
        foreach ($entries as $entry) {
            $balance += $entry['amount'];
            $rows[] = [
                'date' => $entry['date'], 'ref' => $entry['ref'], 'particulars' => $entry['particulars'],
                'debit' => max(0, $entry['amount']), 'credit' => max(0, -$entry['amount']), 'balance' => $balance,
            ];
        }

        return [
            'units'           => (float) array_sum(array_map(static fn ($s) => (float) $s['units'], $subjects)),
            'rate'            => $rate,
            'tuition'         => $tuition,
            'misc'            => $misc,
            'miscItems'       => $miscItems,
            'discount'        => $discount,
            'discountPercent' => $tuition > 0 && $discount > 0 ? round($discount / $tuition * 100, 2) : null,
            'rows'            => $rows,
            'balance'         => $balance,
        ];
    }

    // -- Corrections (cashier) ------------------------------------------------------------

    public const ADJUSTMENT_TYPES = [
        'DISCOUNT'          => 'Discount',
        'SCHOLARSHIP'       => 'Scholarship',
        'WAIVER'            => 'Fee waiver',
        'ADDITIONAL_CHARGE' => 'Additional charge',
        'BRIDGING'          => 'Bridging fee',
        'OJT_FEE'           => 'OJT fee',
        'OLD_ACCOUNT'       => 'Old account',
        'CORRECTION'        => 'Correction',
    ];

    /** Types that raise what the student owes. A correction goes either way. */
    public const INCREASING_TYPES = ['ADDITIONAL_CHARGE', 'BRIDGING', 'OJT_FEE', 'OLD_ACCOUNT'];

    /**
     * A discount, scholarship, waiver, extra charge, or correction to what a student
     * owes. Takes effect at once, always with a reason, always audited. It may not
     * bring the amount due below what has already been paid (refunds are not built)
     * nor below zero. A reduction can meet the payment policy and enroll the student.
     *
     * A discount or scholarship may instead be given as a percentage of the tuition (the assessment's
     * per-unit lines only; miscellaneous fees are not discounted). The peso amount is that share,
     * rounded to the centavo, and the stored reason carries the percentage (docs/14).
     *
     * @param array<string,mixed> $input type, amount (positive pesos) or percent, reason, direction (CORRECTION only: increase|decrease)
     * @return int the adjustment id
     */
    public static function adjust(User $cashier, int $assessmentId, array $input): int
    {
        self::assertCashier($cashier);

        $percentText = trim((string) ($input['percent'] ?? ''));
        $byPercent = $percentText !== '';

        $validator = Validator::make($input, [
            'type'   => 'required|in:' . implode(',', array_keys(self::ADJUSTMENT_TYPES)),
            'amount' => $byPercent ? 'nullable' : 'required',
            'reason' => 'required|min:5|max:480',
        ], ['reason' => 'Reason']);

        $type = (string) ($input['type'] ?? '');
        $amountText = trim((string) ($input['amount'] ?? ''));
        if ($byPercent) {
            if ($amountText !== '') {
                $validator->addError('percent', 'Give either a percentage or an amount in pesos, not both.');
            } elseif (!in_array($type, ['DISCOUNT', 'SCHOLARSHIP'], true)) {
                $validator->addError('percent', 'A percentage can be used for a discount or a scholarship.');
            } elseif (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $percentText) || (float) $percentText <= 0 || (float) $percentText > 100) {
                $validator->addError('percent', 'Enter a percentage from 0.01 to 100, with at most two decimal places.');
            }
        } elseif ($amountText !== '' && (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $amountText) || self::cents($amountText) <= 0)) {
            $validator->addError('amount', 'Enter an amount in pesos greater than zero, with at most two decimal places.');
        }
        $direction = (string) ($input['direction'] ?? '');
        if ($type === 'CORRECTION' && !in_array($direction, ['increase', 'decrease'], true)) {
            $validator->addError('direction', 'Say whether the correction increases or decreases what the student owes.');
        }
        if ($validator->fails()) {
            throw ValidationException::withErrors($validator->errors());
        }

        $increase = in_array($type, self::INCREASING_TYPES, true) || ($type === 'CORRECTION' && $direction === 'increase');
        $reason = trim((string) $input['reason']);

        return Database::transaction(static function () use ($cashier, $assessmentId, $type, $increase, $amountText, $percentText, $byPercent, $reason): int {
            $assessment = Database::selectOne(
                'SELECT fa.*, s.user_id FROM fee_assessments fa JOIN students s ON s.id = fa.student_id WHERE fa.id = ? FOR UPDATE',
                [$assessmentId]
            );
            if ($assessment === null) {
                throw HttpException::notFound('That account was not found.');
            }
            if ($assessment['status'] !== 'ACTIVE') {
                throw ValidationException::withMessage('This assessment is no longer active.');
            }

            if ($byPercent) {
                $tuition = self::cents((string) Database::scalar(
                    "SELECT COALESCE(SUM(amount), 0) FROM fee_items WHERE fee_assessment_id = ? AND basis = 'PER_UNIT'",
                    [$assessmentId]
                ));
                $cents = intdiv($tuition * (int) round((float) $percentText * 100) + 5000, 10000);
                if ($cents <= 0) {
                    throw ValidationException::withErrors(['percent' => ['This account has no tuition to take a percentage of.']], 'There is no tuition to discount.');
                }
                $reason = rtrim(rtrim(number_format((float) $percentText, 2, '.', ''), '0'), '.') . '% of tuition. ' . $reason;
            } else {
                $cents = self::cents($amountText);
            }
            $signed = ($increase ? 1 : -1) * $cents;

            $dueAfter = self::cents($assessment['total_amount']) + self::signedCents($assessment['total_adjustments']) + $signed;
            $paid = self::cents($assessment['total_paid']);
            if ($dueAfter < $paid) {
                throw ValidationException::withErrors(['amount' => [sprintf(
                    'That would make the amount due %s, less than the %s already paid. Refunds are not supported; reduce the adjustment.',
                    money(self::money(max(0, $dueAfter))), money($assessment['total_paid'])
                )]], 'The adjustment would leave the student overpaid.');
            }

            $id = Database::insert('financial_adjustments', [
                'adjustment_number' => SequenceService::next('adjustment', 'ADJ'),
                'fee_assessment_id' => $assessmentId,
                'type'              => $type,
                'amount'            => self::signedMoney($signed),
                'reason'            => $reason,
                'created_by'        => $cashier->id,
                'created_at'        => date('Y-m-d H:i:s'),
            ]);
            self::recalculate($assessmentId);

            AuditService::log('finance.adjusted', 'finance', 'FinancialAdjustment', $id, ['balance' => $assessment['balance']], [
                'assessment' => $assessment['assessment_number'], 'type' => $type, 'amount' => self::signedMoney($signed),
            ], 'SUCCESS', $reason, $cashier->id, $cashier->role_name);

            NotificationService::notify((int) $assessment['user_id'], 'finance.adjusted',
                self::ADJUSTMENT_TYPES[$type] . ' of ' . money(self::money(abs($signed))) . ' applied to your account', $reason, '/student/account?assessment=' . $assessmentId);

            EnrollmentConfirmationService::confirmIfReady((int) $assessment['application_id'], $cashier);

            return $id;
        });
    }

    /**
     * Reverse a payment recorded by mistake: a new, negative payments row that points
     * at it. The original and its receipt stay as they were. If the student was
     * enrolled and is now below the payment policy, the enrollment stands and the
     * registrar and cashier are told to follow up.
     *
     * @return int the reversal's payments id
     */
    public static function reversePayment(User $cashier, int $paymentId, string $reason): int
    {
        self::assertCashier($cashier);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withErrors(['reason' => ['Give the reason for reversing this payment (at least 5 characters).']], 'A reason is required to reverse a payment.');
        }

        return Database::transaction(static function () use ($cashier, $paymentId, $reason): int {
            DayCloseService::assertOpen();
            $payment = Database::selectOne(
                'SELECT pay.*, fa.application_id, fa.assessment_number, s.user_id
                   FROM payments pay
                   JOIN fee_assessments fa ON fa.id = pay.fee_assessment_id
                   JOIN students s ON s.id = pay.student_id
                  WHERE pay.id = ?',
                [$paymentId]
            );
            if ($payment === null) {
                throw HttpException::notFound('That payment was not found.');
            }
            // Lock the assessment, as every payment path does, before checking for an
            // earlier reversal. The unique key on reversed_payment_id backs this up.
            Database::selectOne('SELECT id FROM fee_assessments WHERE id = ? FOR UPDATE', [$payment['fee_assessment_id']]);

            if ($payment['reversed_payment_id'] !== null) {
                throw ValidationException::withMessage('This entry is itself a reversal and cannot be reversed.');
            }
            if (Database::scalar('SELECT id FROM payments WHERE reversed_payment_id = ?', [$paymentId]) !== null) {
                throw ValidationException::withMessage("Receipt {$payment['receipt_number']} was already reversed.");
            }

            $reversalId = Database::insert('payments', [
                'transaction_number'  => SequenceService::next('payment', 'PAY'),
                'receipt_number'      => null,
                'fee_assessment_id'   => (int) $payment['fee_assessment_id'],
                'student_id'          => (int) $payment['student_id'],
                'amount'              => '-' . $payment['amount'],
                'payment_method'      => $payment['payment_method'],
                'reference_number'    => $payment['reference_number'],
                'paid_at'             => date('Y-m-d H:i:s'),
                'cashier_user_id'     => $cashier->id,
                'reversed_payment_id' => $paymentId,
                'remarks'             => $reason,
            ]);
            self::recalculate((int) $payment['fee_assessment_id']);

            AuditService::log('finance.payment_reversed', 'finance', 'Payment', $paymentId, ['amount' => $payment['amount'], 'receipt' => $payment['receipt_number']], [
                'reversal_id' => $reversalId, 'assessment' => $payment['assessment_number'],
                'from_closed_day' => DayCloseService::isClosed(substr((string) $payment['created_at'], 0, 10)),
            ], 'SUCCESS', $reason, $cashier->id, $cashier->role_name);

            NotificationService::notify((int) $payment['user_id'], 'finance.payment_reversed',
                'Payment of ' . money($payment['amount']) . " (receipt {$payment['receipt_number']}) was reversed", $reason, '/student/account?assessment=' . $payment['fee_assessment_id']);

            $application = Database::selectOne('SELECT * FROM enrollment_applications WHERE id = ?', [$payment['application_id']]);
            if ($application['enrollment_state'] === 'ENROLLED' && PaymentPolicy::problem($application) !== null) {
                foreach ([User::ROLE_REGISTRAR, User::ROLE_CASHIER] as $role) {
                    NotificationService::notifyRole($role, 'finance.below_policy',
                        "Enrolled student's account {$payment['assessment_number']} is now below the payment policy",
                        "A payment was reversed: {$reason}", $role === User::ROLE_CASHIER ? '/cashier/accounts/' . $payment['fee_assessment_id'] : '/registrar/applications/' . $payment['application_id']);
                }
            }

            return $reversalId;
        });
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
    private static function createPayment(User $cashier, int $assessmentId, string $amount, string $method, ?string $reference, string $paidAt, ?int $submissionId, ?string $remarks, ?int $bankAccountId = null): array
    {
        DayCloseService::assertOpen();
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
            'bank_account_id'       => $bankAccountId,
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

    /** Like cents(), keeping a leading minus: "-500.00" -> -50000. */
    public static function signedCents(string|int|float $amount): int
    {
        $text = trim((string) $amount);
        return str_starts_with($text, '-') ? -self::cents(substr($text, 1)) : self::cents($text);
    }

    /** Like money(), keeping the sign: -50000 -> "-500.00". */
    public static function signedMoney(int $cents): string
    {
        return ($cents < 0 ? '-' : '') . self::money(abs($cents));
    }

    /** The amount due on an assessment row, in centavos: total plus adjustments. */
    public static function dueCents(array $assessment): int
    {
        return self::cents($assessment['total_amount']) + self::signedCents((string) ($assessment['total_adjustments'] ?? '0'));
    }
}
