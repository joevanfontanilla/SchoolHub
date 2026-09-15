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
    public static function subjects(?int $programId = null, ?int $curriculumId = null): array
    {
        $where = ['1 = 1'];
        $bindings = [];
        if ($curriculumId !== null) {
            $where[] = 's.curriculum_id = ?';
            $bindings[] = $curriculumId;
        } elseif ($programId !== null) {
            $where[] = '(s.program_id = ? OR s.program_id IS NULL)';
            $bindings[] = $programId;
        }

        return Database::select(
            "SELECT s.*, p.code AS program_code, c.code AS curriculum_code, yl.name AS year_level_name,
                    (SELECT GROUP_CONCAT(pre.code ORDER BY pre.code SEPARATOR ', ')
                       FROM subject_prerequisites sp JOIN subjects pre ON pre.id = sp.prerequisite_subject_id
                      WHERE sp.subject_id = s.id) AS prerequisite_codes
               FROM subjects s
          LEFT JOIN programs p     ON p.id  = s.program_id
          LEFT JOIN curricula c    ON c.id  = s.curriculum_id
          LEFT JOIN year_levels yl ON yl.id = s.year_level_id
              WHERE " . implode(' AND ', $where) . "
              ORDER BY s.is_active DESC, p.code IS NULL, p.code, c.code, yl.sequence, s.term_sequence, s.id",
            $bindings
        );
    }

    /** @return array<string,mixed>|null one section with its program, year level, and term */
    public static function section(int $id): ?array
    {
        return Database::selectOne(
            'SELECT sec.*, p.code AS program_code, yl.name AS year_level_name,
                    t.name AS term_name, t.sequence AS term_sequence, ay.code AS academic_year_code
               FROM sections sec
               JOIN programs p        ON p.id  = sec.program_id
               JOIN year_levels yl    ON yl.id = sec.year_level_id
               JOIN terms t           ON t.id  = sec.term_id
               JOIN academic_years ay ON ay.id = t.academic_year_id
              WHERE sec.id = ?',
            [$id]
        );
    }

    /**
     * Subjects that can still become classes of a section: active, from its
     * program's curricula or general, and not already one of its classes. Subjects
     * the curriculum places in the section's own year and term come first.
     *
     * @param array<string,mixed> $section a row from section()
     * @param array<int,mixed>    $taken   subject ids the section already has
     * @return array<int,array<string,mixed>>
     */
    public static function subjectsForSection(array $section, array $taken): array
    {
        $rows = Database::select(
            'SELECT s.id, s.code, s.title, s.units, c.code AS curriculum_code,
                    (s.year_level_id <=> ? AND s.term_sequence <=> ?) AS matches_term
               FROM subjects s
          LEFT JOIN curricula c ON c.id = s.curriculum_id
              WHERE s.is_active = 1 AND (s.program_id = ? OR s.program_id IS NULL)
              ORDER BY matches_term DESC, c.code IS NULL, c.code, s.year_level_id, s.term_sequence, s.id',
            [$section['year_level_id'], $section['term_sequence'], $section['program_id']]
        );
        $taken = array_map('intval', $taken);
        return array_values(array_filter($rows, static fn ($row) => !in_array((int) $row['id'], $taken, true)));
    }

    /**
     * Subjects a subject in this curriculum may require: its own curriculum's
     * subjects and the general ones.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function prerequisiteOptions(?int $curriculumId, ?int $exceptId): array
    {
        return Database::select(
            'SELECT s.id, s.code, s.title, s.curriculum_id
               FROM subjects s
          LEFT JOIN year_levels yl ON yl.id = s.year_level_id
              WHERE (s.curriculum_id <=> ? OR s.curriculum_id IS NULL) AND s.id <> ?
              ORDER BY s.curriculum_id IS NULL, yl.sequence, s.term_sequence, s.id',
            [$curriculumId, $exceptId ?? 0]
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

    /** @return array<int,array<string,mixed>> every program, active ones first (for choosing a curriculum's program) */
    public static function allPrograms(): array
    {
        return Database::select('SELECT id, code, name FROM programs ORDER BY is_active DESC, code');
    }

    /** One catalog row by id. Only the catalog tables can be named. */
    public static function row(string $table, int $id): ?array
    {
        if (!in_array($table, ['programs', 'curricula', 'subjects', 'sections'], true)) {
            throw new \InvalidArgumentException("Not a catalog table: {$table}");
        }
        return Database::selectOne("SELECT * FROM `{$table}` WHERE id = ?", [$id]);
    }

    public static function enrolledInSection(int $sectionId): int
    {
        return (int) Database::scalar("SELECT COUNT(*) FROM enrollments WHERE section_id = ? AND status = 'ENROLLED'", [$sectionId]);
    }
}
