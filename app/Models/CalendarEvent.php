<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Read queries for the school academic calendar (docs/09). Writing goes through
 * App\Services\SchoolCalendarService.
 */
final class CalendarEvent
{
    /**
     * Categories are ordered First Semester, Second Semester, Summer, then anything else
     * (no-class days and custom sections), so the calendar reads like the printed one.
     */
    private const CATEGORY_ORDER = "CASE
        WHEN category = 'First Semester' THEN 1
        WHEN category = 'Second Semester' THEN 2
        WHEN category = 'Summer' THEN 3
        ELSE 4 END";

    /** Every academic year that has entries, newest first. @return array<int,string> */
    public static function years(): array
    {
        return array_column(
            Database::select('SELECT DISTINCT academic_year FROM calendar_events ORDER BY academic_year DESC'),
            'academic_year'
        );
    }

    /** The newest academic year with entries, or null when the calendar is empty. */
    public static function latestYear(): ?string
    {
        $years = self::years();
        return $years[0] ?? null;
    }

    /**
     * Entries of one academic year, in reading order.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forYear(string $academicYear): array
    {
        return Database::select(
            'SELECT * FROM calendar_events WHERE academic_year = ?
              ORDER BY ' . self::CATEGORY_ORDER . ', sort_date IS NULL, sort_date, id',
            [$academicYear]
        );
    }

    /** One entry, for editing. @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM calendar_events WHERE id = ?', [$id]);
    }

    /**
     * The entries of an academic year grouped by category, in reading order.
     *
     * @return array<int,array{category:string,rows:array<int,array<string,mixed>>}>
     */
    public static function grouped(string $academicYear): array
    {
        $groups = [];
        foreach (self::forYear($academicYear) as $row) {
            $category = (string) $row['category'];
            if (!isset($groups[$category])) {
                $groups[$category] = ['category' => $category, 'rows' => []];
            }
            $groups[$category]['rows'][] = $row;
        }
        return array_values($groups);
    }
}
