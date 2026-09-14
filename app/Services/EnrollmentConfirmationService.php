<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
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
     * @return array{enrollment_id:int,student_number:string,number_issued:bool}
     * @throws ValidationException|HttpException
     */
    public static function confirm(User $user, int $applicationId, int $sectionId): array
    {
        if (!$user->isRegistrar()) {
            throw HttpException::forbidden('Only the registrar can confirm enrollment.');
        }

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

            Database::update('students', [
                'student_number' => $studentNumber,
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

            EnrollmentStatusService::transitionEnrollment($applicationId, 'ENROLLED', $user);

            AuditService::log('enrollment.confirmed', 'enrollment', 'Enrollment', $enrollmentId, null, [
                'application_id' => $applicationId,
                'section_id'     => $sectionId,
                'student_number' => $studentNumber,
                'number_issued'  => $numberIssued,
            ], 'SUCCESS', null, $user->id, $user->role_name);

            NotificationService::notify(
                (int) $application['owner_user_id'],
                'enrollment.enrolled',
                "You are enrolled in section {$section['code']}",
                "Your student number is {$studentNumber}.",
                "/student/enrollment/{$applicationId}"
            );

            return ['enrollment_id' => $enrollmentId, 'student_number' => $studentNumber, 'number_issued' => $numberIssued];
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
