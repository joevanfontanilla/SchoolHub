<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Models\EnrollmentApplication as Application;
use App\Models\User;

/**
 * The registrar's decision on one uploaded document (docs/10, docs/11).
 *
 * Documents are reviewed only while their application is UNDER_REVIEW, and a
 * decision can be changed until the application itself is decided.
 */
final class RequirementReviewService
{
    public const DECISIONS = [
        Application::REQ_VERIFIED,
        Application::REQ_REJECTED,
        Application::REQ_CORRECTION_REQUIRED,
    ];

    /** @throws ValidationException|HttpException */
    public static function review(User $user, int $requirementId, string $decision, ?string $remarks): void
    {
        if (!$user->isRegistrar()) {
            throw HttpException::forbidden('Only the registrar can review documents.');
        }
        if (!in_array($decision, self::DECISIONS, true)) {
            throw ValidationException::withMessage('Choose verify, reject, or request a correction.');
        }

        $remarks = trim((string) $remarks);
        if ($decision !== Application::REQ_VERIFIED && $remarks === '') {
            throw ValidationException::withErrors(
                ['remarks' => ['Tell the student what is wrong with this document.']],
                'A note is required when a document is rejected or needs correction.'
            );
        }

        Database::transaction(static function () use ($user, $requirementId, $decision, $remarks): void {
            $requirement = Database::selectOne(
                'SELECT er.*, rt.name, rt.code, s.user_id AS owner_user_id, ea.application_number
                   FROM enrollment_requirements er
                   JOIN requirement_types rt       ON rt.id = er.requirement_type_id
                   JOIN enrollment_applications ea ON ea.id = er.application_id
                   JOIN students s                 ON s.id  = ea.student_id
                  WHERE er.id = ?',
                [$requirementId]
            );
            if ($requirement === null) {
                throw HttpException::notFound('That document was not found.');
            }

            // Lock the application, then the requirement: the same order the upload
            // service uses, so the two can never deadlock each other.
            $application = Database::selectOne(
                'SELECT * FROM enrollment_applications WHERE id = ? FOR UPDATE',
                [$requirement['application_id']]
            );
            Database::selectOne('SELECT id FROM enrollment_requirements WHERE id = ? FOR UPDATE', [$requirementId]);

            if ($application['application_status'] !== Application::UNDER_REVIEW) {
                throw ValidationException::withMessage(
                    'Documents can be reviewed only while the application is under review. It is currently '
                    . strtolower(Application::label($application['application_status'])) . '.'
                );
            }

            $hasFile = (int) Database::scalar(
                'SELECT COUNT(*) FROM enrollment_requirement_files WHERE enrollment_requirement_id = ? AND is_current = 1',
                [$requirementId]
            ) > 0;
            if (!$hasFile) {
                throw ValidationException::withMessage(
                    "No file has been uploaded for {$requirement['name']}, so there is nothing to review. "
                    . 'Return the application as incomplete instead.'
                );
            }

            $verified = $decision === Application::REQ_VERIFIED;
            Database::update('enrollment_requirements', [
                'status'      => $decision,
                'remarks'     => $verified ? null : $remarks,
                'verified_by' => $verified ? $user->id : null,
                'verified_at' => $verified ? date('Y-m-d H:i:s') : null,
            ], ['id' => $requirementId]);

            AuditService::log(
                'requirement.reviewed',
                'requirements',
                'EnrollmentRequirement',
                $requirementId,
                ['status' => $requirement['status']],
                ['status' => $decision, 'requirement' => $requirement['code']],
                'SUCCESS',
                $verified ? null : $remarks,
                $user->id,
                $user->role_name
            );

            if (!$verified) {
                NotificationService::notify(
                    (int) $requirement['owner_user_id'],
                    'requirement.' . strtolower($decision),
                    ($decision === Application::REQ_REJECTED ? 'Document rejected: ' : 'Document needs correction: ')
                        . $requirement['name'],
                    $remarks,
                    '/student/enrollment/' . $requirement['application_id']
                );
            }
        });
    }
}
