<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Registrar reports (docs/15): the enrollment list, applicants by status, requirements
 * status, and enrollment history. Read-only and unscoped by owner: only routes gated to
 * the Registrar role with reports.registrar reach this class.
 *
 * Draft applications are the student's private work in progress and never counted.
 */
final class RegistrarReport
{
    /** Application statuses in the order the queue moves through them. */
    public const APPLICATION_STATUSES = [
        EnrollmentApplication::SUBMITTED, EnrollmentApplication::UNDER_REVIEW, EnrollmentApplication::REQUIREMENTS_INCOMPLETE,
        EnrollmentApplication::CORRECTION_REQUIRED, EnrollmentApplication::APPROVED, EnrollmentApplication::REJECTED, EnrollmentApplication::CANCELLED,
    ];

    public const REQUIREMENT_STATUSES = [
        EnrollmentApplication::REQ_NOT_SUBMITTED, EnrollmentApplication::REQ_SUBMITTED, EnrollmentApplication::REQ_UNDER_REVIEW,
        EnrollmentApplication::REQ_CORRECTION_REQUIRED, EnrollmentApplication::REQ_REJECTED, EnrollmentApplication::REQ_VERIFIED,
    ];

    /**
     * The term a report opens on: the one open for enrollment (the registrar's current work),
     * else the one in session today, else the latest that has started, else the next.
     */
    public static function defaultTermId(): ?int
    {
        $open = Term::openForEnrollment();
        if ($open !== []) {
            return (int) $open[0]['id'];
        }
        $id = Database::scalar(
            'SELECT id FROM terms
              ORDER BY (CURDATE() BETWEEN start_date AND end_date) DESC, (start_date <= CURDATE()) DESC,
                       CASE WHEN start_date <= CURDATE() THEN start_date END DESC, start_date ASC
              LIMIT 1'
        );
        return $id === null ? null : (int) $id;
    }

    /**
     * Officially enrolled students of a term, by program, year level, section, and name.
     *
     * @return array{rows:array<int,array<string,mixed>>,groups:array<int,array<string,mixed>>,by_type:array<string,int>,units:float}
     */
    public static function enrollmentList(int $termId, ?int $programId = null, ?int $yearLevelId = null, ?int $sectionId = null): array
    {
        $where = ["e.status = 'ENROLLED'", 'e.term_id = ?'];
        $bindings = [$termId];
        foreach (['sec.program_id' => $programId, 'sec.year_level_id' => $yearLevelId, 'e.section_id' => $sectionId] as $column => $value) {
            if ($value !== null) {
                $where[] = $column . ' = ?';
                $bindings[] = $value;
            }
        }

        $rows = Database::select(
            'SELECT e.id AS enrollment_id, e.total_units, e.enrolled_at, st.id AS student_id, st.student_number,
                    u.first_name, u.middle_name, u.last_name, u.email, ea.student_type, ea.application_number,
                    p.id AS program_id, p.code AS program_code, p.name AS program_name,
                    yl.name AS year_level_name, yl.sequence AS year_sequence, sec.id AS section_id, sec.code AS section_code, sec.capacity
               FROM enrollments e
               JOIN enrollment_applications ea ON ea.id = e.application_id
               JOIN students st       ON st.id = e.student_id
               JOIN users u           ON u.id = st.user_id
               JOIN sections sec      ON sec.id = e.section_id
               JOIN programs p        ON p.id = sec.program_id
               JOIN year_levels yl    ON yl.id = sec.year_level_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY p.code, yl.sequence, sec.code, u.last_name, u.first_name',
            $bindings
        );

        // Counts by program, year level, and section, in the order the rows already come in.
        $groups = [];
        $byType = [];
        $units = 0.0;
        foreach ($rows as $row) {
            $key = $row['section_id'];
            $groups[$key] ??= [
                'program_code' => $row['program_code'], 'year_level_name' => $row['year_level_name'],
                'section_code' => $row['section_code'], 'capacity' => (int) $row['capacity'], 'count' => 0,
            ];
            $groups[$key]['count']++;
            $byType[$row['student_type']] = ($byType[$row['student_type']] ?? 0) + 1;
            $units += (float) $row['total_units'];
        }

        return ['rows' => $rows, 'groups' => array_values($groups), 'by_type' => $byType, 'units' => $units];
    }

    /**
     * Every submitted application of a term with its status, and counts by program and status.
     *
     * @return array{rows:array<int,array<string,mixed>>,counts:array<string,int>,matrix:array<string,array<string,int>>}
     */
    public static function applicationsByStatus(int $termId, ?int $programId = null, ?string $status = null): array
    {
        $where = ["ea.application_status <> 'DRAFT'", 'ea.term_id = ?'];
        $bindings = [$termId];
        if ($programId !== null) {
            $where[] = 'ea.program_id = ?';
            $bindings[] = $programId;
        }

        $rows = Database::select(
            "SELECT ea.id, ea.application_number, ea.application_status, ea.payment_status, ea.enrollment_state, ea.student_type,
                    ea.submitted_at, ea.updated_at, st.student_number, u.first_name, u.middle_name, u.last_name, u.email,
                    p.code AS program_code, yl.name AS year_level_name,
                    SUM(er.is_required = 1) AS required_count,
                    SUM(er.is_required = 1 AND er.status = 'VERIFIED') AS verified_count
               FROM enrollment_applications ea
               JOIN students st       ON st.id = ea.student_id
               JOIN users u           ON u.id = st.user_id
               JOIN programs p        ON p.id = ea.program_id
               JOIN year_levels yl    ON yl.id = ea.year_level_id
          LEFT JOIN enrollment_requirements er ON er.application_id = ea.id
              WHERE " . implode(' AND ', $where) . '
              GROUP BY ea.id
              ORDER BY p.code, yl.sequence, u.last_name, u.first_name',
            $bindings
        );

        // Counts cover the whole term and program; the status filter narrows only the list.
        $counts = array_fill_keys(self::APPLICATION_STATUSES, 0);
        $matrix = [];
        foreach ($rows as $row) {
            $counts[$row['application_status']] = ($counts[$row['application_status']] ?? 0) + 1;
            $matrix[$row['program_code']] ??= array_fill_keys(self::APPLICATION_STATUSES, 0);
            $matrix[$row['program_code']][$row['application_status']]++;
        }
        if ($status !== null && in_array($status, self::APPLICATION_STATUSES, true)) {
            $rows = array_values(array_filter($rows, static fn (array $row) => $row['application_status'] === $status));
        }

        return ['rows' => $rows, 'counts' => $counts, 'matrix' => $matrix];
    }

    /**
     * Document requirements of a term's live applications (not rejected or cancelled):
     * counts per requirement and status, and every application still missing a required one.
     *
     * @return array{types:array<int,array<string,mixed>>,outstanding:array<int,array<string,mixed>>,applications:int,complete:int}
     */
    public static function requirementsStatus(int $termId, ?int $programId = null): array
    {
        $where = ["ea.application_status NOT IN ('DRAFT', 'REJECTED', 'CANCELLED')", 'ea.term_id = ?'];
        $bindings = [$termId];
        if ($programId !== null) {
            $where[] = 'ea.program_id = ?';
            $bindings[] = $programId;
        }
        $whereSql = implode(' AND ', $where);

        $types = [];
        foreach (Database::select(
            "SELECT rt.id, rt.name, rt.sort_order, er.status, SUM(er.is_required = 1) AS required, COUNT(*) AS total
               FROM enrollment_requirements er
               JOIN enrollment_applications ea ON ea.id = er.application_id
               JOIN requirement_types rt ON rt.id = er.requirement_type_id
              WHERE {$whereSql}
              GROUP BY rt.id, er.status
              ORDER BY rt.sort_order, rt.name",
            $bindings
        ) as $row) {
            $id = (int) $row['id'];
            $types[$id] ??= ['name' => $row['name'], 'counts' => array_fill_keys(self::REQUIREMENT_STATUSES, 0), 'total' => 0, 'required' => 0];
            $types[$id]['counts'][$row['status']] = ($types[$id]['counts'][$row['status']] ?? 0) + (int) $row['total'];
            $types[$id]['total'] += (int) $row['total'];
            $types[$id]['required'] += (int) $row['required'];
        }

        $outstanding = [];
        foreach (Database::select(
            "SELECT ea.id, ea.application_number, ea.application_status, ea.submitted_at, st.student_number,
                    u.first_name, u.last_name, u.email, p.code AS program_code, yl.name AS year_level_name,
                    rt.name AS requirement_name, er.status AS requirement_status
               FROM enrollment_requirements er
               JOIN enrollment_applications ea ON ea.id = er.application_id
               JOIN requirement_types rt ON rt.id = er.requirement_type_id
               JOIN students st    ON st.id = ea.student_id
               JOIN users u        ON u.id = st.user_id
               JOIN programs p     ON p.id = ea.program_id
               JOIN year_levels yl ON yl.id = ea.year_level_id
              WHERE {$whereSql} AND er.is_required = 1 AND er.status <> 'VERIFIED'
              ORDER BY p.code, yl.sequence, u.last_name, u.first_name, rt.sort_order",
            $bindings
        ) as $row) {
            $id = (int) $row['id'];
            $outstanding[$id] ??= array_diff_key($row, array_flip(['requirement_name', 'requirement_status'])) + ['missing' => []];
            $outstanding[$id]['missing'][] = ['name' => $row['requirement_name'], 'status' => $row['requirement_status']];
        }

        $applications = (int) Database::scalar("SELECT COUNT(*) FROM enrollment_applications ea WHERE {$whereSql}", $bindings);

        return [
            'types'        => array_values($types),
            'outstanding'  => array_values($outstanding),
            'applications' => $applications,
            'complete'     => $applications - count($outstanding),
        ];
    }

    /**
     * Applicants and enrolled students for every term, newest first, with enrolled
     * students per program.
     *
     * @return array{terms:array<int,array<string,mixed>>,programs:array<int,string>}
     */
    public static function enrollmentHistory(?int $programId = null): array
    {
        $programFilter = $programId !== null ? ' AND p.id = ?' : '';
        $bindings = $programId !== null ? [$programId] : [];

        $terms = [];
        foreach (Database::select(
            'SELECT t.id, t.name, t.start_date, ay.code AS academic_year_code FROM terms t
               JOIN academic_years ay ON ay.id = t.academic_year_id
              WHERE t.start_date <= DATE_ADD(CURDATE(), INTERVAL 1 YEAR)
              ORDER BY t.start_date DESC'
        ) as $term) {
            $terms[(int) $term['id']] = $term + ['applicants' => 0, 'approved' => 0, 'enrolled' => 0, 'new' => 0, 'units' => 0.0, 'by_program' => []];
        }

        foreach (Database::select(
            "SELECT ea.term_id, COUNT(*) AS applicants, SUM(ea.application_status = 'APPROVED') AS approved
               FROM enrollment_applications ea JOIN programs p ON p.id = ea.program_id
              WHERE ea.application_status <> 'DRAFT'{$programFilter}
              GROUP BY ea.term_id",
            $bindings
        ) as $row) {
            if (isset($terms[(int) $row['term_id']])) {
                $terms[(int) $row['term_id']]['applicants'] = (int) $row['applicants'];
                $terms[(int) $row['term_id']]['approved'] = (int) $row['approved'];
            }
        }

        $programs = [];
        foreach (Database::select(
            "SELECT e.term_id, p.code AS program_code, COUNT(*) AS enrolled, SUM(ea.student_type = 'New') AS new_students, SUM(e.total_units) AS units
               FROM enrollments e
               JOIN enrollment_applications ea ON ea.id = e.application_id
               JOIN sections sec ON sec.id = e.section_id
               JOIN programs p   ON p.id = sec.program_id
              WHERE e.status = 'ENROLLED'{$programFilter}
              GROUP BY e.term_id, p.id
              ORDER BY p.code",
            $bindings
        ) as $row) {
            $id = (int) $row['term_id'];
            if (!isset($terms[$id])) {
                continue;
            }
            $programs[$row['program_code']] = $row['program_code'];
            $terms[$id]['by_program'][$row['program_code']] = (int) $row['enrolled'];
            $terms[$id]['enrolled'] += (int) $row['enrolled'];
            $terms[$id]['new'] += (int) $row['new_students'];
            $terms[$id]['units'] += (float) $row['units'];
        }

        return ['terms' => array_values($terms), 'programs' => array_values($programs)];
    }

    /** A readable label for a status code: UNDER_REVIEW => "Under review". */
    public static function label(string $status): string
    {
        return ucfirst(strtolower(str_replace('_', ' ', $status)));
    }
}
