<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Models\Curriculum;
use App\Models\EnrollmentApplication as Application;
use App\Models\Student;
use App\Models\User;

/**
 * Enrollment confirmation (docs/26 section 4): the only place a student number
 * is issued and an enrollment record is created.
 *
 * Everything happens in one transaction. If any check fails — section full,
 * wrong section, payment policy not met — nothing is written: no enrollment
 * row, no student number, and the number sequence is not advanced.
 */
final class EnrollmentConfirmationService
{
    /**
     * @return array{enrollment_id:int,student_number:string,number_issued:bool,enlistment:array}
     * @throws ValidationException|HttpException
     */
    public static function confirm(User $user, int $applicationId, int $sectionId): array
    {
        if (!$user->isRegistrar()) {
            throw HttpException::forbidden('Only the registrar can confirm enrollment.');
        }

        return self::perform($user, $applicationId, $sectionId);
    }

    /**
     * Enroll automatically once everything is in place (docs/09): the application
     * is approved, a section was assigned at approval, and the payment policy is
     * met. Called after approval (policy NONE) and after every payment the cashier
     * records. Every check runs BEFORE anything is written, so a student who is not
     * ready yet — or whose section filled up — leaves no trace; in the second case
     * the registrar is told to assign another section.
     *
     * @return array{enrollment_id:int,student_number:string,number_issued:bool,enlistment:array}|null null when not enrolled now
     */
    public static function confirmIfReady(int $applicationId, User $actor): ?array
    {
        $application = Database::selectOne('SELECT * FROM enrollment_applications WHERE id = ?', [$applicationId]);
        if ($application === null
            || $application['application_status'] !== Application::APPROVED
            || $application['enrollment_state'] !== 'NOT_ENROLLED'
            || $application['section_id'] === null
            || PaymentPolicy::problem($application) !== null) {
            return null;
        }

        $section = Database::selectOne('SELECT * FROM sections WHERE id = ?', [$application['section_id']]);
        $sectionProblem = self::sectionProblem($section, $application);
        if ($sectionProblem !== null) {
            NotificationService::notifyRole(User::ROLE_REGISTRAR, 'enrollment.section_problem',
                "Application {$application['application_number']} is paid but could not be enrolled",
                $sectionProblem . ' Assign another section and confirm the enrollment.', "/registrar/applications/{$applicationId}/confirm");
            return null;
        }

        return self::perform($actor, $applicationId, (int) $application['section_id']);
    }

    /** @return array{enrollment_id:int,student_number:string,number_issued:bool,enlistment:array} */
    private static function perform(User $user, int $applicationId, int $sectionId): array
    {
        return Database::transaction(static function () use ($user, $applicationId, $sectionId): array {
            $application = Database::selectOne(
                'SELECT ea.*, s.user_id AS owner_user_id
                   FROM enrollment_applications ea
                   JOIN students s ON s.id = ea.student_id
                  WHERE ea.id = ?
                  FOR UPDATE',
                [$applicationId]
            );
            if ($application === null) {
                throw HttpException::notFound('That application was not found.');
            }
            if ($application['application_status'] !== Application::APPROVED) {
                throw ValidationException::withMessage('Only an approved application can be confirmed for enrollment.');
            }
            if ($application['enrollment_state'] !== 'NOT_ENROLLED') {
                throw ValidationException::withMessage('This application has already been confirmed.');
            }

            // Checked before any record is created so the most useful message wins;
            // transitionEnrollment() checks it again under the same lock.
            $paymentProblem = PaymentPolicy::problem($application);
            if ($paymentProblem !== null) {
                throw ValidationException::withMessage($paymentProblem);
            }

            // Lock the section so two confirmations cannot both take its last seat.
            $section = Database::selectOne('SELECT * FROM sections WHERE id = ? FOR UPDATE', [$sectionId]);
            $sectionProblem = self::sectionProblem($section, $application);
            if ($sectionProblem !== null) {
                throw ValidationException::withErrors(['section_id' => [$sectionProblem]], $sectionProblem);
            }

            $alreadyEnrolled = (int) Database::scalar(
                'SELECT COUNT(*) FROM enrollments WHERE student_id = ? AND term_id = ?',
                [$application['student_id'], $application['term_id']]
            ) > 0;
            if ($alreadyEnrolled) {
                throw ValidationException::withMessage('This student is already enrolled for that term.');
            }

            $student = Database::selectOne('SELECT * FROM students WHERE id = ? FOR UPDATE', [$application['student_id']]);

            $numberIssued = $student['student_number'] === null;
            $studentNumber = $numberIssued ? SequenceService::next('student') : (string) $student['student_number'];

            // A student keeps the curriculum they entered under. One is assigned
            // only on first enrollment, or when they move to another program.
            $curriculumId = $student['curriculum_id'] === null ? null : (int) $student['curriculum_id'];
            if ($curriculumId === null || (int) $student['program_id'] !== (int) $application['program_id']) {
                $current = Curriculum::currentForProgram((int) $application['program_id']);
                $curriculumId = $current === null ? null : (int) $current['id'];
            }

            Database::update('students', [
                'student_number' => $studentNumber,
                'curriculum_id'  => $curriculumId,
                'program_id'     => (int) $application['program_id'],
                'year_level_id'  => (int) $application['year_level_id'],
                'student_type'   => (string) $application['student_type'],
                'status'         => Student::STATUS_ENROLLED,
                'admitted_at'    => $student['admitted_at'] ?? date('Y-m-d'),
            ], ['id' => $student['id']]);

            $enrollmentId = Database::insert('enrollments', [
                'application_id' => $applicationId,
                'student_id'     => (int) $application['student_id'],
                'term_id'        => (int) $application['term_id'],
                'section_id'     => $sectionId,
                'enrolled_at'    => date('Y-m-d H:i:s'),
                'enrolled_by'    => $user->id,
                'status'         => 'ENROLLED',
            ]);

            // The section actually used, which the registrar may have changed at confirmation.
            Database::update('enrollment_applications', ['section_id' => $sectionId], ['id' => $applicationId]);

            EnrollmentStatusService::transitionEnrollment($applicationId, 'ENROLLED', $user);

            // Block section: enlist in the section's classes now. Anything that
            // can't be taken yet is skipped and reported, not forced.
            $enlistment = EnlistmentService::enlistBlock($enrollmentId, $user);

            AuditService::log('enrollment.confirmed', 'enrollment', 'Enrollment', $enrollmentId, null, [
                'application_id' => $applicationId,
                'section_id'     => $sectionId,
                'student_number' => $studentNumber,
                'number_issued'  => $numberIssued,
                'curriculum_id'  => $curriculumId,
            ], 'SUCCESS', null, $user->id, $user->role_name);

            NotificationService::notify(
                (int) $application['owner_user_id'],
                'enrollment.enrolled',
                "You are enrolled in section {$section['code']}",
                "Your student number is {$studentNumber}.",
                "/student/enrollment/{$applicationId}"
            );

            return [
                'enrollment_id'  => $enrollmentId,
                'student_number' => $studentNumber,
                'number_issued'  => $numberIssued,
                'enlistment'     => $enlistment,
            ];
        });
    }

    /**
     * Why a section cannot take this application, or null when it can.
     *
     * @param array<string,mixed>|null $section
     * @param array<string,mixed>      $application
     */
    public static function sectionProblem(?array $section, array $application): ?string
    {
        if ($section === null) {
            return 'Choose a section.';
        }
        if ((int) $section['is_active'] !== 1) {
            return "Section {$section['code']} is inactive.";
        }
        if ((int) $section['term_id'] !== (int) $application['term_id']
            || (int) $section['program_id'] !== (int) $application['program_id']
            || (int) $section['year_level_id'] !== (int) $application['year_level_id']) {
            return "Section {$section['code']} is not for this application's program, year level, and term.";
        }

        $enrolled = (int) Database::scalar(
            "SELECT COUNT(*) FROM enrollments WHERE section_id = ? AND status = 'ENROLLED'",
            [$section['id']]
        );
        if ($enrolled >= (int) $section['capacity']) {
            return "Section {$section['code']} is full ({$enrolled} of {$section['capacity']}).";
        }

        return null;
    }
}
