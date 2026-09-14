<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Curriculum;

/**
 * A student's classes, grades, and history across terms (docs/13 "Student
 * Academic History"). Read-only. Final grades are always computed through
 * GradeCalculator from the stored period grades — never stored separately, so
 * a corrected period grade can never leave a stale final behind.
 */
final class AcademicRecordService
{
    /**
     * Grading periods of a term, in order.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function periods(int $termId): array
    {
        return Database::select('SELECT * FROM grade_periods WHERE term_id = ? ORDER BY sequence', [$termId]);
    }

    /**
     * Every class of one enrollment with meetings, period grades, and the final.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function classesForEnrollment(int $enrollmentId): array
    {
        $enrollment = Database::selectOne('SELECT * FROM enrollments WHERE id = ?', [$enrollmentId]);
        if ($enrollment === null) {
            return [];
        }

        $periods = self::periods((int) $enrollment['term_id']);
        $weights = [];
        foreach ($periods as $period) {
            $weights[(int) $period['id']] = (float) $period['weight'];
        }

        $classes = Database::select(
            "SELECT ce.id AS enlistment_id, ce.status AS enlistment_status, ce.override_reason, ce.drop_reason, ce.dropped_at,
                    cs.id AS class_id, cs.teacher_user_id,
                    sub.id AS subject_id, sub.code, sub.title, sub.units,
                    sec.code AS section_code,
                    CONCAT(t.first_name, ' ', t.last_name) AS teacher_name
               FROM class_enlistments ce
               JOIN class_schedules cs ON cs.id = ce.class_schedule_id
               JOIN subjects sub       ON sub.id = cs.subject_id
               JOIN sections sec       ON sec.id = cs.section_id
          LEFT JOIN users t            ON t.id = cs.teacher_user_id
              WHERE ce.enrollment_id = ?
              ORDER BY ce.status = 'DROPPED', sub.code",
            [$enrollmentId]
        );

        foreach ($classes as &$class) {
            $class['meetings'] = self::meetings((int) $class['class_id']);

            $grades = [];
            foreach (Database::select('SELECT grade_period_id, grade, remark FROM grades WHERE class_enlistment_id = ?', [$class['enlistment_id']]) as $row) {
                $grades[(int) $row['grade_period_id']] = ['grade' => $row['grade'], 'remark' => $row['remark']];
            }
            $class['period_grades'] = $grades;

            if ($class['enlistment_status'] === EnlistmentService::DROPPED) {
                $class['final'] = ['grade' => null, 'remark' => GradeCalculator::REMARK_DRP];
            } elseif ($weights === []) {
                $class['final'] = ['grade' => null, 'remark' => GradeCalculator::REMARK_IN_PROGRESS];
            } else {
                $class['final'] = GradeCalculator::final($grades, $weights);
            }
        }
        unset($class);

        return $classes;
    }

    /** @return array<int,array<string,mixed>> */
    public static function meetings(int $classId): array
    {
        return Database::select(
            'SELECT m.*, r.code AS room_code FROM class_meetings m LEFT JOIN rooms r ON r.id = m.room_id
              WHERE m.class_schedule_id = ? ORDER BY m.day_of_week, m.start_time',
            [$classId]
        );
    }

    /**
     * Every term the student was enrolled in, newest first, with its classes.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function history(int $studentId): array
    {
        $enrollments = Database::select(
            'SELECT e.*, t.name AS term_name, t.start_date, ay.code AS academic_year_code,
                    sec.code AS section_code, p.code AS program_code, yl.name AS year_level_name
               FROM enrollments e
               JOIN terms t           ON t.id  = e.term_id
               JOIN academic_years ay ON ay.id = t.academic_year_id
               JOIN sections sec      ON sec.id = e.section_id
               JOIN programs p        ON p.id  = sec.program_id
               JOIN year_levels yl    ON yl.id = sec.year_level_id
              WHERE e.student_id = ?
              ORDER BY t.start_date DESC',
            [$studentId]
        );

        foreach ($enrollments as &$enrollment) {
            $enrollment['periods'] = self::periods((int) $enrollment['term_id']);
            $enrollment['classes'] = self::classesForEnrollment((int) $enrollment['id']);
        }
        unset($enrollment);

        return $enrollments;
    }

    /** The student's enrollment in the most recent term, if any. */
    public static function currentEnrollment(int $studentId): ?array
    {
        return Database::selectOne(
            "SELECT e.*, t.name AS term_name, t.start_date, t.end_date, ay.code AS academic_year_code, sec.code AS section_code
               FROM enrollments e
               JOIN terms t           ON t.id  = e.term_id
               JOIN academic_years ay ON ay.id = t.academic_year_id
               JOIN sections sec      ON sec.id = e.section_id
              WHERE e.student_id = ? AND e.status = 'ENROLLED'
              ORDER BY t.start_date DESC
              LIMIT 1",
            [$studentId]
        );
    }

    /** Has the student passed this subject in any term? Only a PASSED final counts. */
    public static function hasPassed(int $studentId, int $subjectId): bool
    {
        return in_array($subjectId, self::passedSubjectIds($studentId), true);
    }

    /**
     * Every subject the student has passed, in any term.
     *
     * @return array<int,int> subject ids
     */
    public static function passedSubjectIds(int $studentId): array
    {
        $enrollmentIds = Database::select(
            "SELECT DISTINCT e.id
               FROM enrollments e
               JOIN class_enlistments ce ON ce.enrollment_id = e.id AND ce.status = 'ENLISTED'
              WHERE e.student_id = ?",
            [$studentId]
        );

        $passed = [];
        foreach ($enrollmentIds as $row) {
            foreach (self::classesForEnrollment((int) $row['id']) as $class) {
                if ($class['final']['remark'] === GradeCalculator::REMARK_PASSED) {
                    $passed[(int) $class['subject_id']] = true;
                }
            }
        }

        return array_keys($passed);
    }

    /**
     * How far through a curriculum the student is: units of its subjects passed,
     * as a whole percent of the curriculum's units (docs/28, "80% of the course").
     */
    public static function progressPercent(int $studentId, int $curriculumId): int
    {
        $total = Curriculum::totalUnits($curriculumId);
        if ($total <= 0) {
            return 0;
        }

        $passed = self::passedSubjectIds($studentId);
        if ($passed === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($passed), '?'));
        $units = (float) Database::scalar(
            "SELECT COALESCE(SUM(units), 0) FROM subjects WHERE curriculum_id = ? AND is_active = 1 AND id IN ({$in})",
            array_merge([$curriculumId], $passed)
        );

        return (int) floor($units / $total * 100);
    }
}
