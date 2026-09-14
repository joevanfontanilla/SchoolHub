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
    public const TYPES = ['New', 'Transferee', 'Continuing', 'Returning'];

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
