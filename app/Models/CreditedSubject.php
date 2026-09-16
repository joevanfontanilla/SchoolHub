<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Subjects credited to a student without being taken here (transfer credit, or study before
 * the school moved onto this system). Written only through CreditedSubjectService.
 */
final class CreditedSubject
{
    /**
     * The student's credited subjects, newest first, with subject details.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forStudent(int $studentId): array
    {
        return Database::select(
            'SELECT cr.id, cr.subject_id, cr.units, cr.grade, cr.source, cr.remarks, cr.created_at,
                    s.code AS subject_code, s.title AS subject_title,
                    CONCAT(u.first_name, \' \', u.last_name) AS credited_by_name
               FROM credited_subjects cr
               JOIN subjects s ON s.id = cr.subject_id
               JOIN users u    ON u.id = cr.credited_by
              WHERE cr.student_id = ?
              ORDER BY cr.created_at DESC, cr.id DESC',
            [$studentId]
        );
    }

    /**
     * The subject ids credited to a student.
     *
     * @return array<int,int>
     */
    public static function subjectIdsFor(int $studentId): array
    {
        return array_map('intval', array_column(
            Database::select('SELECT subject_id FROM credited_subjects WHERE student_id = ?', [$studentId]),
            'subject_id'
        ));
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM credited_subjects WHERE id = ?', [$id]);
    }

    /**
     * Active subjects that can still be credited to the student: their curriculum's subjects and
     * general subjects, minus ones already credited. Grouped for the picker.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function creditableFor(int $studentId): array
    {
        $student = Database::selectOne('SELECT curriculum_id, program_id FROM students WHERE id = ?', [$studentId]);
        if ($student === null) {
            return [];
        }
        return Database::select(
            'SELECT s.id, s.code, s.title, s.units, c.code AS curriculum_code
               FROM subjects s
          LEFT JOIN curricula c ON c.id = s.curriculum_id
              WHERE s.is_active = 1
                AND (s.curriculum_id = ? OR s.curriculum_id IS NULL OR s.program_id = ? OR s.program_id IS NULL)
                AND s.id NOT IN (SELECT subject_id FROM credited_subjects WHERE student_id = ?)
              ORDER BY c.code IS NULL, c.code, s.code',
            [$student['curriculum_id'], $student['program_id'], $studentId]
        );
    }
}
