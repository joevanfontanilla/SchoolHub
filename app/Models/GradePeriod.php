<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Read queries for grading periods (docs/27). Opening, closing, and weights
 * change only through GradingService.
 */
final class GradePeriod
{
    /**
     * A term's periods with who last opened or closed each and how many grades it holds.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forTerm(int $termId): array
    {
        return Database::select(
            "SELECT gp.*,
                    CONCAT(ou.first_name, ' ', ou.last_name) AS opened_by_name,
                    CONCAT(cu.first_name, ' ', cu.last_name) AS closed_by_name,
                    (SELECT COUNT(*) FROM grades g WHERE g.grade_period_id = gp.id) AS grade_count
               FROM grade_periods gp
          LEFT JOIN users ou ON ou.id = gp.opened_by
          LEFT JOIN users cu ON cu.id = gp.closed_by
              WHERE gp.term_id = ?
              ORDER BY gp.sequence",
            [$termId]
        );
    }

    /**
     * Open periods of the terms a teacher has active classes in.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function openForTeacher(int $teacherId): array
    {
        return Database::select(
            "SELECT DISTINCT gp.*, t.name AS term_name
               FROM grade_periods gp
               JOIN terms t ON t.id = gp.term_id
               JOIN class_schedules cs ON cs.term_id = gp.term_id AND cs.teacher_user_id = ? AND cs.status = 'ACTIVE'
              WHERE gp.is_open = 1
              ORDER BY t.start_date, gp.sequence",
            [$teacherId]
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM grade_periods WHERE id = ?', [$id]);
    }
}
