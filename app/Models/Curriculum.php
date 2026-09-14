<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Read queries for curricula (docs/28). Writes go through CatalogService and
 * CurriculumImporter.
 */
final class Curriculum
{
    public const TERM_NAMES = [1 => '1st Semester', 2 => '2nd Semester', 3 => 'Summer'];

    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        return Database::select(
            'SELECT c.*, p.code AS program_code, p.name AS program_name,
                    (SELECT COUNT(*) FROM subjects s WHERE s.curriculum_id = c.id AND s.is_active = 1) AS subject_count,
                    (SELECT COALESCE(SUM(s.units), 0) FROM subjects s WHERE s.curriculum_id = c.id AND s.is_active = 1) AS total_units,
                    (SELECT COUNT(*) FROM students st WHERE st.curriculum_id = c.id) AS student_count
               FROM curricula c
               JOIN programs p ON p.id = c.program_id
              ORDER BY c.is_active DESC, p.code, c.effective_school_year DESC, c.code'
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne(
            'SELECT c.*, p.code AS program_code, p.name AS program_name
               FROM curricula c JOIN programs p ON p.id = c.program_id
              WHERE c.id = ?',
            [$id]
        );
    }

    /**
     * The curriculum a newly enrolled student of this program is placed under:
     * the active one with the latest effective school year.
     *
     * @return array<string,mixed>|null
     */
    public static function currentForProgram(int $programId): ?array
    {
        return Database::selectOne(
            'SELECT * FROM curricula WHERE program_id = ? AND is_active = 1
              ORDER BY effective_school_year DESC, id DESC LIMIT 1',
            [$programId]
        );
    }

    /**
     * The curriculum laid out by year and term, as printed on the school's
     * document: each term with its subjects, prerequisite codes, and unit total.
     *
     * @return array<int,array{year:int,year_name:string,term:int,term_name:string,units:float,subjects:array<int,array<string,mixed>>}>
     */
    public static function grid(int $curriculumId): array
    {
        $subjects = Database::select(
            "SELECT s.*, yl.sequence AS year_sequence, yl.name AS year_level_name,
                    (SELECT GROUP_CONCAT(pre.code ORDER BY pre.code SEPARATOR ', ')
                       FROM subject_prerequisites sp JOIN subjects pre ON pre.id = sp.prerequisite_subject_id
                      WHERE sp.subject_id = s.id) AS prerequisite_codes
               FROM subjects s
          LEFT JOIN year_levels yl ON yl.id = s.year_level_id
              WHERE s.curriculum_id = ?
              ORDER BY yl.sequence IS NULL, yl.sequence, s.term_sequence IS NULL, s.term_sequence, s.id",
            [$curriculumId]
        );

        $terms = [];
        foreach ($subjects as $subject) {
            $year = (int) ($subject['year_sequence'] ?? 0);
            $term = (int) ($subject['term_sequence'] ?? 0);
            $key = $year * 10 + $term;

            $terms[$key] ??= [
                'year'      => $year,
                'year_name' => $subject['year_level_name'] ?? 'Unscheduled',
                'term'      => $term,
                'term_name' => self::TERM_NAMES[$term] ?? 'Any term',
                'units'     => 0.0,
                'subjects'  => [],
            ];
            $terms[$key]['subjects'][] = $subject;
            if ((int) $subject['is_active'] === 1) {
                $terms[$key]['units'] += (float) $subject['units'];
            }
        }

        return array_values($terms);
    }

    /**
     * The regular load of one term of a curriculum: the units of its active
     * subjects for that year level and term.
     */
    public static function prescribedUnits(int $curriculumId, int $yearLevelId, int $termSequence): float
    {
        return (float) Database::scalar(
            'SELECT COALESCE(SUM(units), 0) FROM subjects
              WHERE curriculum_id = ? AND year_level_id = ? AND term_sequence = ? AND is_active = 1',
            [$curriculumId, $yearLevelId, $termSequence]
        );
    }

    public static function totalUnits(int $curriculumId): float
    {
        return (float) Database::scalar(
            'SELECT COALESCE(SUM(units), 0) FROM subjects WHERE curriculum_id = ? AND is_active = 1',
            [$curriculumId]
        );
    }

    /** @return array<int,array<string,mixed>> active curricula with their program, for pickers */
    public static function options(): array
    {
        return Database::select(
            'SELECT c.id, c.code, c.name, p.code AS program_code
               FROM curricula c JOIN programs p ON p.id = c.program_id
              WHERE c.is_active = 1
              ORDER BY p.code, c.effective_school_year DESC'
        );
    }
}
