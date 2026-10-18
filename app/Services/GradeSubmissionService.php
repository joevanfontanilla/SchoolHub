<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Models\User;

/**
 * Releasing grades (docs/27): a teacher submits one class's grades for one period, and a student sees them only after the registrar
 * confirms. A submission is DRAFT while the teacher enters grades, SUBMITTED while it waits, CONFIRMED once released, and RETURNED when
 * the registrar sends it back. Every transition is audited.
 */
final class GradeSubmissionService
{
    public const DRAFT     = 'DRAFT';
    public const SUBMITTED = 'SUBMITTED';
    public const CONFIRMED = 'CONFIRMED';
    public const RETURNED  = 'RETURNED';

    /**
     * An SQL condition on a `grades g` row: the grade is visible to a student when no submission holds it back, that is, no submission
     * exists for its class and period, or the one that does is CONFIRMED. A grade saved before this step existed has no submission.
     */
    public const VISIBLE_TO_STUDENT = "NOT EXISTS (SELECT 1 FROM class_enlistments vce
        JOIN grade_submissions vgs ON vgs.class_schedule_id = vce.class_schedule_id
        WHERE vce.id = g.class_enlistment_id AND vgs.grade_period_id = g.grade_period_id AND vgs.status <> 'CONFIRMED')";

    // -- Reading -------------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne(
            "SELECT gs.*, gp.name AS period_name, gp.term_id, gp.is_open, cs.teacher_user_id, sub.code AS subject_code, sub.title AS subject_title,
                    sec.code AS section_code, CONCAT(t.first_name, ' ', t.last_name) AS teacher_name
               FROM grade_submissions gs
               JOIN grade_periods gp ON gp.id = gs.grade_period_id
               JOIN class_schedules cs ON cs.id = gs.class_schedule_id
               JOIN subjects sub ON sub.id = cs.subject_id
               JOIN sections sec ON sec.id = cs.section_id
               JOIN users t ON t.id = cs.teacher_user_id
              WHERE gs.id = ?",
            [$id]
        );
    }

    /** The status of one class's period: DRAFT when nothing was saved yet. */
    public static function statusOf(int $classId, int $periodId): string
    {
        return (string) (Database::scalar('SELECT status FROM grade_submissions WHERE class_schedule_id = ? AND grade_period_id = ?', [$classId, $periodId]) ?? self::DRAFT);
    }

    /** @return array<int,array<string,mixed>> a class's submissions keyed by period id */
    public static function forClass(int $classId): array
    {
        $rows = [];
        foreach (Database::select('SELECT * FROM grade_submissions WHERE class_schedule_id = ?', [$classId]) as $row) {
            $rows[(int) $row['grade_period_id']] = $row;
        }

        return $rows;
    }

    /**
     * For a student's classes: which periods are submitted and waiting for the registrar ("Pending release"), keyed by class id.
     *
     * @param array<int,int> $classIds
     * @return array<int,array<int,true>>
     */
    public static function submittedPeriods(array $classIds): array
    {
        if ($classIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($classIds), '?'));
        $result = [];
        foreach (Database::select("SELECT class_schedule_id, grade_period_id FROM grade_submissions WHERE status = 'SUBMITTED' AND class_schedule_id IN ({$in})", $classIds) as $row) {
            $result[(int) $row['class_schedule_id']][(int) $row['grade_period_id']] = true;
        }

        return $result;
    }

    /**
     * The registrar's list, newest submission first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function listing(string $status = self::SUBMITTED): array
    {
        $where = in_array($status, [self::DRAFT, self::SUBMITTED, self::CONFIRMED, self::RETURNED], true) ? 'gs.status = ?' : '1 = 1';

        return Database::select(
            "SELECT gs.id, gs.status, gs.submitted_at, gs.confirmed_at, gs.returned_at, gs.return_reason, gp.name AS period_name,
                    sub.code AS subject_code, sub.title AS subject_title, sec.code AS section_code,
                    CONCAT(t.first_name, ' ', t.last_name) AS teacher_name,
                    (SELECT COUNT(*) FROM grades g JOIN class_enlistments ce ON ce.id = g.class_enlistment_id
                      WHERE ce.class_schedule_id = gs.class_schedule_id AND g.grade_period_id = gs.grade_period_id) AS grade_count
               FROM grade_submissions gs
               JOIN grade_periods gp ON gp.id = gs.grade_period_id
               JOIN class_schedules cs ON cs.id = gs.class_schedule_id
               JOIN subjects sub ON sub.id = cs.subject_id
               JOIN sections sec ON sec.id = cs.section_id
               JOIN users t ON t.id = cs.teacher_user_id
              WHERE {$where}
              ORDER BY COALESCE(gs.submitted_at, gs.updated_at) DESC, gs.id DESC",
            $where === '1 = 1' ? [] : [$status]
        );
    }

    public static function countSubmitted(): int
    {
        return (int) Database::scalar("SELECT COUNT(*) FROM grade_submissions WHERE status = 'SUBMITTED'");
    }

    /** The grades of a submission, read-only for the registrar. @return array<int,array<string,mixed>> */
    public static function sheet(int $classId, int $periodId): array
    {
        return Database::select(
            "SELECT ce.id AS enlistment_id, st.student_number, u.last_name, u.first_name, g.grade, g.percentage, g.remark
               FROM class_enlistments ce
               JOIN enrollments e ON e.id = ce.enrollment_id
               JOIN students st ON st.id = e.student_id
               JOIN users u ON u.id = st.user_id
          LEFT JOIN grades g ON g.class_enlistment_id = ce.id AND g.grade_period_id = ?
              WHERE ce.class_schedule_id = ? AND ce.status = 'ENLISTED'
              ORDER BY u.last_name, u.first_name",
            [$periodId, $classId]
        );
    }

    // -- Entering (used by GradingService::recordGrades) -----------------------------------

    /**
     * Whether a teacher may enter grades for this class and period now, and why not. A returned sheet is open to the teacher even after
     * the period closed; a draft needs the period open; a submitted or confirmed sheet is locked.
     *
     * @param array<string,mixed> $period
     * @throws ValidationException
     */
    public static function assertEditable(string $status, array $period): void
    {
        if ($status === self::SUBMITTED) {
            throw ValidationException::withMessage('These grades were submitted to the registrar and are locked. Ask the registrar to return them if one must change.');
        }
        if ($status === self::CONFIRMED) {
            throw ValidationException::withMessage('These grades were confirmed and released to students. Ask the registrar to return them if one must change.');
        }
        if ($status !== self::RETURNED && (int) $period['is_open'] !== 1) {
            throw ValidationException::withMessage("{$period['name']} grading is closed. Ask the registrar to reopen it if a grade must change.");
        }
    }

    /** Make sure the class and period have a submission row (a DRAFT), so the grades are held back from students from the first save. */
    public static function ensureDraft(int $classId, int $periodId): void
    {
        Database::query(
            "INSERT INTO grade_submissions (class_schedule_id, grade_period_id, status) VALUES (?, ?, 'DRAFT') ON DUPLICATE KEY UPDATE id = id",
            [$classId, $periodId]
        );
    }

    // -- Transitions -----------------------------------------------------------------------

    /** The teacher sends a complete sheet to the registrar. @throws ValidationException|HttpException */
    public static function submit(User $teacher, int $classId, int $periodId): void
    {
        $class = GradingService::ownedClass($teacher, $classId);

        Database::transaction(static function () use ($teacher, $class, $periodId): void {
            $period = Database::selectOne('SELECT * FROM grade_periods WHERE id = ? FOR UPDATE', [$periodId]);
            if ($period === null || (int) $period['term_id'] !== (int) $class['term_id']) {
                throw HttpException::notFound('That grading period was not found for this class.');
            }
            self::ensureDraft((int) $class['id'], $periodId);
            $submission = Database::selectOne('SELECT * FROM grade_submissions WHERE class_schedule_id = ? AND grade_period_id = ? FOR UPDATE', [$class['id'], $periodId]);
            if ($submission['status'] === self::SUBMITTED || $submission['status'] === self::CONFIRMED) {
                throw ValidationException::withMessage('These grades were already submitted.');
            }
            self::assertEditable((string) $submission['status'], $period);

            // A grade or a mark for every enlisted student, so nobody is left blank by accident.
            $missing = Database::select(
                "SELECT u.last_name, u.first_name
                   FROM class_enlistments ce
                   JOIN enrollments e ON e.id = ce.enrollment_id
                   JOIN students st ON st.id = e.student_id
                   JOIN users u ON u.id = st.user_id
              LEFT JOIN grades g ON g.class_enlistment_id = ce.id AND g.grade_period_id = ?
                  WHERE ce.class_schedule_id = ? AND ce.status = 'ENLISTED' AND g.id IS NULL
                  ORDER BY u.last_name, u.first_name",
                [$periodId, $class['id']]
            );
            $enlisted = (int) Database::scalar("SELECT COUNT(*) FROM class_enlistments WHERE class_schedule_id = ? AND status = 'ENLISTED'", [$class['id']]);
            if ($enlisted === 0) {
                throw ValidationException::withMessage('No students are enlisted in this class, so there is nothing to submit.');
            }
            if ($missing !== []) {
                $names = array_map(static fn (array $row): string => $row['last_name'] . ', ' . $row['first_name'], array_slice($missing, 0, 5));
                throw ValidationException::withMessage(count($missing) . (count($missing) === 1 ? ' student has' : ' students have') . ' no grade or mark yet: '
                    . implode('; ', $names) . (count($missing) > 5 ? '; and more' : '') . '. Enter one for everyone, then submit.');
            }

            Database::update('grade_submissions', [
                'status' => self::SUBMITTED, 'submitted_by' => $teacher->id, 'submitted_at' => date('Y-m-d H:i:s'),
            ], ['id' => $submission['id']]);
            AuditService::log('grades.submitted', 'grades', 'GradeSubmission', (int) $submission['id'], ['status' => $submission['status']],
                ['status' => self::SUBMITTED, 'class' => $class['code'], 'section' => $class['section_code'], 'period' => $period['name']],
                'SUCCESS', null, $teacher->id, $teacher->role_name);
            NotificationService::notifyRole(User::ROLE_REGISTRAR, 'grades.submitted',
                "{$period['name']} grades submitted for {$class['code']}",
                "{$class['code']} · {$class['section_code']}: {$enlisted} student" . ($enlisted === 1 ? '' : 's') . '. Review and confirm them so students can see them.',
                '/registrar/grading/submissions/' . (int) $submission['id']);
        });
    }

    /** Confirm one submission: its grades are released to students. @throws ValidationException|HttpException */
    public static function confirm(User $registrar, int $submissionId): void
    {
        self::assertRegistrar($registrar);

        Database::transaction(static function () use ($registrar, $submissionId): void {
            $submission = self::lockedSubmission($submissionId);
            if ($submission['status'] !== self::SUBMITTED) {
                throw ValidationException::withMessage('Only a submitted sheet can be confirmed.');
            }
            $details = self::find($submissionId);

            Database::update('grade_submissions', [
                'status' => self::CONFIRMED, 'confirmed_by' => $registrar->id, 'confirmed_at' => date('Y-m-d H:i:s'),
            ], ['id' => $submissionId]);
            AuditService::log('grades.confirmed', 'grades', 'GradeSubmission', $submissionId, ['status' => self::SUBMITTED],
                ['status' => self::CONFIRMED, 'class' => $details['subject_code'], 'section' => $details['section_code'], 'period' => $details['period_name']],
                'SUCCESS', null, $registrar->id, $registrar->role_name);

            NotificationService::notify((int) $details['teacher_user_id'], 'grades.confirmed',
                "{$details['period_name']} grades confirmed for {$details['subject_code']}",
                'The registrar confirmed them. Students can see them now.', '/teacher/classes/' . (int) $submission['class_schedule_id'] . '/grades?period=' . (int) $submission['grade_period_id']);

            // The students who have a grade in it.
            foreach (Database::select(
                'SELECT DISTINCT s.user_id FROM grades g
                   JOIN class_enlistments ce ON ce.id = g.class_enlistment_id
                   JOIN enrollments e        ON e.id = ce.enrollment_id
                   JOIN students s           ON s.id = e.student_id
                  WHERE ce.class_schedule_id = ? AND g.grade_period_id = ?',
                [$submission['class_schedule_id'], $submission['grade_period_id']]
            ) as $student) {
                NotificationService::notify((int) $student['user_id'], 'grades.released', "{$details['period_name']} grades are available", null, '/student/grades');
            }
        });
    }

    /**
     * Confirm several at once; a sheet that is not submitted any more is skipped.
     *
     * @param array<int,int|string> $ids
     * @return int how many were confirmed
     */
    public static function confirmMany(User $registrar, array $ids): int
    {
        self::assertRegistrar($registrar);
        $confirmed = 0;
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            try {
                self::confirm($registrar, $id);
                $confirmed++;
            } catch (ValidationException | HttpException) {
                // Already confirmed, returned, or gone: nothing to do for this one.
            }
        }

        return $confirmed;
    }

    /** Send a submitted or confirmed sheet back to the teacher, hidden from students again. @throws ValidationException|HttpException */
    public static function returnToTeacher(User $registrar, int $submissionId, string $reason): void
    {
        self::assertRegistrar($registrar);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withErrors(['reason' => ['Give the reason for returning these grades (at least 5 characters).']]);
        }

        Database::transaction(static function () use ($registrar, $submissionId, $reason): void {
            $submission = self::lockedSubmission($submissionId);
            if (!in_array($submission['status'], [self::SUBMITTED, self::CONFIRMED], true)) {
                throw ValidationException::withMessage('Only a submitted or confirmed sheet can be returned.');
            }
            $details = self::find($submissionId);

            Database::update('grade_submissions', [
                'status' => self::RETURNED, 'returned_by' => $registrar->id, 'returned_at' => date('Y-m-d H:i:s'), 'return_reason' => mb_substr($reason, 0, 500),
            ], ['id' => $submissionId]);
            AuditService::log('grades.returned', 'grades', 'GradeSubmission', $submissionId, ['status' => $submission['status']],
                ['status' => self::RETURNED, 'class' => $details['subject_code'], 'section' => $details['section_code'], 'period' => $details['period_name']],
                'SUCCESS', $reason, $registrar->id, $registrar->role_name);
            NotificationService::notify((int) $details['teacher_user_id'], 'grades.returned',
                "{$details['period_name']} grades returned for {$details['subject_code']}",
                'Reason: ' . $reason, '/teacher/classes/' . (int) $submission['class_schedule_id'] . '/grades?period=' . (int) $submission['grade_period_id']);
        });
    }

    // -- Internals -------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private static function lockedSubmission(int $id): array
    {
        $row = Database::selectOne('SELECT * FROM grade_submissions WHERE id = ? FOR UPDATE', [$id]);
        if ($row === null) {
            throw HttpException::notFound('That grade submission was not found.');
        }

        return $row;
    }

    private static function assertRegistrar(User $user): void
    {
        if (!$user->isRegistrar() || !$user->hasPermission('grade_periods.manage')) {
            throw HttpException::forbidden('Only the registrar confirms grades.');
        }
    }
}
