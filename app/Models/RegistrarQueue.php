<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Registrar-side lookups across all applications and students.
 *
 * Unscoped by owner on purpose: only routes gated to the Registrar role reach
 * this class.
 */
final class RegistrarQueue
{
    /** "Needs action": waiting for the registrar to open or finish a review. */
    public const NEEDS_ACTION = ['SUBMITTED', 'UNDER_REVIEW'];

    public const PER_PAGE = 20;

    /**
     * @param array{status?:string,term_id?:int|string,program_id?:int|string,q?:string} $filters
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int}
     */
    public static function applications(array $filters, int $page = 1): array
    {
        [$where, $bindings] = self::applicationFilters($filters);

        $total = (int) Database::scalar(
            "SELECT COUNT(*)
               FROM enrollment_applications ea
               JOIN students s ON s.id = ea.student_id
               JOIN users u    ON u.id = s.user_id
              WHERE {$where}",
            $bindings
        );

        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * self::PER_PAGE;

        $rows = Database::select(
            "SELECT ea.id, ea.application_number, ea.application_status, ea.enrollment_state, ea.student_type,
                    ea.submitted_at, ea.updated_at,
                    u.first_name, u.middle_name, u.last_name, u.email,
                    p.code AS program_code, yl.name AS year_level_name,
                    t.name AS term_name, ay.code AS academic_year_code,
                    (SELECT COUNT(*) FROM enrollment_requirements er
                      WHERE er.application_id = ea.id AND er.is_required = 1 AND er.status = 'VERIFIED') AS verified_count,
                    (SELECT COUNT(*) FROM enrollment_requirements er
                      WHERE er.application_id = ea.id AND er.is_required = 1) AS required_count
               FROM enrollment_applications ea
               JOIN students s        ON s.id  = ea.student_id
               JOIN users u           ON u.id  = s.user_id
               JOIN programs p        ON p.id  = ea.program_id
               JOIN year_levels yl    ON yl.id = ea.year_level_id
               JOIN terms t           ON t.id  = ea.term_id
               JOIN academic_years ay ON ay.id = ea.academic_year_id
              WHERE {$where}
              ORDER BY (ea.submitted_at IS NULL), ea.submitted_at ASC, ea.id ASC
              LIMIT " . self::PER_PAGE . " OFFSET {$offset}",
            $bindings
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function applicationFilters(array $filters): array
    {
        // Drafts are the student's private work in progress; the registrar never sees them.
        $where = ["ea.application_status <> 'DRAFT'"];
        $bindings = [];

        $status = (string) ($filters['status'] ?? 'action');
        if ($status === 'action') {
            $where[] = "ea.application_status IN ('SUBMITTED', 'UNDER_REVIEW')";
        } elseif ($status === 'awaiting_confirmation') {
            $where[] = "ea.application_status = 'APPROVED' AND ea.enrollment_state = 'NOT_ENROLLED'";
        } elseif ($status === 'enrolled') {
            $where[] = "ea.enrollment_state = 'ENROLLED'";
        } elseif ($status !== 'all' && in_array($status, [
            'SUBMITTED', 'UNDER_REVIEW', 'REQUIREMENTS_INCOMPLETE', 'CORRECTION_REQUIRED', 'APPROVED', 'REJECTED', 'CANCELLED',
        ], true)) {
            $where[] = 'ea.application_status = ?';
            $bindings[] = $status;
        }

        if (!empty($filters['term_id'])) {
            $where[] = 'ea.term_id = ?';
            $bindings[] = (int) $filters['term_id'];
        }
        if (!empty($filters['program_id'])) {
            $where[] = 'ea.program_id = ?';
            $bindings[] = (int) $filters['program_id'];
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = "(ea.application_number LIKE ? OR u.email LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR u.last_name LIKE ?)";
            array_push($bindings, $like, $like, $like, $like);
        }

        return [implode(' AND ', $where), $bindings];
    }

    /** @return array<string,int> counts for the dashboard and filter labels */
    public static function counts(): array
    {
        $row = Database::selectOne(
            "SELECT
                SUM(application_status = 'SUBMITTED')                                   AS submitted,
                SUM(application_status = 'UNDER_REVIEW')                                AS under_review,
                SUM(application_status IN ('REQUIREMENTS_INCOMPLETE', 'CORRECTION_REQUIRED')) AS returned,
                SUM(application_status = 'APPROVED' AND enrollment_state = 'NOT_ENROLLED')    AS awaiting_confirmation,
                SUM(enrollment_state = 'ENROLLED')                                      AS enrolled
               FROM enrollment_applications
              WHERE application_status <> 'DRAFT'"
        ) ?? [];

        return array_map('intval', $row + [
            'submitted' => 0, 'under_review' => 0, 'returned' => 0, 'awaiting_confirmation' => 0, 'enrolled' => 0,
        ]);
    }

    /**
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int}
     */
    public static function students(string $q, string $status, int $page = 1): array
    {
        $where = ['1 = 1'];
        $bindings = [];

        if (in_array($status, [Student::STATUS_APPLICANT, Student::STATUS_ENROLLED, 'Inactive', 'Graduated'], true)) {
            $where[] = 's.status = ?';
            $bindings[] = $status;
        }

        $q = trim($q);
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = "(s.student_number LIKE ? OR u.email LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR u.last_name LIKE ?)";
            array_push($bindings, $like, $like, $like, $like);
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) Database::scalar("SELECT COUNT(*) FROM students s JOIN users u ON u.id = s.user_id WHERE {$whereSql}", $bindings);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * self::PER_PAGE;

        $rows = Database::select(
            "SELECT s.id, s.student_number, s.status, s.student_type,
                    u.first_name, u.middle_name, u.last_name, u.email,
                    p.code AS program_code, yl.name AS year_level_name,
                    (SELECT COUNT(*) FROM enrollment_applications ea WHERE ea.student_id = s.id AND ea.application_status <> 'DRAFT') AS application_count
               FROM students s
               JOIN users u             ON u.id  = s.user_id
          LEFT JOIN programs p          ON p.id  = s.program_id
          LEFT JOIN year_levels yl      ON yl.id = s.year_level_id
              WHERE {$whereSql}
              ORDER BY u.last_name, u.first_name
              LIMIT " . self::PER_PAGE . " OFFSET {$offset}",
            $bindings
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** @return array<string,mixed>|null student with account and program details */
    public static function student(int $id): ?array
    {
        return Database::selectOne(
            'SELECT s.*, u.first_name, u.middle_name, u.last_name, u.email, u.username, u.status AS account_status,
                    u.created_at AS registered_at, p.code AS program_code, p.name AS program_name, yl.name AS year_level_name,
                    c.code AS curriculum_code, c.name AS curriculum_name, c.effective_school_year AS curriculum_school_year
               FROM students s
               JOIN users u        ON u.id  = s.user_id
          LEFT JOIN programs p     ON p.id  = s.program_id
          LEFT JOIN year_levels yl ON yl.id = s.year_level_id
          LEFT JOIN curricula c    ON c.id  = s.curriculum_id
              WHERE s.id = ?',
            [$id]
        );
    }

    /** @return array<int,array<string,mixed>> every non-draft application of a student */
    public static function applicationsOfStudent(int $studentId): array
    {
        return Database::select(
            "SELECT ea.id, ea.application_number, ea.application_status, ea.enrollment_state, ea.submitted_at,
                    t.name AS term_name, ay.code AS academic_year_code, p.code AS program_code, yl.name AS year_level_name,
                    sec.code AS section_code
               FROM enrollment_applications ea
               JOIN terms t           ON t.id  = ea.term_id
               JOIN academic_years ay ON ay.id = ea.academic_year_id
               JOIN programs p        ON p.id  = ea.program_id
               JOIN year_levels yl    ON yl.id = ea.year_level_id
          LEFT JOIN enrollments en    ON en.application_id = ea.id
          LEFT JOIN sections sec      ON sec.id = en.section_id
              WHERE ea.student_id = ? AND ea.application_status <> 'DRAFT'
              ORDER BY ea.created_at DESC",
            [$studentId]
        );
    }
}
