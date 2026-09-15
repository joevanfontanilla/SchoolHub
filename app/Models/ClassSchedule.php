<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Services\AcademicRecordService;
use App\Services\GradeCalculator;

/**
 * Read queries for classes, rosters, and teaching timetables (docs/12, docs/13).
 * Writes go through ClassScheduleService, EnlistmentService, GradingService, and
 * AttendanceService.
 */
final class ClassSchedule
{
    private const CLASS_COLUMNS =
        "cs.*, sub.code, sub.title, sub.units, sec.code AS section_code, sec.year_level_id,
         t.name AS term_name, t.start_date AS term_start, t.end_date AS term_end, ay.code AS academic_year_code,
         CONCAT(u.first_name, ' ', u.last_name) AS teacher_name,
         (SELECT COUNT(*) FROM class_enlistments ce WHERE ce.class_schedule_id = cs.id AND ce.status = 'ENLISTED') AS enlisted_count";

    private const CLASS_JOINS =
        'FROM class_schedules cs
         JOIN subjects sub       ON sub.id = cs.subject_id
         JOIN sections sec       ON sec.id = cs.section_id
         JOIN terms t            ON t.id = cs.term_id
         JOIN academic_years ay  ON ay.id = t.academic_year_id
    LEFT JOIN users u            ON u.id = cs.teacher_user_id';

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return self::withMeetings(Database::select('SELECT ' . self::CLASS_COLUMNS . ' ' . self::CLASS_JOINS . ' WHERE cs.id = ?', [$id]))[0] ?? null;
    }

    /** @return array<int,array<string,mixed>> a section's classes, active first */
    public static function forSection(int $sectionId): array
    {
        return self::withMeetings(Database::select(
            'SELECT ' . self::CLASS_COLUMNS . ' ' . self::CLASS_JOINS . "
              WHERE cs.section_id = ? ORDER BY cs.status = 'CLOSED', sub.code",
            [$sectionId]
        ));
    }

    /**
     * A teacher's active classes, newest term first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forTeacher(int $teacherId): array
    {
        return self::withMeetings(Database::select(
            'SELECT ' . self::CLASS_COLUMNS . ' ' . self::CLASS_JOINS . "
              WHERE cs.teacher_user_id = ? AND cs.status = 'ACTIVE'
              ORDER BY t.start_date DESC, sub.code, sec.code",
            [$teacherId]
        ));
    }

    /**
     * Active classes of a term that an enrollment is not already in — the
     * registrar's "add a class" choices.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function addableFor(int $enrollmentId): array
    {
        return self::withMeetings(Database::select(
            'SELECT ' . self::CLASS_COLUMNS . ' ' . self::CLASS_JOINS . "
              WHERE cs.term_id = (SELECT term_id FROM enrollments WHERE id = ?)
                AND cs.status = 'ACTIVE'
                AND NOT EXISTS (SELECT 1 FROM class_enlistments ce WHERE ce.class_schedule_id = cs.id AND ce.enrollment_id = ? AND ce.status = 'ENLISTED')
              ORDER BY sub.code, sec.code",
            [$enrollmentId, $enrollmentId]
        ));
    }

    /** The student an enlistment belongs to, or null if there is no such enlistment. */
    public static function studentOfEnlistment(int $enlistmentId): ?int
    {
        $id = Database::scalar(
            'SELECT e.student_id FROM class_enlistments ce JOIN enrollments e ON e.id = ce.enrollment_id WHERE ce.id = ?',
            [$enlistmentId]
        );
        return $id === null ? null : (int) $id;
    }

    /**
     * Students enlisted in a class, by name.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function roster(int $classId): array
    {
        return Database::select(
            "SELECT ce.id AS enlistment_id, ce.override_reason, st.id AS student_id, st.student_number,
                    u.first_name, u.middle_name, u.last_name, u.email, p.code AS program_code, yl.name AS year_level_name
               FROM class_enlistments ce
               JOIN enrollments e  ON e.id = ce.enrollment_id
               JOIN students st    ON st.id = e.student_id
               JOIN users u        ON u.id = st.user_id
          LEFT JOIN programs p     ON p.id = st.program_id
          LEFT JOIN year_levels yl ON yl.id = st.year_level_id
              WHERE ce.class_schedule_id = ? AND ce.status = 'ENLISTED'
              ORDER BY u.last_name, u.first_name",
            [$classId]
        );
    }

    /**
     * The roster with each student's attendance on one date.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function attendanceSheet(int $classId, string $date): array
    {
        $marks = [];
        foreach (Database::select(
            'SELECT a.class_enlistment_id, a.status, a.remarks FROM attendance a
               JOIN class_enlistments ce ON ce.id = a.class_enlistment_id
              WHERE ce.class_schedule_id = ? AND a.attendance_date = ?',
            [$classId, $date]
        ) as $row) {
            $marks[(int) $row['class_enlistment_id']] = $row;
        }

        return array_map(static fn (array $student) => $student + [
            'status'  => $marks[(int) $student['enlistment_id']]['status'] ?? null,
            'remarks' => $marks[(int) $student['enlistment_id']]['remarks'] ?? null,
        ], self::roster($classId));
    }

    /**
     * Dates attendance has been taken for a class, newest first, with counts.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function attendanceDates(int $classId, int $limit = 12): array
    {
        return Database::select(
            "SELECT a.attendance_date,
                    SUM(a.status = 'PRESENT') AS present, SUM(a.status = 'ABSENT') AS absent,
                    SUM(a.status = 'LATE') AS late, SUM(a.status = 'EXCUSED') AS excused
               FROM attendance a JOIN class_enlistments ce ON ce.id = a.class_enlistment_id
              WHERE ce.class_schedule_id = ?
              GROUP BY a.attendance_date
              ORDER BY a.attendance_date DESC
              LIMIT " . max(1, $limit),
            [$classId]
        );
    }

    /**
     * The roster with every period grade and the computed final.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function gradeSheet(int $classId, int $termId): array
    {
        $weights = [];
        foreach (AcademicRecordService::periods($termId) as $period) {
            $weights[(int) $period['id']] = (float) $period['weight'];
        }

        $grades = [];
        foreach (Database::select(
            'SELECT g.class_enlistment_id, g.grade_period_id, g.grade, g.remark FROM grades g
               JOIN class_enlistments ce ON ce.id = g.class_enlistment_id
              WHERE ce.class_schedule_id = ?',
            [$classId]
        ) as $row) {
            $grades[(int) $row['class_enlistment_id']][(int) $row['grade_period_id']] = ['grade' => $row['grade'], 'remark' => $row['remark']];
        }

        return array_map(static function (array $student) use ($grades, $weights): array {
            $own = $grades[(int) $student['enlistment_id']] ?? [];
            return $student + [
                'period_grades' => $own,
                'final'         => $weights === [] ? ['grade' => null, 'remark' => GradeCalculator::REMARK_IN_PROGRESS] : GradeCalculator::final($own, $weights),
            ];
        }, self::roster($classId));
    }

    /**
     * Attendance for a whole class: every date taken, oldest first, and each enlisted
     * student's mark on each date with their totals.
     *
     * @return array{dates:array<int,string>,students:array<int,array<string,mixed>>}
     */
    public static function attendanceSummary(int $classId): array
    {
        $dates = [];
        $marks = [];
        foreach (Database::select(
            'SELECT a.class_enlistment_id, a.attendance_date, a.status FROM attendance a
               JOIN class_enlistments ce ON ce.id = a.class_enlistment_id
              WHERE ce.class_schedule_id = ?
              ORDER BY a.attendance_date',
            [$classId]
        ) as $row) {
            $dates[$row['attendance_date']] = $row['attendance_date'];
            $marks[(int) $row['class_enlistment_id']][$row['attendance_date']] = $row['status'];
        }

        $students = array_map(static function (array $student) use ($marks): array {
            $own = $marks[(int) $student['enlistment_id']] ?? [];
            $counts = ['PRESENT' => 0, 'ABSENT' => 0, 'LATE' => 0, 'EXCUSED' => 0];
            foreach ($own as $status) {
                $counts[$status] = ($counts[$status] ?? 0) + 1;
            }
            $taken = array_sum($counts);
            return $student + [
                'marks'  => $own,
                'counts' => $counts,
                // Late still attended; excused absences are left out of the rate.
                'rate'   => $taken - $counts['EXCUSED'] > 0 ? (int) round(($counts['PRESENT'] + $counts['LATE']) / ($taken - $counts['EXCUSED']) * 100) : null,
            ];
        }, self::roster($classId));

        return ['dates' => array_values($dates), 'students' => $students];
    }

    /**
     * How many enlisted students of a class have a grade for a period.
     */
    public static function gradedCount(int $classId, int $periodId): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM grades g JOIN class_enlistments ce ON ce.id = g.class_enlistment_id
              WHERE ce.class_schedule_id = ? AND ce.status = 'ENLISTED' AND g.grade_period_id = ?",
            [$classId, $periodId]
        );
    }

    /**
     * Per class of one enrollment: attendance counts and every dated entry.
     *
     * @return array<int,array<string,mixed>> keyed by enlistment id
     */
    public static function attendanceForEnrollment(int $enrollmentId): array
    {
        $result = [];
        foreach (Database::select(
            'SELECT a.class_enlistment_id, a.attendance_date, a.status, a.remarks
               FROM attendance a JOIN class_enlistments ce ON ce.id = a.class_enlistment_id
              WHERE ce.enrollment_id = ?
              ORDER BY a.attendance_date DESC',
            [$enrollmentId]
        ) as $row) {
            $id = (int) $row['class_enlistment_id'];
            $result[$id] ??= ['counts' => ['PRESENT' => 0, 'ABSENT' => 0, 'LATE' => 0, 'EXCUSED' => 0], 'entries' => []];
            $result[$id]['counts'][$row['status']] = ($result[$id]['counts'][$row['status']] ?? 0) + 1;
            $result[$id]['entries'][] = $row;
        }
        return $result;
    }

    /**
     * Blocks for the weekly schedule partial.
     *
     * @param array<int,array<string,mixed>> $classes rows with 'meetings', 'code', 'title', 'section_code'
     * @return array<int,array{day:int,start:string,end:string,code:string,title:string,detail:string}>
     */
    public static function blocks(array $classes, bool $showTeacher = false): array
    {
        $blocks = [];
        foreach ($classes as $class) {
            foreach ($class['meetings'] ?? [] as $meeting) {
                $detail = array_filter([
                    $meeting['room_code'] ?? null,
                    $class['section_code'] ?? null,
                    $showTeacher ? ($class['teacher_name'] ?? 'No teacher yet') : null,
                ]);
                $blocks[] = [
                    'day'    => (int) $meeting['day_of_week'],
                    'start'  => (string) $meeting['start_time'],
                    'end'    => (string) $meeting['end_time'],
                    'code'   => (string) $class['code'],
                    'title'  => (string) $class['title'],
                    'detail' => implode(' · ', $detail),
                ];
            }
        }
        usort($blocks, static fn ($a, $b) => [$a['day'], $a['start']] <=> [$b['day'], $b['start']]);
        return $blocks;
    }

    /**
     * @param array<int,array<string,mixed>> $classes
     * @return array<int,array<string,mixed>>
     */
    private static function withMeetings(array $classes): array
    {
        if ($classes === []) {
            return [];
        }
        $ids = array_map(static fn ($c) => (int) $c['id'], $classes);
        $in = implode(',', array_fill(0, count($ids), '?'));

        $meetings = [];
        foreach (Database::select(
            "SELECT m.*, r.code AS room_code FROM class_meetings m LEFT JOIN rooms r ON r.id = m.room_id
              WHERE m.class_schedule_id IN ({$in}) ORDER BY m.day_of_week, m.start_time",
            $ids
        ) as $meeting) {
            $meetings[(int) $meeting['class_schedule_id']][] = $meeting;
        }

        foreach ($classes as &$class) {
            $class['meetings'] = $meetings[(int) $class['id']] ?? [];
        }
        unset($class);

        return $classes;
    }
}
