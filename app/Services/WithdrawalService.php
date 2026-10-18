<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Models\User;

/**
 * Withdrawing a student, and bringing them back (docs/26, "Withdrawal").
 *
 * The enrollment row is never deleted: its status becomes WITHDRAWN, with who, when, and why. The classes the student was
 * enlisted in are dropped (kept as DROPPED), which frees the section seat. Grades, attendance, fees, and payments are not
 * touched; a refund or reduction is a separate, manual cashier adjustment. The application's enrollment_state stays ENROLLED,
 * so the finance screens keep working, and the change is written to the status history under the axis "enrollment".
 */
final class WithdrawalService
{
    public const WITHDRAWN = 'WITHDRAWN';

    /** The prefix on the reason of every class dropped by a withdrawal, so reinstating restores exactly those. */
    private const DROP_PREFIX = 'Withdrawn: ';

    public static function withdraw(User $registrar, int $enrollmentId, string $reason): void
    {
        self::assertRegistrar($registrar);
        $reason = self::reason($reason, 'withdrawing this student');

        Database::transaction(static function () use ($registrar, $enrollmentId, $reason): void {
            $enrollment = self::lock($enrollmentId);
            if ($enrollment['status'] !== 'ENROLLED') {
                throw ValidationException::withMessage('Only an enrolled student can be withdrawn.');
            }

            $now = date('Y-m-d H:i:s');
            Database::update('enrollments', [
                'status' => self::WITHDRAWN, 'withdrawn_at' => $now, 'withdrawn_by' => $registrar->id, 'withdraw_reason' => $reason,
            ], ['id' => $enrollmentId]);

            $dropped = Database::query(
                "UPDATE class_enlistments SET status = 'DROPPED', dropped_at = ?, dropped_by = ?, drop_reason = ?
                  WHERE enrollment_id = ? AND status = 'ENLISTED'",
                [$now, $registrar->id, mb_substr(self::DROP_PREFIX . $reason, 0, 500), $enrollmentId]
            )->rowCount();
            EnlistmentService::recomputeUnits($enrollmentId);

            self::history((int) $enrollment['application_id'], 'ENROLLED', self::WITHDRAWN, $registrar, $reason);
            AuditService::log('enrollment.withdrawn', 'enrollment', 'Enrollment', $enrollmentId, ['status' => 'ENROLLED'],
                ['status' => self::WITHDRAWN, 'classes_dropped' => $dropped], 'SUCCESS', $reason, $registrar->id, $registrar->role_name);
            NotificationService::notify((int) $enrollment['owner_user_id'], 'enrollment.withdrawn', 'You were withdrawn from the term',
                'The registrar withdrew you from this term. Your record and balance stay on your account. Please see the registrar if this is a mistake.',
                '/student/enrollment/' . (int) $enrollment['application_id']);
        });
    }

    public static function reinstate(User $registrar, int $enrollmentId, string $reason): void
    {
        self::assertRegistrar($registrar);
        $reason = self::reason($reason, 'reinstating this student');

        Database::transaction(static function () use ($registrar, $enrollmentId, $reason): void {
            $enrollment = self::lock($enrollmentId);
            if ($enrollment['status'] !== self::WITHDRAWN) {
                throw ValidationException::withMessage('Only a withdrawn student can be reinstated.');
            }
            $problem = self::reinstateProblem($enrollment);
            if ($problem !== null) {
                throw ValidationException::withMessage($problem);
            }

            Database::query('SELECT id FROM sections WHERE id = ? FOR UPDATE', [$enrollment['section_id']]);
            Database::update('enrollments', ['status' => 'ENROLLED', 'withdrawn_at' => null, 'withdrawn_by' => null, 'withdraw_reason' => null], ['id' => $enrollmentId]);

            $restored = Database::query(
                "UPDATE class_enlistments SET status = 'ENLISTED', dropped_at = NULL, dropped_by = NULL, drop_reason = NULL
                  WHERE enrollment_id = ? AND status = 'DROPPED' AND drop_reason LIKE ?",
                [$enrollmentId, self::DROP_PREFIX . '%']
            )->rowCount();
            EnlistmentService::recomputeUnits($enrollmentId);

            self::history((int) $enrollment['application_id'], self::WITHDRAWN, 'ENROLLED', $registrar, $reason);
            AuditService::log('enrollment.reinstated', 'enrollment', 'Enrollment', $enrollmentId, ['status' => self::WITHDRAWN],
                ['status' => 'ENROLLED', 'classes_restored' => $restored], 'SUCCESS', $reason, $registrar->id, $registrar->role_name);
            NotificationService::notify((int) $enrollment['owner_user_id'], 'enrollment.reinstated', 'You are enrolled again',
                'The registrar reinstated your enrollment for this term.', '/student/enrollment/' . (int) $enrollment['application_id']);
        });
    }

    /** Why a withdrawn enrollment cannot be reinstated now, or null when it can. @param array<string,mixed> $enrollment */
    public static function reinstateProblem(array $enrollment): ?string
    {
        $year = Database::selectOne(
            'SELECT ay.is_active, ay.end_date FROM terms t JOIN academic_years ay ON ay.id = t.academic_year_id WHERE t.id = ?',
            [$enrollment['term_id']]
        );
        if ($year === null || (int) $year['is_active'] !== 1 || $year['end_date'] < date('Y-m-d')) {
            return 'That school year is no longer active, so the student cannot be reinstated.';
        }
        $application = Database::selectOne('SELECT * FROM enrollment_applications WHERE id = ?', [$enrollment['application_id']]);
        $section = Database::selectOne('SELECT * FROM sections WHERE id = ?', [$enrollment['section_id']]);

        return EnrollmentConfirmationService::sectionProblem($section, $application ?? []);
    }

    /** The student's most recent withdrawn enrollment, for the registrar's record page, or null. @return array<string,mixed>|null */
    public static function withdrawnFor(int $studentId): ?array
    {
        $row = Database::selectOne(
            "SELECT e.*, t.name AS term_name, ay.code AS academic_year_code, sec.code AS section_code,
                    CONCAT(u.first_name, ' ', u.last_name) AS withdrawn_by_name
               FROM enrollments e
               JOIN terms t           ON t.id  = e.term_id
               JOIN academic_years ay ON ay.id = t.academic_year_id
               JOIN sections sec      ON sec.id = e.section_id
               LEFT JOIN users u      ON u.id = e.withdrawn_by
              WHERE e.student_id = ? AND e.status = 'WITHDRAWN'
              ORDER BY t.start_date DESC
              LIMIT 1",
            [$studentId]
        );
        if ($row !== null) {
            $row['reinstate_problem'] = self::reinstateProblem($row);
        }

        return $row;
    }

    /** What the student still owes on this enrollment's application, in centavos, or null when nothing was assessed. */
    public static function balanceCents(int $applicationId): ?int
    {
        $assessment = \App\Models\Finance::assessmentForApplication($applicationId);
        if ($assessment === null) {
            return null;
        }

        return max(0, FinanceService::cents($assessment['balance']));
    }

    // -- Internals ---------------------------------------------------------------------

    /** @return array<string,mixed> the enrollment, locked, with its application and the student's user */
    private static function lock(int $enrollmentId): array
    {
        $enrollment = Database::selectOne(
            'SELECT e.*, s.user_id AS owner_user_id FROM enrollments e JOIN students s ON s.id = e.student_id WHERE e.id = ? FOR UPDATE',
            [$enrollmentId]
        );
        if ($enrollment === null) {
            throw HttpException::notFound('That enrollment was not found.');
        }

        return $enrollment;
    }

    private static function history(int $applicationId, string $from, string $to, User $actor, string $remarks): void
    {
        Database::insert('enrollment_status_history', [
            'application_id' => $applicationId, 'axis' => 'enrollment', 'previous_value' => $from, 'new_value' => $to,
            'actor_user_id' => $actor->id, 'actor_role' => (string) $actor->role_name, 'remarks' => $remarks, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private static function reason(string $reason, string $for): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withErrors(['reason' => ["Give the reason for {$for} (at least 5 characters)."]]);
        }

        return mb_substr($reason, 0, 480);
    }

    private static function assertRegistrar(User $user): void
    {
        if (!$user->isRegistrar() || !$user->hasPermission('students.manage')) {
            throw HttpException::forbidden('Only the registrar can withdraw or reinstate a student.');
        }
    }
}
