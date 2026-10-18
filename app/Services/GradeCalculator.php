<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * The grading rule (docs/27). The ONLY place grade arithmetic happens.
 *
 * The school's Official Grading System is a table of equivalents and the lowest percentage each one stands for: 1.00 is 100,
 * 1.01 is 99.8, ... 3.00 is 75, and anything below 75 is 5.00 (failed). A percentage becomes the equivalent of the highest row
 * whose percentage is at or below it. Marks: INC (incomplete), OW (officially withdrawn), UW (unofficially withdrawn), NGA (no
 * grade due to absences).
 *
 * Final grade: the weighted average of the period PERCENTAGES, rounded to one decimal, then looked up in the table.
 *   - Any OW, UW, NGA, or INC (in that order of precedence) makes the final that mark.
 *   - A period with no grade yet leaves the final unknown ("in progress").
 *
 * Pure: no database, no session. Everything else calls this.
 */
final class GradeCalculator
{
    public const PASSING_LIMIT = 3.00;
    public const FAILED = 5.00;

    /** What a failing equivalent typed without a percentage counts as in the average: the highest failing percentage. */
    public const FAILED_PERCENTAGE = '74.00';

    public const INC = 'INC';
    public const OW  = 'OW';
    public const UW  = 'UW';
    public const NGA = 'NGA';

    /** The marks a teacher may type, in the order they take precedence in a final. */
    public const MARKS = [self::OW, self::UW, self::NGA, self::INC];

    public const REMARK_PASSED      = 'PASSED';
    public const REMARK_FAILED      = 'FAILED';
    public const REMARK_INC         = 'INC';
    public const REMARK_OW          = 'OW';
    public const REMARK_UW          = 'UW';
    public const REMARK_NGA         = 'NGA';
    public const REMARK_IN_PROGRESS = 'IN_PROGRESS';

    /**
     * The Official Grading System: [equivalent, lowest percentage], best first. Printed on the Registrar's "Official Grading
     * System" sheet; every other rule here is built on it.
     */
    public const TABLE = [
        ['1.00', '100'], ['1.01', '99.8'], ['1.02', '99.7'], ['1.03', '99.6'], ['1.04', '99.5'], ['1.05', '99.4'], ['1.06', '99.3'],
        ['1.07', '99.2'], ['1.08', '99.1'], ['1.09', '99.0'], ['1.10', '98.4'], ['1.11', '98.3'], ['1.12', '98.2'], ['1.13', '98.1'],
        ['1.14', '98.0'], ['1.15', '97.4'], ['1.16', '97.3'], ['1.17', '97.2'], ['1.18', '97.1'], ['1.19', '97.0'], ['1.20', '96.4'],
        ['1.21', '96.3'], ['1.22', '96.2'], ['1.23', '96.1'], ['1.24', '96.0'], ['1.25', '95.4'], ['1.26', '95.3'], ['1.27', '95.2'],
        ['1.28', '95.1'], ['1.29', '95.0'], ['1.30', '94.4'], ['1.31', '94.3'], ['1.32', '94.2'], ['1.33', '94.1'], ['1.34', '94.0'],
        ['1.35', '93.4'], ['1.36', '93.3'], ['1.37', '93.2'], ['1.38', '93.1'], ['1.39', '93.0'], ['1.40', '92.4'], ['1.41', '92.3'],
        ['1.42', '92.2'], ['1.43', '92.1'], ['1.44', '92.0'], ['1.45', '91.4'], ['1.46', '91.3'], ['1.47', '91.2'], ['1.48', '91.1'],
        ['1.49', '91.0'],
        ['1.50', '90'], ['1.60', '89'], ['1.70', '88'], ['1.80', '87'], ['1.90', '86'],
        ['2.00', '85'], ['2.10', '84'], ['2.20', '83'], ['2.30', '82'], ['2.40', '81'],
        ['2.50', '80'], ['2.60', '79'], ['2.70', '78'], ['2.80', '77'], ['2.90', '76'],
        ['3.00', '75'],
    ];

    /** The equivalent for a percentage below the lowest row. */
    public const FAILED_EQUIVALENT = '5.00';

    /**
     * Every grade a teacher may type as an equivalent: the table's rows, and 5.00.
     *
     * @return array<int,string>
     */
    public static function equivalents(): array
    {
        return [...array_column(self::TABLE, 0), self::FAILED_EQUIVALENT];
    }

    /** The equivalent for a percentage (0 to 100): the highest row at or below it, or 5.00 below 75. */
    public static function equivalentFor(float|string $percentage): string
    {
        // Tenths of a percent, rounded, so 91.4 is exactly 914 and floating-point noise cannot move a boundary.
        $tenths = (int) round((float) $percentage * 10 + 1e-9);
        foreach (self::TABLE as [$equivalent, $lowest]) {
            if ($tenths >= (int) round((float) $lowest * 10)) {
                return $equivalent;
            }
        }

        return self::FAILED_EQUIVALENT;
    }

    /** The percentage the table prints for an equivalent, or the failed placeholder for 5.00, or null when it is not a grade. */
    public static function percentageFor(float|string $equivalent): ?string
    {
        $formatted = number_format((float) $equivalent, 2, '.', '');
        if ($formatted === self::FAILED_EQUIVALENT) {
            return self::FAILED_PERCENTAGE;
        }
        foreach (self::TABLE as [$row, $lowest]) {
            if ($row === $formatted) {
                return number_format((float) $lowest, 2, '.', '');
            }
        }

        return null;
    }

    /** The word the table puts over an equivalent: Excellent, Very good, Good, Satisfactorily, Passed, or Failed. */
    public static function descriptor(float|string $equivalent): string
    {
        $value = (float) $equivalent;

        return match (true) {
            $value >= 5.0 => 'Failed',
            $value >= 3.0 => 'Passed',
            $value >= 2.5 => 'Satisfactorily',
            $value >= 2.0 => 'Good',
            $value >= 1.5 => 'Very good',
            default       => 'Excellent',
        };
    }

    /**
     * Normalize an entered value. A mark gives ['grade' => null, 'percentage' => null, 'remark' => 'INC']. A percentage (6 to 100,
     * up to two decimals) gives its equivalent and itself. An equivalent from the table (1.00 to 3.00, or 5.00) gives itself and the
     * table's percentage. Anything else is null. An empty value is not valid here; callers treat blank as "not entered".
     *
     * @return array{grade:?string,percentage:?string,remark:?string}|null
     */
    public static function parse(mixed $value): ?array
    {
        $text = strtoupper(trim((string) $value));
        $text = rtrim($text, '%');

        if (in_array($text, self::MARKS, true)) {
            return ['grade' => null, 'percentage' => null, 'remark' => $text];
        }
        if (!is_numeric($text) || !preg_match('/^\d{1,3}(\.\d{1,2})?$/', $text)) {
            return null;
        }

        $number = (float) $text;
        if ($number > 100) {
            return null;
        }
        // Under 6 it can only be an equivalent (the scale runs 1.00 to 5.00); from 6 up it is a percentage.
        if ($number < 6) {
            $formatted = number_format($number, 2, '.', '');
            $percentage = self::percentageFor($formatted);

            return $percentage === null ? null : ['grade' => $formatted, 'percentage' => $percentage, 'remark' => null];
        }

        return ['grade' => self::equivalentFor($number), 'percentage' => number_format($number, 2, '.', ''), 'remark' => null];
    }

    public static function isValid(mixed $value): bool
    {
        return self::parse($value) !== null;
    }

    /**
     * Validate a set of period weights: all positive, totalling exactly 100.
     *
     * @param array<int|string,int|float|string> $weights
     */
    public static function assertWeights(array $weights): void
    {
        if ($weights === []) {
            throw new InvalidArgumentException('At least one grading period is needed.');
        }
        $total = 0.0;
        foreach ($weights as $weight) {
            if (!is_numeric($weight) || (float) $weight <= 0) {
                throw new InvalidArgumentException('Every period weight must be greater than zero.');
            }
            $total += (float) $weight;
        }
        if (abs($total - 100.0) > 0.001) {
            throw new InvalidArgumentException(sprintf('The period weights must total 100%% (they total %s%%).', rtrim(rtrim(number_format($total, 2), '0'), '.')));
        }
    }

    /**
     * The final grade for one student in one class.
     *
     * @param array<int|string,array{grade:?string,remark:?string,percentage?:?string}|null> $periodGrades keyed like $weights;
     *        null (or a missing key) means "not graded yet". A grade saved before percentages existed has none: the table's
     *        percentage for its equivalent is used.
     * @param array<int|string,int|float|string> $weights keyed by period
     * @return array{grade:?string,percentage:?string,remark:string} grade and percentage are null unless numeric
     */
    public static function final(array $periodGrades, array $weights): array
    {
        self::assertWeights($weights);

        $remarks = array_filter(array_map(static fn ($g) => $g['remark'] ?? null, $periodGrades));
        foreach (self::MARKS as $mark) {
            if (in_array($mark, $remarks, true)) {
                return ['grade' => null, 'percentage' => null, 'remark' => $mark];
            }
        }

        $sum = 0.0;
        foreach ($weights as $period => $weight) {
            $entry = $periodGrades[$period] ?? null;
            if ($entry === null || $entry['grade'] === null) {
                return ['grade' => null, 'percentage' => null, 'remark' => self::REMARK_IN_PROGRESS];
            }
            $percentage = $entry['percentage'] ?? self::percentageFor($entry['grade']) ?? self::FAILED_PERCENTAGE;
            $sum += (float) $percentage * (float) $weight;
        }

        // The weighted percentage, to one decimal (half up), then the table.
        $average = round($sum / 100.0 + 1e-9, 1);
        $grade = self::equivalentFor($average);

        return [
            'grade'      => $grade,
            'percentage' => number_format($average, 1, '.', ''),
            'remark'     => (float) $grade <= self::PASSING_LIMIT ? self::REMARK_PASSED : self::REMARK_FAILED,
        ];
    }

    /**
     * General weighted average (docs/27): the unit-weighted mean of the numeric
     * finals (equivalents), rounded to two decimals. Marks and in-progress classes
     * have no numeric final and are left out, as are NSTP and physical education,
     * which by school practice carry no weight in the GWA. Null when nothing counts yet.
     *
     * @param array<int,array{code:string,units:mixed,final:array{grade:?string,remark:string}}> $classes
     */
    public static function gwa(array $classes): ?string
    {
        $points = 0.0;
        $units = 0.0;
        foreach ($classes as $class) {
            $grade = $class['final']['grade'] ?? null;
            if ($grade === null || !self::countsTowardGwa((string) $class['code'])) {
                continue;
            }
            $points += (float) $grade * (float) $class['units'];
            $units += (float) $class['units'];
        }
        if ($units <= 0) {
            return null;
        }

        // Rounded half up in whole hundredths.
        return number_format(round($points / $units + 1e-9, 2), 2, '.', '');
    }

    /**
     * Whether a subject counts toward the GWA. NSTP (and its components CWTS,
     * LTS, ROTC) and physical education (PATH-FIT, PE n, PED n) do not.
     */
    public static function countsTowardGwa(string $code): bool
    {
        $compact = preg_replace('/[\s._-]+/', '', strtoupper($code));
        return !preg_match('/^(NSTP|CWTS|LTS|ROTC|PATHFIT|PED?\d)/', (string) $compact);
    }

    /** A grade for display: "1.40 (92.4%)", or just the equivalent when no percentage is known, or the mark. @param array<string,mixed>|null $entry */
    public static function display(?array $entry, bool $withPercentage = true): string
    {
        if ($entry === null) {
            return '—';
        }
        if (!empty($entry['remark'])) {
            return (string) $entry['remark'];
        }
        if (($entry['grade'] ?? null) === null) {
            return '—';
        }
        $percentage = $entry['percentage'] ?? null;

        return $withPercentage && $percentage !== null
            ? $entry['grade'] . ' (' . rtrim(rtrim(number_format((float) $percentage, 2, '.', ''), '0'), '.') . '%)'
            : (string) $entry['grade'];
    }

    public static function remarkLabel(string $remark): string
    {
        return match ($remark) {
            self::REMARK_PASSED      => 'Passed',
            self::REMARK_FAILED      => 'Failed',
            self::REMARK_INC         => 'Incomplete',
            self::REMARK_OW          => 'Officially withdrawn',
            self::REMARK_UW          => 'Unofficially withdrawn',
            self::REMARK_NGA         => 'No grade due to absences',
            default                  => 'In progress',
        };
    }
}
