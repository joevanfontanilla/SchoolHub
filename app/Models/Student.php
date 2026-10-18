<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Student record queries. Every lookup is keyed by the student or by the user
 * who owns it — there is no "find any student" path for student-facing code.
 */
final class Student
{
    /** The stored value => what people read (docs/09). */
    public const TYPE_LABELS = ['New' => 'New student', 'Old' => 'Old student', 'Transferee' => 'Transferee', 'Summer' => 'Summer'];
    public const TYPES = ['New', 'Old', 'Transferee', 'Summer'];

    public const STATUS_APPLICANT = 'Applicant';
    public const STATUS_ENROLLED  = 'Enrolled';

    /** @return array<string,mixed>|null */
    public static function findByUserId(int $userId): ?array
    {
        return Database::selectOne('SELECT * FROM students WHERE user_id = ?', [$userId]);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM students WHERE id = ?', [$id]);
    }

    /**
     * The short line under a student's name in the portal: the section of their
     * latest enrollment ("BSIT-2A"), else their program, else "Applicant".
     */
    public static function portalLabel(int $userId): string
    {
        $row = Database::selectOne(
            "SELECT sec.code AS section_code, p.code AS program_code
               FROM students st
          LEFT JOIN programs p ON p.id = st.program_id
          LEFT JOIN enrollments e ON e.student_id = st.id AND e.status = 'ENROLLED'
          LEFT JOIN terms t ON t.id = e.term_id
          LEFT JOIN sections sec ON sec.id = e.section_id
              WHERE st.user_id = ?
              ORDER BY t.start_date DESC
              LIMIT 1",
            [$userId]
        );
        return (string) ($row['section_code'] ?? $row['program_code'] ?? 'Applicant');
    }

    /** @return array<string,mixed> profile row, or an empty set of fields */
    public static function profile(int $studentId): array
    {
        return Database::selectOne('SELECT * FROM student_profiles WHERE student_id = ?', [$studentId]) ?? [];
    }

    /** @return array<int,array<string,mixed>> */
    public static function guardians(int $studentId): array
    {
        return Database::select(
            'SELECT * FROM guardians WHERE student_id = ? ORDER BY is_emergency_contact DESC, id',
            [$studentId]
        );
    }
}
