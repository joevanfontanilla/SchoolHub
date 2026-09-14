<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * The grading rule (docs/27). The ONLY place grade arithmetic happens.
 *
 * Scale: 1.00 (highest) to 3.00 (lowest passing) in steps of 0.25, and 5.00
 * (failed). Special marks: INC (incomplete) and DRP (dropped).
 *
 * Final grade: the weighted average of the period grades.
 *   - Any DRP makes the final DRP; otherwise any INC makes it INC.
 *   - A period with no grade yet leaves the final unknown ("in progress").
 *   - An average above 3.00 is a failure: 5.00. It is never rounded down into
 *     a passing grade.
 *   - An average at or below 3.00 rounds to the nearest 0.25 step; an exact
 *     tie rounds to the higher number, which is the worse grade.
 *
 * Pure: no database, no session. Everything else calls this.
 */
final class GradeCalculator
{
    public const PASSING_LIMIT = 3.00;
    public const FAILED = 5.00;
    public const INC = 'INC';
    public const DRP = 'DRP';

    /** Every numeric grade a teacher may enter. */
    public const STEPS = ['1.00', '1.25', '1.50', '1.75', '2.00', '2.25', '2.50', '2.75', '3.00', '5.00'];

    public const REMARK_PASSED      = 'PASSED';
    public const REMARK_FAILED      = 'FAILED';
    public const REMARK_INC         = 'INC';
    public const REMARK_DRP         = 'DRP';
    public const REMARK_IN_PROGRESS = 'IN_PROGRESS';

    /**
     * Normalize an entered value. Returns ['grade' => '1.75', 'remark' => null],
     * ['grade' => null, 'remark' => 'INC'], or null when the value is not valid.
     * An empty value is not valid here; callers treat blank as "not entered".
     *
     * @return array{grade:?string,remark:?string}|null
     */
    public static function parse(mixed $value): ?array
    {
        $text = strtoupper(trim((string) $value));

        if ($text === self::INC || $text === self::DRP) {
            return ['grade' => null, 'remark' => $text];
        }
        if (!is_numeric($text)) {
            return null;
        }

        $formatted = number_format((float) $text, 2, '.', '');
        // "1.125" would format to "1.13"; refuse anything more precise than two
        // decimals rather than silently rounding what the teacher typed.
        if (abs((float) $text - (float) $formatted) > 0.0001) {
            return null;
        }

        return in_array($formatted, self::STEPS, true) ? ['grade' => $formatted, 'remark' => null] : null;
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
     * @param array<int|string,array{grade:?string,remark:?string}|null> $periodGrades keyed like $weights;
     *        null (or a missing key) means "not graded yet"
     * @param array<int|string,int|float|string> $weights keyed by period
     * @return array{grade:?string,remark:string} grade is null unless numeric
     */
    public static function final(array $periodGrades, array $weights): array
    {
        self::assertWeights($weights);

        $remarks = array_filter(array_map(static fn ($g) => $g['remark'] ?? null, $periodGrades));
        if (in_array(self::DRP, $remarks, true)) {
            return ['grade' => null, 'remark' => self::REMARK_DRP];
        }
        if (in_array(self::INC, $remarks, true)) {
            return ['grade' => null, 'remark' => self::REMARK_INC];
        }

        $sum = 0.0;
        foreach ($weights as $period => $weight) {
            $entry = $periodGrades[$period] ?? null;
            if ($entry === null || $entry['grade'] === null) {
                return ['grade' => null, 'remark' => self::REMARK_IN_PROGRESS];
            }
            $sum += (float) $entry['grade'] * (float) $weight;
        }

        $average = $sum / 100.0;
        $grade = self::roundAverage($average);

        return ['grade' => $grade, 'remark' => (float) $grade <= self::PASSING_LIMIT ? self::REMARK_PASSED : self::REMARK_FAILED];
    }

    /** A weighted average turned into a grade on the scale. */
    public static function roundAverage(float $average): string
    {
        // Work in hundredths of a point, rounded, so floating-point noise such as
        // 3.0000000000000004 does not turn a clean 3.00 into a failure.
        $hundredths = (int) round($average * 100);

        if ($hundredths > 300) {
            return number_format(self::FAILED, 2, '.', '');
        }
        if ($hundredths < 100) {
            $hundredths = 100;
        }

        // Nearest multiple of 25; an exact tie (e.g. 1.125) goes up, to the worse grade.
        $steps = intdiv($hundredths, 25);
        $remainder = $hundredths % 25;
        $rounded = ($remainder * 2 >= 25 ? $steps + 1 : $steps) * 25;

        return number_format(min($rounded, 300) / 100, 2, '.', '');
    }

    /**
     * General weighted average (docs/27): the unit-weighted mean of the numeric
     * finals — passing grades and 5.00 failures — rounded to two decimals.
     * INC, DRP, and in-progress classes have no numeric final and are left out, as
     * are NSTP and physical education, which by school practice carry no weight
     * in the GWA. Null when nothing counts yet.
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

        // Rounded half up in whole hundredths, like the finals themselves.
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

    public static function remarkLabel(string $remark): string
    {
        return match ($remark) {
            self::REMARK_PASSED      => 'Passed',
            self::REMARK_FAILED      => 'Failed',
            self::REMARK_INC         => 'Incomplete',
            self::REMARK_DRP         => 'Dropped',
            default                  => 'In progress',
        };
    }
}
