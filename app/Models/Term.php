<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Academic terms and their enrollment windows.
 */
final class Term
{
    private const SELECT = 'SELECT t.*, a.code AS academic_year_code
                              FROM terms t
                              JOIN academic_years a ON a.id = t.academic_year_id';

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne(self::SELECT . ' WHERE t.id = ?', [$id]);
    }

    /**
     * Terms whose enrollment window contains the current moment.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function openForEnrollment(): array
    {
        return Database::select(
            self::SELECT . ' WHERE NOW() BETWEEN t.enrollment_opens_at AND t.enrollment_closes_at
                             ORDER BY t.start_date'
        );
    }

    /** @param array<string,mixed> $term */
    public static function isOpen(array $term): bool
    {
        if (empty($term['enrollment_opens_at']) || empty($term['enrollment_closes_at'])) {
            return false;
        }
        $now = time();
        return $now >= strtotime((string) $term['enrollment_opens_at'])
            && $now <= strtotime((string) $term['enrollment_closes_at']);
    }

    /** @param array<string,mixed> $term */
    public static function label(array $term): string
    {
        return $term['name'] . ', A.Y. ' . $term['academic_year_code'];
    }
}
