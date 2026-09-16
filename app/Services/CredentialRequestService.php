<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Models\CredentialRequest;
use App\Models\Student;
use App\Models\User;

/**
 * Student requests for official documents, and the registrar's processing of them (docs/08).
 *
 * Students (`credentials.request`) file a request; the registrar (`credentials.manage`) moves it
 * through REQUESTED -> PROCESSING -> READY -> RELEASED, or REJECTED, and the student is notified at
 * each step. A student may cancel while it is still REQUESTED. Every change is audited.
 */
final class CredentialRequestService
{
    /** Which status a registrar may move a request to from each open status. */
    private const NEXT = [
        'REQUESTED'  => ['PROCESSING', 'READY', 'REJECTED'],
        'PROCESSING' => ['READY', 'REJECTED'],
        'READY'      => ['RELEASED', 'REJECTED'],
    ];

    /**
     * A student files a request.
     *
     * @param array<string,mixed> $input type, purpose, copies
     * @throws ValidationException|HttpException
     */
    public static function request(User $student, array $input): void
    {
        if (!$student->hasPermission('credentials.request')) {
            throw HttpException::forbidden('You are not allowed to request documents.');
        }
        $studentRow = RegistrationService::ensureStudentRecord($student);
        $studentId = (int) $studentRow['id'];

        $type = (string) ($input['type'] ?? '');
        $purpose = trim((string) ($input['purpose'] ?? ''));
        $copies = (int) ($input['copies'] ?? 1);
        $errors = [];

        if (!isset(CredentialRequest::TYPES[$type])) {
            $errors['type'] = ['Choose a document to request.'];
        } elseif ((int) Database::scalar(
            "SELECT COUNT(*) FROM credential_requests WHERE student_id = ? AND type = ? AND status IN ('REQUESTED','PROCESSING','READY')",
            [$studentId, $type]
        ) > 0) {
            $errors['type'] = ['You already have an open request for that document. Wait for it, or cancel it first.'];
        }
        if ($copies < 1 || $copies > 10) {
            $errors['copies'] = ['Ask for between 1 and 10 copies.'];
        }
        if (mb_strlen($purpose) > 200) {
            $errors['purpose'] = ['Keep the purpose under 200 characters.'];
        }
        if ($errors !== []) {
            throw ValidationException::withErrors($errors);
        }

        Database::transaction(static function () use ($student, $studentId, $type, $purpose, $copies): void {
            $id = Database::insert('credential_requests', [
                'student_id' => $studentId,
                'type'       => $type,
                'purpose'    => $purpose !== '' ? $purpose : null,
                'copies'     => $copies,
                'status'     => 'REQUESTED',
            ]);
            AuditService::log('credential.requested', 'students', 'CredentialRequest', $id,
                null, ['type' => $type, 'copies' => $copies], 'SUCCESS', null, $student->id, $student->role_name);
        });
    }

    /** A student cancels their own still-pending request. */
    public static function cancel(User $student, int $id): void
    {
        $studentRow = RegistrationService::ensureStudentRecord($student);
        $request = CredentialRequest::find($id);
        if ($request === null || (int) $request['student_id'] !== (int) $studentRow['id']) {
            throw HttpException::notFound('That request was not found.');
        }
        if ($request['status'] !== 'REQUESTED') {
            throw ValidationException::withMessage('Only a request the registrar has not started can be cancelled.');
        }
        Database::transaction(static function () use ($student, $id): void {
            Database::update('credential_requests', ['status' => 'CANCELLED', 'updated_by' => $student->id], ['id' => $id]);
            AuditService::log('credential.cancelled', 'students', 'CredentialRequest', $id, null, null, 'SUCCESS', null, $student->id, $student->role_name);
        });
    }

    /**
     * The registrar advances a request, optionally noting a fee and remarks, and the student is
     * notified.
     *
     * @param array<string,mixed> $input status, fee_amount, remarks
     * @throws ValidationException|HttpException
     */
    public static function process(User $registrar, int $id, array $input): void
    {
        if (!$registrar->hasPermission('credentials.manage')) {
            throw HttpException::forbidden('You are not allowed to process document requests.');
        }
        $request = CredentialRequest::find($id);
        if ($request === null) {
            throw HttpException::notFound('That request was not found.');
        }

        $from = (string) $request['status'];
        $to = (string) ($input['status'] ?? '');
        $allowed = self::NEXT[$from] ?? [];
        if (!in_array($to, $allowed, true)) {
            throw ValidationException::withMessage('That request can no longer be updated to that status.');
        }

        $remarks = trim((string) ($input['remarks'] ?? ''));
        if ($to === 'REJECTED' && $remarks === '') {
            throw ValidationException::withErrors(['remarks' => ['Give the student a reason for rejecting the request.']]);
        }
        if (mb_strlen($remarks) > 255) {
            throw ValidationException::withErrors(['remarks' => ['Keep the note under 255 characters.']]);
        }

        // A fee is optional and only meaningful while the document is being prepared.
        $feeInput = trim((string) ($input['fee_amount'] ?? ''));
        $fee = null;
        if ($feeInput !== '') {
            if (!preg_match('/^\d+(\.\d{1,2})?$/', $feeInput) || (float) $feeInput < 0) {
                throw ValidationException::withErrors(['fee_amount' => ['Enter the fee in pesos, or leave it blank.']]);
            }
            $fee = number_format((float) $feeInput, 2, '.', '');
        }

        $studentUserId = (int) Database::scalar('SELECT user_id FROM students WHERE id = ?', [(int) $request['student_id']]);
        $typeLabel = CredentialRequest::typeLabel((string) $request['type']);

        Database::transaction(static function () use ($registrar, $id, $request, $to, $fee, $remarks, $studentUserId, $typeLabel): void {
            $data = ['status' => $to, 'updated_by' => $registrar->id];
            if ($fee !== null) {
                $data['fee_amount'] = $fee;
            }
            if ($remarks !== '') {
                $data['remarks'] = $remarks;
            }
            if ($to === 'RELEASED') {
                $data['released_at'] = date('Y-m-d H:i:s');
            }
            Database::update('credential_requests', $data, ['id' => $id]);

            $message = match ($to) {
                'PROCESSING' => 'Your request for your ' . $typeLabel . ' is being prepared.',
                'READY'      => 'Your ' . $typeLabel . ' is ready. ' . ($fee !== null ? 'Please settle the ₱' . $fee . ' fee at the cashier, then claim it at the registrar.' : 'Claim it at the registrar.'),
                'RELEASED'   => 'Your ' . $typeLabel . ' has been released.',
                'REJECTED'   => 'Your request for your ' . $typeLabel . ' was not approved: ' . $remarks,
                default      => 'Your document request was updated.',
            };
            NotificationService::notify($studentUserId, 'credential.' . strtolower($to), $typeLabel . ': ' . CredentialRequest::STATUS_LABELS[$to], $message, '/student/credentials');

            AuditService::log('credential.' . strtolower($to), 'students', 'CredentialRequest', $id,
                ['status' => $request['status']], ['status' => $to, 'fee' => $fee], 'SUCCESS', $remarks !== '' ? $remarks : null, $registrar->id, $registrar->role_name);
        });
    }
}
