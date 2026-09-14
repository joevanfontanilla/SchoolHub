<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Read queries for programs, subjects, and sections.
 */
final class Catalog
{
    /** @return array<int,array<string,mixed>> */
    public static function programs(): array
    {
        return Database::select(
            'SELECT p.*,
                    (SELECT COUNT(*) FROM subjects s WHERE s.program_id = p.id) AS subject_count,
                    (SELECT COUNT(*) FROM students st WHERE st.program_id = p.id AND st.status = \'Enrolled\') AS enrolled_count
               FROM programs p
              ORDER BY p.is_active DESC, p.code'
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function subjects(?int $programId = null): array
    {
        $where = '';
        $bindings = [];
        if ($programId !== null) {
            $where = 'WHERE s.program_id = ? OR s.program_id IS NULL';
            $bindings[] = $programId;
        }

        return Database::select(
            "SELECT s.*, p.code AS program_code, yl.name AS year_level_name,
                    (SELECT GROUP_CONCAT(pre.code ORDER BY pre.code SEPARATOR ', ')
                       FROM subject_prerequisites sp JOIN subjects pre ON pre.id = sp.prerequisite_subject_id
                      WHERE sp.subject_id = s.id) AS prerequisite_codes
               FROM subjects s
          LEFT JOIN programs p     ON p.id  = s.program_id
          LEFT JOIN year_levels yl ON yl.id = s.year_level_id
               {$where}
              ORDER BY s.is_active DESC, p.code IS NULL, p.code, yl.sequence, s.term_sequence, s.code",
            $bindings
        );
    }

    /** @return array<int,int> */
    public static function prerequisiteIds(int $subjectId): array
    {
        return array_map('intval', array_column(
            Database::select('SELECT prerequisite_subject_id FROM subject_prerequisites WHERE subject_id = ?', [$subjectId]),
            'prerequisite_subject_id'
        ));
    }

    /**
     * @param array{term_id?:int|string,program_id?:int|string} $filters
     * @return array<int,array<string,mixed>>
     */
    public static function sections(array $filters = []): array
    {
        $where = ['1 = 1'];
        $bindings = [];
        if (!empty($filters['term_id'])) {
            $where[] = 'sec.term_id = ?';
            $bindings[] = (int) $filters['term_id'];
        }
        if (!empty($filters['program_id'])) {
            $where[] = 'sec.program_id = ?';
            $bindings[] = (int) $filters['program_id'];
        }

        return Database::select(
            'SELECT sec.*, p.code AS program_code, yl.name AS year_level_name, yl.sequence AS year_sequence,
                    t.name AS term_name, ay.code AS academic_year_code,
                    CONCAT(adv.first_name, \' \', adv.last_name) AS adviser_name,
                    (SELECT COUNT(*) FROM enrollments e WHERE e.section_id = sec.id AND e.status = \'ENROLLED\') AS enrolled_count
               FROM sections sec
               JOIN programs p        ON p.id  = sec.program_id
               JOIN year_levels yl    ON yl.id = sec.year_level_id
               JOIN terms t           ON t.id  = sec.term_id
               JOIN academic_years ay ON ay.id = t.academic_year_id
          LEFT JOIN users adv         ON adv.id = sec.adviser_user_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY ay.code DESC, t.sequence DESC, p.code, yl.sequence, sec.code',
            $bindings
        );
    }

    /**
     * Active sections an approved application can be confirmed into, with seats.
     *
     * @param array<string,mixed> $application
     * @return array<int,array<string,mixed>>
     */
    public static function sectionsForApplication(array $application): array
    {
        return Database::select(
            "SELECT sec.*, CONCAT(adv.first_name, ' ', adv.last_name) AS adviser_name,
                    (SELECT COUNT(*) FROM enrollments e WHERE e.section_id = sec.id AND e.status = 'ENROLLED') AS enrolled_count
               FROM sections sec
          LEFT JOIN users adv ON adv.id = sec.adviser_user_id
              WHERE sec.term_id = ? AND sec.program_id = ? AND sec.year_level_id = ? AND sec.is_active = 1
              ORDER BY sec.code",
            [$application['term_id'], $application['program_id'], $application['year_level_id']]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function allTerms(): array
    {
        return Database::select(
            'SELECT t.*, ay.code AS academic_year_code FROM terms t JOIN academic_years ay ON ay.id = t.academic_year_id
              ORDER BY ay.code DESC, t.sequence DESC'
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function activeTeachers(): array
    {
        return Database::select(
            "SELECT u.id, u.first_name, u.last_name FROM users u JOIN roles r ON r.id = u.role_id
              WHERE r.name = 'Teacher' AND u.status = 'Active' ORDER BY u.last_name, u.first_name"
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function yearLevels(): array
    {
        return Database::select('SELECT id, name, sequence FROM year_levels ORDER BY sequence');
    }
}
