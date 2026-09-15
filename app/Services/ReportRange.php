<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The date range of a report (docs/15): two Y-m-d dates from the query string, checked.
 * A bad, reversed, or too-long range falls back to the report's default with a message,
 * never an error page, so a mistyped date can't scan years of records.
 */
final class ReportRange
{
    /** Longest range a report covers. */
    public const MAX_DAYS = 366;

    /**
     * @return array{0:string,1:string,2:?string} from, to, and the message when the default was used
     */
    public static function parse(mixed $from, mixed $to, string $defaultFrom, string $defaultTo, string $defaultLabel): array
    {
        $from = is_string($from) && $from !== '' ? $from : $defaultFrom;
        $to = is_string($to) && $to !== '' ? $to : $defaultTo;

        if (!self::isDate($from) || !self::isDate($to)) {
            return [$defaultFrom, $defaultTo, "Enter dates as YYYY-MM-DD. Showing {$defaultLabel} instead."];
        }
        if ($from > $to) {
            return [$defaultFrom, $defaultTo, "The start date is after the end date. Showing {$defaultLabel} instead."];
        }
        if ((new \DateTime($from))->diff(new \DateTime($to))->days >= self::MAX_DAYS) {
            return [$defaultFrom, $defaultTo, "A report can cover at most one year. Showing {$defaultLabel} instead."];
        }
        return [$from, $to, null];
    }

    public static function isDate(string $value): bool
    {
        $parsed = \DateTime::createFromFormat('!Y-m-d', $value);
        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }
}
