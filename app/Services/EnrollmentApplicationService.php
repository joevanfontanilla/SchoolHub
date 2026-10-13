<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\EnrollmentApplication as App;
use App\Models\RequirementType;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;

/**
 * The student's enrollment application (docs/09).
 *
 * Status changes are delegated to EnrollmentStatusService; this class never
 * writes a status column itself.
 */
final class EnrollmentApplicationService
{
    /**
     * Create a DRAFT application (docs/26 rule 1).
     *
     * @param array<string,mixed> $input
     * @return int the new application id
     * @throws ValidationException
     */
    public static function createDraft(User $user, array $input): int
    {
        $student = RegistrationService::ensureStudentRecord($user);

        return self::createDraftFor((int) $student['id'], $user, $input);
    }

    /**
     * Create a DRAFT application for a student. $actor is the student themselves, or the
     * registrar starting a walk-in (WalkInService), in which case $source is "walk_in".
     *
     * @param array<string,mixed> $input
     * @throws ValidationException
     */
    public static function createDraftFor(int $studentId, User $actor, array $input, string $source = 'online'): int
    {
        $validator = Validator::make($input, [
            'term_id'          => 'required|integer',
            'program_id'       => 'required|integer',
            'year_level_id'    => 'required|integer',
            'student_type'     => 'required|in:' . implode(',', Student::TYPES),
            'previous_school'  => 'nullable|max:200',
            'last_school_year' => 'nullable|max:20',
        ], [
            'term_id'       => 'Term',
            'program_id'    => 'Program',
            'year_level_id' => 'Year level',
        ]);
        if ($validator->fails()) {
            throw ValidationException::withErrors($validator->errors());
        }

        $term = Term::find((int) $input['term_id']);
        if ($term === null || !Term::isOpen($term)) {
            throw ValidationException::withErrors(['term_id' => ['Enrollment for that term is not open.']]);
        }

        $problem = self::formProblem($input);
        if ($problem !== null) {
            throw ValidationException::withMessage($problem);
        }

        return Database::transaction(static function () use ($actor, $studentId, $term, $input, $source): int {
            // Re-checked inside the transaction with the student row locked, so two
            // quick submissions of the same form cannot both create an application.
            Database::selectOne('SELECT id FROM students WHERE id = ? FOR UPDATE', [$studentId]);

            if (App::hasOpenApplicationForTerm($studentId, (int) $term['id'])) {
                throw ValidationException::withMessage(
                    'You already have an application for ' . Term::label($term) . '. Continue that one, or cancel it first.'
                );
            }

            $applicationId = Database::insert('enrollment_applications', [
                'application_number' => SequenceService::next('application', 'APP'),
                'student_id'         => $studentId,
                'academic_year_id'   => (int) $term['academic_year_id'],
                'term_id'            => (int) $term['id'],
                'program_id'         => (int) $input['program_id'],
                'year_level_id'      => (int) $input['year_level_id'],
                'student_type'       => (string) $input['student_type'],
                'source'             => $source,
                'created_by'         => $source === 'online' ? null : $actor->id,
                'previous_school'    => trim((string) ($input['previous_school'] ?? '')) ?: null,
                'last_school_year'   => trim((string) ($input['last_school_year'] ?? '')) ?: null,
            ]);

            // Snapshot the document list for this student type and term.
            foreach (RequirementType::forApplication((int) $term['id'], (string) $input['student_type']) as $type) {
                Database::insert('enrollment_requirements', [
                    'application_id'      => $applicationId,
                    'requirement_type_id' => (int) $type['id'],
                    'is_required'         => (int) $type['effective_required'],
                    'status'              => App::REQ_NOT_SUBMITTED,
                ]);
            }

            Database::update('students', ['student_type' => (string) $input['student_type']], ['id' => $studentId]);

            // Pre-check the regular load: this program's subjects for the year and term.
            SubjectSelectionService::selectDefaults($applicationId);

            EnrollmentStatusService::recordCreation($applicationId, $actor);

            AuditService::log('enrollment.application_created', 'enrollment', 'EnrollmentApplication', $applicationId, null, [
                'term_id'      => (int) $term['id'],
                'program_id'   => (int) $input['program_id'],
                'student_type' => (string) $input['student_type'],
            ], 'SUCCESS', null, $actor->id, $actor->role_name);

            return $applicationId;
        });
    }

    /**
     * Edit the form while it is unlocked (DRAFT, or CORRECTION_REQUIRED).
     *
     * The term and student type are fixed once the draft exists: they decide
     * which documents are required, and changing them under uploaded files would
     * leave the document list inconsistent. To change either, cancel the draft
     * and start a new one.
     *
     * @param array<string,mixed> $input
     */
    public static function updateDraft(User $user, int $applicationId, array $input): void
    {
        $application = self::ownedApplication($user, $applicationId);

        if (!in_array($application['application_status'], App::EDITS_UNLOCKED, true)) {
            throw ValidationException::withMessage(
                'This application is ' . strtolower(App::label($application['application_status'])) . ' and cannot be edited now.'
            );
        }

        $validator = Validator::make($input, [
            'program_id'       => 'required|integer',
            'year_level_id'    => 'required|integer',
            'previous_school'  => 'nullable|max:200',
            'last_school_year' => 'nullable|max:20',
        ], ['program_id' => 'Program', 'year_level_id' => 'Year level']);
        if ($validator->fails()) {
            throw ValidationException::withErrors($validator->errors());
        }

        $candidate = array_merge($application, [
            'program_id'      => (int) $input['program_id'],
            'year_level_id'   => (int) $input['year_level_id'],
            'previous_school' => trim((string) ($input['previous_school'] ?? '')),
        ]);
        $problem = self::formProblem($candidate);
        if ($problem !== null) {
            throw ValidationException::withMessage($problem);
        }

        Database::update('enrollment_applications', [
            'program_id'       => (int) $input['program_id'],
            'year_level_id'    => (int) $input['year_level_id'],
            'previous_school'  => trim((string) ($input['previous_school'] ?? '')) ?: null,
            'last_school_year' => trim((string) ($input['last_school_year'] ?? '')) ?: null,
        ], ['id' => $applicationId]);

        // A different program or year level means a different subject list.
        if ((int) $application['program_id'] !== (int) $input['program_id'] || (int) $application['year_level_id'] !== (int) $input['year_level_id']) {
            SubjectSelectionService::selectDefaults($applicationId);
        }

        AuditService::log('enrollment.application_updated', 'enrollment', 'EnrollmentApplication', $applicationId, [
            'program_id'    => (int) $application['program_id'],
            'year_level_id' => (int) $application['year_level_id'],
        ], [
            'program_id'    => (int) $input['program_id'],
            'year_level_id' => (int) $input['year_level_id'],
        ], 'SUCCESS', null, $user->id, $user->role_name);
    }

    /** Submit, or resubmit after the registrar returned it (docs/26 rules 2, 6, 7). */
    public static function submit(User $user, int $applicationId): void
    {
        self::ownedApplication($user, $applicationId);
        EnrollmentStatusService::transition($applicationId, App::SUBMITTED, $user);
    }

    /**
     * The registrar approves (docs/26 rule 8), placing the student in a section.
     * The section is saved and the approval made in one transaction, so a refused
     * approval leaves the section unchanged too.
     */
    public static function approve(User $registrar, int $applicationId, int $sectionId, ?string $remarks = null): void
    {
        if (!$registrar->isRegistrar()) {
            throw HttpException::forbidden('Only the registrar can approve applications.');
        }

        Database::transaction(static function () use ($registrar, $applicationId, $sectionId, $remarks): void {
            $application = Database::selectOne('SELECT * FROM enrollment_applications WHERE id = ? FOR UPDATE', [$applicationId]);
            if ($application === null) {
                throw HttpException::notFound('That application was not found.');
            }
            $section = Database::selectOne('SELECT * FROM sections WHERE id = ?', [$sectionId]);
            $problem = EnrollmentConfirmationService::sectionProblem($section, $application);
            if ($problem !== null) {
                throw ValidationException::withErrors(['section_id' => [$problem]], $problem);
            }

            Database::update('enrollment_applications', ['section_id' => $sectionId], ['id' => $applicationId]);
            EnrollmentStatusService::transition($applicationId, App::APPROVED, $registrar, $remarks);
        });
    }

    /** Withdraw the application (docs/26 rules 10, 11). */
    public static function cancel(User $user, int $applicationId, ?string $remarks = null): void
    {
        self::ownedApplication($user, $applicationId);
        EnrollmentStatusService::transition($applicationId, App::CANCELLED, $user, $remarks);
    }

    /**
     * Problems with the application form itself, or null when it is valid.
     * Used on create, on edit, and again as a precondition at submission.
     *
     * @param array<string,mixed> $form
     */
    public static function formProblem(array $form): ?string
    {
        $programActive = (int) Database::scalar(
            'SELECT COUNT(*) FROM programs WHERE id = ? AND is_active = 1',
            [(int) ($form['program_id'] ?? 0)]
        ) > 0;
        if (!$programActive) {
            return 'Please choose a program that is currently offered.';
        }

        $yearLevelExists = (int) Database::scalar(
            'SELECT COUNT(*) FROM year_levels WHERE id = ?',
            [(int) ($form['year_level_id'] ?? 0)]
        ) > 0;
        if (!$yearLevelExists) {
            return 'Please choose a valid year level.';
        }

        if (($form['student_type'] ?? '') === 'Transferee' && trim((string) ($form['previous_school'] ?? '')) === '') {
            return 'Transferees must give the name of the school they are transferring from.';
        }

        return null;
    }

    /**
     * The application, if it belongs to this user. Otherwise 404 — never 403,
     * so the response does not confirm the application exists.
     *
     * @return array<string,mixed>
     */
    public static function ownedApplication(User $user, int $applicationId): array
    {
        $student = RegistrationService::ensureStudentRecord($user);
        $application = App::findForStudent($applicationId, (int) $student['id']);

        if ($application === null) {
            throw HttpException::notFound('That application was not found.');
        }

        return $application;
    }
}
