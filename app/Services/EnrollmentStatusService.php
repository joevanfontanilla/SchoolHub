<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Models\EnrollmentApplication as App;
use App\Models\User;

/**
 * The only code that changes an application's status (docs/26 section 5).
 *
 * The transition matrix below is docs/26 section 2, encoded as data. A pair
 * that is not listed is invalid. Every transition:
 *   - locks the application row, so a concurrent request cannot race it;
 *   - re-checks role, remarks, and preconditions at that moment, so a stale
 *     form or a replayed request cannot slip past a check that passed earlier;
 *   - writes exactly one enrollment_status_history row and one audit entry;
 *   - changes nothing at all if any check fails.
 *
 * Super Admin is deliberately absent from every rule: it administers the
 * system but is not a substitute registrar (docs/04).
 */
final class EnrollmentStatusService
{
    private const STUDENT   = User::ROLE_STUDENT;
    private const REGISTRAR = User::ROLE_REGISTRAR;

    /**
     * "FROM>TO" => [docs/26 rule number, permitted roles, remarks required, precondition method or null]
     *
     * @var array<string,array{0:int,1:array<int,string>,2:bool,3:?string}>
     */
    private const RULES = [
        'DRAFT>SUBMITTED'                   => [2,  [self::STUDENT, self::REGISTRAR], false, 'readyToSubmitFromDraft'],
        'SUBMITTED>UNDER_REVIEW'            => [3,  [self::REGISTRAR], false, null],
        'UNDER_REVIEW>REQUIREMENTS_INCOMPLETE' => [4, [self::REGISTRAR], true, 'hasIncompleteRequirement'],
        'UNDER_REVIEW>CORRECTION_REQUIRED'  => [5,  [self::REGISTRAR], true,  null],
        'REQUIREMENTS_INCOMPLETE>SUBMITTED' => [6,  [self::STUDENT],   false, 'requiredDocumentsSubmitted'],
        'CORRECTION_REQUIRED>SUBMITTED'     => [7,  [self::STUDENT],   false, 'formIsValid'],
        'UNDER_REVIEW>APPROVED'             => [8,  [self::REGISTRAR], false, 'readyToApprove'],
        'UNDER_REVIEW>REJECTED'             => [9,  [self::REGISTRAR], true,  null],
        'DRAFT>CANCELLED'                   => [10, [self::STUDENT, self::REGISTRAR], false, null],
        'SUBMITTED>CANCELLED'               => [11, [self::STUDENT, self::REGISTRAR], false, null],
        'UNDER_REVIEW>CANCELLED'            => [11, [self::STUDENT, self::REGISTRAR], false, null],
        'REQUIREMENTS_INCOMPLETE>CANCELLED' => [11, [self::STUDENT, self::REGISTRAR], false, null],
        'CORRECTION_REQUIRED>CANCELLED'     => [11, [self::STUDENT, self::REGISTRAR], false, null],
        'APPROVED>CANCELLED'                => [12, [self::REGISTRAR], true,  'approvedCancellable'],
    ];

    /**
     * Move an application to a new status.
     *
     * @return array<string,mixed> the updated application row
     * @throws ValidationException when the transition or its preconditions are not met
     * @throws HttpException       404 for a student who does not own it, 403 for a role that may not do this
     */
    public static function transition(int $applicationId, string $to, User $actor, ?string $remarks = null): array
    {
        $remarks = $remarks !== null ? trim($remarks) : null;

        return Database::transaction(static function () use ($applicationId, $to, $actor, $remarks): array {
            $application = Database::selectOne(
                'SELECT ea.*, s.user_id AS owner_user_id
                   FROM enrollment_applications ea
                   JOIN students s ON s.id = ea.student_id
                  WHERE ea.id = ?
                  FOR UPDATE',
                [$applicationId]
            );

            // A student acting on someone else's application is told it does not
            // exist, rather than that it is forbidden.
            if ($application === null || ($actor->isStudent() && (int) $application['owner_user_id'] !== $actor->id)) {
                throw HttpException::notFound('That application was not found.');
            }

            $from = (string) $application['application_status'];

            if (in_array($from, App::TERMINAL, true)) {
                throw ValidationException::withMessage(
                    'This application is ' . strtolower(App::label($from)) . ' and can no longer be changed.'
                );
            }

            $rule = self::RULES["{$from}>{$to}"] ?? null;
            if ($rule === null) {
                throw ValidationException::withMessage(sprintf(
                    'An application that is %s cannot be moved to %s.',
                    strtolower(App::label($from)),
                    strtolower(App::label($to))
                ));
            }

            [$ruleNumber, $roles, $remarksRequired, $precondition] = $rule;

            if (!in_array($actor->role_name, $roles, true)) {
                throw HttpException::forbidden('Your role cannot make this change to an application.');
            }

            // A draft is the student's private work. The registrar may act on one only when
            // the registrar started it as a walk-in (docs/26 rules 2 and 10).
            if ($from === App::DRAFT && $actor->isRegistrar() && ($application['source'] ?? 'online') !== 'walk_in') {
                throw HttpException::forbidden('Only the student can act on their own draft.');
            }

            if ($remarksRequired && ($remarks === null || $remarks === '')) {
                throw ValidationException::withErrors(
                    ['remarks' => ['Please explain the reason. The student will see it.']],
                    'Remarks are required for this action.'
                );
            }

            if ($precondition !== null) {
                $problem = self::{$precondition}($application);
                if ($problem !== null) {
                    throw ValidationException::withMessage($problem);
                }
            }

            $now = date('Y-m-d H:i:s');
            $update = ['application_status' => $to];

            if ($to === App::SUBMITTED) {
                $update['submitted_at'] = $now;
            }
            if ($to === App::UNDER_REVIEW) {
                $update['reviewed_by'] = $actor->id;
                $update['reviewed_at'] = $now;
            }
            if (in_array($to, [App::APPROVED, App::REJECTED], true)) {
                $update['decided_at'] = $now;
            }
            if ($remarks !== null && $remarks !== '') {
                $update['remarks'] = $remarks;
            }

            Database::update('enrollment_applications', $update, ['id' => $applicationId]);

            self::writeHistory($applicationId, 'application', $from, $to, $actor, $remarks);
            self::applySideEffects($application, $from, $to, $actor, $remarks);

            AuditService::log(
                'enrollment.status_changed',
                'enrollment',
                'EnrollmentApplication',
                $applicationId,
                ['application_status' => $from],
                ['application_status' => $to, 'rule' => $ruleNumber],
                'SUCCESS',
                $remarks !== '' ? $remarks : null,
                $actor->id,
                $actor->role_name
            );

            return Database::selectOne('SELECT * FROM enrollment_applications WHERE id = ?', [$applicationId]);
        });
    }

    /**
     * The enrollment axis (docs/26 section 4): NOT_ENROLLED -> ENROLLED.
     *
     * Registrar or cashier; the application must be APPROVED and the payment policy
     * satisfied. Call it from EnrollmentConfirmationService, which also creates
     * the enrollment record and issues the student number in the same
     * transaction — never on its own.
     *
     * @return array<string,mixed> the updated application row
     */
    public static function transitionEnrollment(int $applicationId, string $to, User $actor): array
    {
        return Database::transaction(static function () use ($applicationId, $to, $actor): array {
            $application = Database::selectOne('SELECT * FROM enrollment_applications WHERE id = ? FOR UPDATE', [$applicationId]);
            if ($application === null) {
                throw HttpException::notFound('That application was not found.');
            }

            $from = (string) $application['enrollment_state'];

            if ("{$from}>{$to}" !== 'NOT_ENROLLED>ENROLLED') {
                throw ValidationException::withMessage(sprintf(
                    'Enrollment cannot be changed from %s to %s.',
                    strtolower(App::label($from)),
                    strtolower(App::label($to))
                ));
            }
            // The registrar confirms by hand; the cashier's recorded payment confirms
            // automatically once the payment policy is met (docs/26 section 4).
            if (!$actor->isRegistrar() && !$actor->isCashier()) {
                throw HttpException::forbidden('Only the registrar, or a verified payment, can confirm enrollment.');
            }
            if ($application['application_status'] !== App::APPROVED) {
                throw ValidationException::withMessage('Only an approved application can be confirmed for enrollment.');
            }
            $paymentProblem = PaymentPolicy::problem($application);
            if ($paymentProblem !== null) {
                throw ValidationException::withMessage($paymentProblem);
            }

            Database::update('enrollment_applications', ['enrollment_state' => $to], ['id' => $applicationId]);
            self::writeHistory($applicationId, 'enrollment', $from, $to, $actor, null);

            AuditService::log(
                'enrollment.state_changed',
                'enrollment',
                'EnrollmentApplication',
                $applicationId,
                ['enrollment_state' => $from],
                ['enrollment_state' => $to],
                'SUCCESS',
                null,
                $actor->id,
                $actor->role_name
            );

            return Database::selectOne('SELECT * FROM enrollment_applications WHERE id = ?', [$applicationId]);
        });
    }

    /**
     * Side effects that belong to a transition (docs/26 section 2, last column).
     * Runs inside the transition's transaction, so they commit or roll back with it.
     *
     * @param array<string,mixed> $application the row as locked, before the change
     */
    private static function applySideEffects(array $application, string $from, string $to, User $actor, ?string $remarks): void
    {
        $id = (int) $application['id'];
        $number = (string) $application['application_number'];
        $studentLink = "/student/enrollment/{$id}";
        $note = ($remarks !== null && $remarks !== '') ? $remarks : null;

        // Rule 3: documents waiting for review are now being reviewed.
        if ($to === App::UNDER_REVIEW) {
            Database::query(
                "UPDATE enrollment_requirements SET status = 'UNDER_REVIEW' WHERE application_id = ? AND status = 'SUBMITTED'",
                [$id]
            );
        }

        // Rule 8: issue the fee assessment (payment_status becomes PENDING). With no
        // payment required, the student is enrolled at once.
        if ($to === App::APPROVED) {
            FinanceService::assess($application + ['application_status' => App::APPROVED], $actor);
            EnrollmentConfirmationService::confirmIfReady($id, $actor);
        }

        // Rules 2, 6, 7: the registrar's queue has new work.
        if ($to === App::SUBMITTED) {
            NotificationService::notifyRole(
                User::ROLE_REGISTRAR,
                'enrollment.submitted',
                $from === App::DRAFT ? "New application {$number}" : "Application {$number} was resubmitted",
                null,
                "/registrar/applications/{$id}"
            );
        }

        // Registrar decisions reach the student.
        if ($actor->isRegistrar()) {
            $message = match ($to) {
                App::REQUIREMENTS_INCOMPLETE => 'Some documents need to be replaced',
                App::CORRECTION_REQUIRED     => 'Your application needs a correction',
                App::APPROVED                => 'Your application was approved. Your fee assessment is ready',
                App::REJECTED                => 'Your application was not approved',
                App::CANCELLED               => 'Your application was cancelled by the registrar',
                default                      => null,
            };
            if ($message !== null) {
                NotificationService::notify((int) $application['owner_user_id'], 'enrollment.' . strtolower($to), "{$message} ({$number})", $note, $studentLink);
            }
        }

        // Rule 11 by the student after submission: tell the registrar's office.
        if ($actor->isStudent() && $to === App::CANCELLED && $from !== App::DRAFT) {
            NotificationService::notifyRole(User::ROLE_REGISTRAR, 'enrollment.cancelled', "Application {$number} was withdrawn by the student", $note, "/registrar/applications/{$id}");
        }
    }

    /**
     * Record the creation of an application (docs/26 rule 1: none -> DRAFT).
     * Called by EnrollmentApplicationService inside its own transaction.
     */
    public static function recordCreation(int $applicationId, User $actor): void
    {
        self::writeHistory($applicationId, 'application', null, App::DRAFT, $actor, null);
    }

    /**
     * Targets this actor could move the application to, by rule and role only.
     * For deciding which buttons to render; transition() still re-checks
     * everything, including preconditions, when the button is pressed.
     *
     * @param array<string,mixed> $application
     * @return array<int,string>
     */
    public static function availableTargets(array $application, User $actor): array
    {
        $from = (string) $application['application_status'];
        $targets = [];

        foreach (self::RULES as $pair => [, $roles]) {
            [$ruleFrom, $ruleTo] = explode('>', $pair);
            if ($ruleFrom === $from && in_array($actor->role_name, $roles, true)) {
                $targets[] = $ruleTo;
            }
        }

        return $targets;
    }

    /** @return array<string,array{0:int,1:array<int,string>,2:bool,3:?string}> */
    public static function rules(): array
    {
        return self::RULES;
    }

    // -- Preconditions: return null when satisfied, or a message for the user --

    /** @param array<string,mixed> $application */
    private static function readyToSubmitFromDraft(array $application): ?string
    {
        return self::profileProblem($application)
            ?? EnrollmentApplicationService::formProblem($application)
            ?? SubjectSelectionService::problem($application)
            ?? self::requiredDocumentsSubmitted($application);
    }

    /** @param array<string,mixed> $application */
    private static function formIsValid(array $application): ?string
    {
        return self::profileProblem($application)
            ?? EnrollmentApplicationService::formProblem($application)
            ?? SubjectSelectionService::problem($application);
    }

    /** @param array<string,mixed> $application */
    private static function requiredDocumentsSubmitted(array $application): ?string
    {
        $missing = Database::select(
            "SELECT rt.name
               FROM enrollment_requirements er
               JOIN requirement_types rt ON rt.id = er.requirement_type_id
              WHERE er.application_id = ?
                AND er.is_required = 1
                AND er.status NOT IN ('SUBMITTED', 'UNDER_REVIEW', 'VERIFIED')
              ORDER BY rt.sort_order",
            [$application['id']]
        );

        if ($missing === []) {
            return null;
        }

        return 'Upload the remaining required documents first: ' . implode(', ', array_column($missing, 'name')) . '.';
    }

    /** @param array<string,mixed> $application */
    private static function hasIncompleteRequirement(array $application): ?string
    {
        // A REQUIRED document that is missing, or ANY document the registrar has
        // turned back. An optional document nobody uploaded is not a reason to
        // return an application — every application has some of those.
        $count = (int) Database::scalar(
            "SELECT COUNT(*) FROM enrollment_requirements
              WHERE application_id = ?
                AND (status IN ('REJECTED', 'CORRECTION_REQUIRED')
                     OR (is_required = 1 AND status = 'NOT_SUBMITTED'))",
            [$application['id']]
        );

        return $count > 0
            ? null
            : 'Mark at least one document as rejected or needing correction before returning the application for incomplete requirements.';
    }

    /**
     * Rule 8: documents verified, subjects chosen, and a section assigned that
     * fits the application and still has a seat.
     *
     * @param array<string,mixed> $application
     */
    private static function readyToApprove(array $application): ?string
    {
        $problem = self::allRequiredVerified($application) ?? SubjectSelectionService::problem($application);
        if ($problem !== null) {
            return $problem;
        }
        if ($application['section_id'] === null) {
            return 'Assign a section before approving.';
        }
        $section = Database::selectOne('SELECT * FROM sections WHERE id = ?', [$application['section_id']]);
        return EnrollmentConfirmationService::sectionProblem($section, $application);
    }

    /** @param array<string,mixed> $application */
    private static function allRequiredVerified(array $application): ?string
    {
        $count = (int) Database::scalar(
            "SELECT COUNT(*) FROM enrollment_requirements
              WHERE application_id = ? AND is_required = 1 AND status <> 'VERIFIED'",
            [$application['id']]
        );

        return $count === 0 ? null : 'Every required document must be verified before the application can be approved.';
    }

    /**
     * Rule 12. An enrolled application cannot be cancelled either: that would
     * leave an enrollment record and student number behind a cancelled
     * application. Withdrawing an enrolled student is a separate "drop" process,
     * which is not part of v1.0 (docs/26 section 4).
     *
     * @param array<string,mixed> $application
     */
    private static function approvedCancellable(array $application): ?string
    {
        if ($application['enrollment_state'] !== 'NOT_ENROLLED') {
            return 'This student is already enrolled, so the application cannot be cancelled. Withdrawing an enrolled student is not supported yet.';
        }

        return in_array($application['payment_status'], ['NOT_ASSESSED', 'PENDING'], true)
            ? null
            : 'A payment has already been recorded, so this approved application cannot be cancelled.';
    }

    /** @param array<string,mixed> $application */
    private static function profileProblem(array $application): ?string
    {
        $missing = StudentProfileService::missingFields((int) $application['student_id']);

        return $missing === []
            ? null
            : 'Complete your profile first. Missing: ' . implode(', ', $missing) . '.';
    }

    private static function writeHistory(int $applicationId, string $axis, ?string $from, string $to, User $actor, ?string $remarks): void
    {
        Database::insert('enrollment_status_history', [
            'application_id' => $applicationId,
            'axis'           => $axis,
            'previous_value' => $from,
            'new_value'      => $to,
            'actor_user_id'  => $actor->id,
            'actor_role'     => (string) $actor->role_name,
            'remarks'        => ($remarks !== null && $remarks !== '') ? $remarks : null,
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
    }
}
