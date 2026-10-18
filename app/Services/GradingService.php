<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Models\User;

/**
 * Grading periods and grade entry (docs/12, docs/27).
 *
 * - The registrar opens and closes periods and sets their weights.
 * - A teacher records grades only for a class assigned to them, only while
 *   that period is open, only for students enlisted in that class.
 * - The registrar cannot enter or change grades (docs/04). A correction after
 *   a period closes means reopening it, which is audited, and the teacher
 *   making the change, which is audited with the old and new value.
 */
final class GradingService
{
    /** The grading periods every term has, closed to start: [name, sequence, default weight]. */
    public const DEFAULT_PERIODS = [['Prelim', 1, 20], ['Midterm', 2, 20], ['Semi-Finals', 3, 20], ['Finals', 4, 40]];

    /**
     * Create the standard closed grading periods for a term, if it has none yet. Used by the
     * seeder and when the registrar adds a new academic year. Returns how many were created.
     */
    public static function seedPeriods(int $termId): int
    {
        if ((int) Database::scalar('SELECT COUNT(*) FROM grade_periods WHERE term_id = ?', [$termId]) > 0) {
            return 0;
        }
        foreach (self::DEFAULT_PERIODS as [$name, $sequence, $weight]) {
            Database::insert('grade_periods', [
                'term_id' => $termId, 'name' => $name, 'sequence' => $sequence, 'weight' => $weight, 'is_open' => 0,
            ]);
        }
        return count(self::DEFAULT_PERIODS);
    }

    // -- Periods (registrar) ---------------------------------------------------

    public static function openPeriod(User $registrar, int $periodId): void
    {
        self::togglePeriod($registrar, $periodId, true);
    }

    public static function closePeriod(User $registrar, int $periodId): void
    {
        self::togglePeriod($registrar, $periodId, false);
    }

    /** @param array<int|string,mixed> $weights period id => percent */
    public static function setWeights(User $registrar, int $termId, array $weights): void
    {
        self::assertRegistrar($registrar);

        Database::transaction(static function () use ($registrar, $termId, $weights): void {
            $periods = Database::select('SELECT * FROM grade_periods WHERE term_id = ? ORDER BY sequence FOR UPDATE', [$termId]);
            if ($periods === []) {
                throw HttpException::notFound('That term has no grading periods.');
            }

            $clean = [];
            foreach ($periods as $period) {
                $clean[(int) $period['id']] = $weights[$period['id']] ?? $weights[(string) $period['id']] ?? null;
            }
            try {
                GradeCalculator::assertWeights($clean);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withErrors(['weights' => [$e->getMessage()]], $e->getMessage());
            }

            $graded = (int) Database::scalar(
                'SELECT COUNT(*) FROM grades g JOIN grade_periods gp ON gp.id = g.grade_period_id WHERE gp.term_id = ?',
                [$termId]
            );
            if ($graded > 0) {
                throw ValidationException::withMessage('Grades have already been recorded for this term, so its weights can no longer change.');
            }

            $old = [];
            foreach ($periods as $period) {
                $old[$period['name']] = (float) $period['weight'];
                Database::update('grade_periods', ['weight' => number_format((float) $clean[(int) $period['id']], 2, '.', '')], ['id' => $period['id']]);
            }
            $new = [];
            foreach ($periods as $period) {
                $new[$period['name']] = (float) $clean[(int) $period['id']];
            }

            AuditService::log('grading.weights_changed', 'academic', 'Term', $termId, $old, $new, 'SUCCESS', null, $registrar->id, $registrar->role_name);
        });
    }

    /**
     * Give a term that lacks it a Semi-Finals period, between Midterm and Finals, closed, with the standard 20/20/20/40 weights.
     * Refused when a student in the term already has a complete final (every existing period graded), because adding a period
     * would change it; grades already entered are never touched.
     */
    public static function addSemiFinals(User $registrar, int $termId): void
    {
        self::assertRegistrar($registrar);

        Database::transaction(static function () use ($registrar, $termId): void {
            $periods = Database::select('SELECT * FROM grade_periods WHERE term_id = ? ORDER BY sequence FOR UPDATE', [$termId]);
            if ($periods === []) {
                throw HttpException::notFound('That term has no grading periods.');
            }
            $names = array_column($periods, 'name');
            if (in_array('Semi-Finals', $names, true)) {
                throw ValidationException::withMessage('This term already has a Semi-Finals period.');
            }
            if ($names !== ['Prelim', 'Midterm', 'Finals']) {
                throw ValidationException::withMessage('This term has custom grading periods, so Semi-Finals cannot be added automatically.');
            }

            $complete = (int) Database::scalar(
                'SELECT COUNT(*) FROM (
                    SELECT ce.id FROM class_enlistments ce
                      JOIN class_schedules cs ON cs.id = ce.class_schedule_id
                      JOIN grades g ON g.class_enlistment_id = ce.id
                     WHERE cs.term_id = ?
                     GROUP BY ce.id HAVING COUNT(*) >= ?
                 ) complete',
                [$termId, count($periods)]
            );
            if ($complete > 0) {
                throw ValidationException::withMessage('A student in this term already has a complete final grade. Adding a period would change it, so Semi-Finals cannot be added.');
            }

            $old = [];
            foreach ($periods as $period) {
                $old[$period['name']] = (float) $period['weight'];
            }
            $finals = $periods[2];
            Database::update('grade_periods', ['sequence' => 4, 'weight' => '40.00'], ['id' => $finals['id']]);
            Database::insert('grade_periods', ['term_id' => $termId, 'name' => 'Semi-Finals', 'sequence' => 3, 'weight' => '20.00', 'is_open' => 0]);
            Database::update('grade_periods', ['weight' => '20.00'], ['id' => $periods[0]['id']]);
            Database::update('grade_periods', ['weight' => '20.00'], ['id' => $periods[1]['id']]);

            AuditService::log('grading.period_added', 'academic', 'Term', $termId, $old,
                ['Prelim' => 20.0, 'Midterm' => 20.0, 'Semi-Finals' => 20.0, 'Finals' => 40.0], 'SUCCESS', 'Semi-Finals added', $registrar->id, $registrar->role_name);
        });
    }

    // -- Grade entry (teacher) -------------------------------------------------

    /**
     * Record grades for one period of one class. All-or-nothing: if any entry is
     * invalid, nothing is saved and the errors are keyed by enlistment.
     *
     * @param array<int|string,mixed> $entries enlistment id => value ('1.75', 'INC', or '' to leave unchanged)
     * @return array{saved:int,changed:int}
     */
    public static function recordGrades(User $teacher, int $classId, int $periodId, array $entries): array
    {
        $class = self::ownedClass($teacher, $classId);

        return Database::transaction(static function () use ($teacher, $class, $periodId, $entries): array {
            // Lock the period: a close cannot slip in between the check and the save.
            $period = Database::selectOne('SELECT * FROM grade_periods WHERE id = ? FOR UPDATE', [$periodId]);
            if ($period === null || (int) $period['term_id'] !== (int) $class['term_id']) {
                throw HttpException::notFound('That grading period was not found for this class.');
            }
            if ((int) $period['is_open'] !== 1) {
                throw ValidationException::withMessage("{$period['name']} grading is closed. Ask the registrar to reopen it if a grade must change.");
            }

            $enlisted = [];
            foreach (Database::select("SELECT id FROM class_enlistments WHERE class_schedule_id = ? AND status = 'ENLISTED'", [$class['id']]) as $row) {
                $enlisted[(int) $row['id']] = true;
            }

            $errors = [];
            $parsed = [];
            foreach ($entries as $enlistmentId => $value) {
                $enlistmentId = (int) $enlistmentId;
                if (trim((string) $value) === '') {
                    continue;
                }
                if (!isset($enlisted[$enlistmentId])) {
                    $errors["grades.{$enlistmentId}"] = ['That student is not enlisted in this class.'];
                    continue;
                }
                $grade = GradeCalculator::parse($value);
                if ($grade === null) {
                    $errors["grades.{$enlistmentId}"] = ['"' . mb_substr((string) $value, 0, 10) . '" is not a valid grade. Use 1.00–3.00 in steps of 0.25, 5.00, INC, or DRP.'];
                    continue;
                }
                $parsed[$enlistmentId] = $grade;
            }

            if ($errors !== []) {
                throw ValidationException::withErrors($errors, 'Some grades were not valid, so nothing was saved.');
            }

            $saved = 0;
            $changed = 0;
            $now = date('Y-m-d H:i:s');

            foreach ($parsed as $enlistmentId => $grade) {
                $existing = Database::selectOne(
                    'SELECT * FROM grades WHERE class_enlistment_id = ? AND grade_period_id = ? FOR UPDATE',
                    [$enlistmentId, $periodId]
                );

                if ($existing === null) {
                    $gradeId = Database::insert('grades', [
                        'class_enlistment_id' => $enlistmentId,
                        'grade_period_id'     => $periodId,
                        'grade'               => $grade['grade'],
                        'remark'              => $grade['remark'],
                        'recorded_by'         => $teacher->id,
                        'recorded_at'         => $now,
                    ]);
                    AuditService::log('grade.recorded', 'grades', 'Grade', $gradeId, null, [
                        'enlistment_id' => $enlistmentId, 'period' => $period['name'],
                        'grade' => $grade['grade'], 'remark' => $grade['remark'],
                    ], 'SUCCESS', null, $teacher->id, $teacher->role_name);
                    $saved++;
                    continue;
                }

                if ($existing['grade'] === $grade['grade'] && $existing['remark'] === $grade['remark']) {
                    continue; // unchanged
                }

                Database::update('grades', [
                    'grade'      => $grade['grade'],
                    'remark'     => $grade['remark'],
                    'changed_by' => $teacher->id,
                    'changed_at' => $now,
                ], ['id' => $existing['id']]);

                // docs/12: grade changes are auditable — old and new value.
                AuditService::log('grade.changed', 'grades', 'Grade', (int) $existing['id'], [
                    'grade' => $existing['grade'], 'remark' => $existing['remark'],
                ], [
                    'enlistment_id' => $enlistmentId, 'period' => $period['name'],
                    'grade' => $grade['grade'], 'remark' => $grade['remark'],
                ], 'SUCCESS', null, $teacher->id, $teacher->role_name);
                $changed++;
            }

            return ['saved' => $saved, 'changed' => $changed];
        });
    }

    /**
     * The class, if it is assigned to this teacher. Any other class is a 404, so a
     * teacher cannot even confirm that another teacher's class exists.
     *
     * @return array<string,mixed>
     */
    public static function ownedClass(User $teacher, int $classId): array
    {
        if (!$teacher->isTeacher()) {
            throw HttpException::forbidden('Only the class teacher can record grades and attendance.');
        }

        $class = Database::selectOne(
            "SELECT cs.*, sub.code, sub.title, sub.units, sec.code AS section_code
               FROM class_schedules cs
               JOIN subjects sub ON sub.id = cs.subject_id
               JOIN sections sec ON sec.id = cs.section_id
              WHERE cs.id = ? AND cs.teacher_user_id = ? AND cs.status = 'ACTIVE'",
            [$classId, $teacher->id]
        );
        if ($class === null) {
            throw HttpException::notFound('That class was not found.');
        }

        return $class;
    }

    private static function togglePeriod(User $registrar, int $periodId, bool $open): void
    {
        self::assertRegistrar($registrar);

        Database::transaction(static function () use ($registrar, $periodId, $open): void {
            $period = Database::selectOne('SELECT * FROM grade_periods WHERE id = ? FOR UPDATE', [$periodId]);
            if ($period === null) {
                throw HttpException::notFound('That grading period was not found.');
            }
            if ((int) $period['is_open'] === ($open ? 1 : 0)) {
                throw ValidationException::withMessage("{$period['name']} is already " . ($open ? 'open' : 'closed') . '.');
            }

            $now = date('Y-m-d H:i:s');
            Database::update('grade_periods', $open
                ? ['is_open' => 1, 'opened_at' => $now, 'opened_by' => $registrar->id]
                : ['is_open' => 0, 'closed_at' => $now, 'closed_by' => $registrar->id],
                ['id' => $periodId]);

            AuditService::log(
                $open ? 'grading.period_opened' : 'grading.period_closed',
                'academic',
                'GradePeriod',
                $periodId,
                ['is_open' => (int) $period['is_open']],
                ['is_open' => $open ? 1 : 0, 'period' => $period['name'], 'term_id' => (int) $period['term_id']],
                'SUCCESS',
                null,
                $registrar->id,
                $registrar->role_name
            );

            if (!$open) {
                // Grades for this period are now final until reopened: tell the students who have one.
                $students = Database::select(
                    'SELECT DISTINCT s.user_id FROM grades g
                       JOIN class_enlistments ce ON ce.id = g.class_enlistment_id
                       JOIN enrollments e        ON e.id = ce.enrollment_id
                       JOIN students s           ON s.id = e.student_id
                      WHERE g.grade_period_id = ?',
                    [$periodId]
                );
                foreach ($students as $student) {
                    NotificationService::notify((int) $student['user_id'], 'grades.released', "{$period['name']} grades are available", null, '/student/grades');
                }
            }
        });
    }

    private static function assertRegistrar(User $user): void
    {
        if (!$user->isRegistrar()) {
            throw HttpException::forbidden('Only the registrar manages grading periods.');
        }
    }
}
