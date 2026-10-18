<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * The configurable document list (docs/10).
 */
final class RequirementType
{
    /** @return array<int,array<string,mixed>> */
    public static function active(): array
    {
        return Database::select('SELECT * FROM requirement_types WHERE is_active = 1 ORDER BY sort_order, id');
    }

    /**
     * Documents asked of one student type for one term. A term_requirements row
     * overrides whether the document is required for that term.
     *
     * @return array<int,array<string,mixed>> each row carries an effective is_required
     */
    public static function forApplication(int $termId, string $studentType, ?string $civilStatus = null): array
    {
        return Database::select(
            "SELECT rt.*, COALESCE(tr.is_required, rt.is_required) AS effective_required
               FROM requirement_types rt
          LEFT JOIN term_requirements tr ON tr.requirement_type_id = rt.id AND tr.term_id = ?
              WHERE rt.is_active = 1
                AND (rt.applies_to = 'All' OR FIND_IN_SET(?, rt.applies_to) > 0)
                AND (rt.applies_to_civil_status IS NULL OR FIND_IN_SET(?, rt.applies_to_civil_status) > 0)
              ORDER BY rt.sort_order, rt.id",
            [$termId, $studentType, $civilStatus ?? '']
        );
    }

    /** Who a document is asked of, in words: "New and transferee students", or "Married applicants" when it depends on civil status. @param array<string,mixed> $type */
    public static function appliesLabel(array $type): string
    {
        $status = trim((string) ($type['applies_to_civil_status'] ?? ''));
        if ($status !== '') {
            return ucfirst(strtolower(str_replace(',', ' and ', $status))) . ' applicants';
        }

        return self::appliesToLabel((string) $type['applies_to']);
    }

    /** "New,Transferee" -> "New and transferee students". */
    public static function appliesToLabel(string $appliesTo): string
    {
        if ($appliesTo === 'All') {
            return 'All applicants';
        }
        $types = array_map(static fn ($t) => strtolower(trim($t)), explode(',', $appliesTo));
        $last = array_pop($types);
        $list = $types === [] ? $last : implode(', ', $types) . ' and ' . $last;
        return ucfirst($list) . ' students';
    }

    /** @return array<int,string> */
    public static function extensions(array $type): array
    {
        return array_values(array_filter(array_map(
            static fn ($e) => strtolower(trim($e)),
            explode(',', (string) $type['allowed_extensions'])
        )));
    }
}
