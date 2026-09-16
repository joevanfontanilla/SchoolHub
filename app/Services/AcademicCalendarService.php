<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\User;

/**
 * The academic calendar: the registrar sets each semester's dates and its enrollment window,
 * and adds the next academic year's semesters. The enrollment window (enrollment_opens_at ..
 * enrollment_closes_at) is what actually opens enrollment for a term (Term::openForEnrollment),
 * so these dates are set by the school, not fixed by the installer. Every change is audited.
 *
 * Governed by `academic.manage`, the same permission as the rest of the catalog.
 */
final class AcademicCalendarService
{
    /** The semesters every academic year has, in order: [sequence => name]. */
    public const SEMESTERS = [1 => '1st Semester', 2 => '2nd Semester', 3 => 'Summer'];

    /**
     * Update one term's dates and enrollment window.
     *
     * @param array<string,mixed> $input start_date, end_date, enrollment_opens_at, enrollment_closes_at
     * @throws ValidationException|HttpException
     */
    public static function saveTerm(User $actor, int $termId, array $input): void
    {
        self::assertCan($actor);

        $term = Database::selectOne('SELECT * FROM terms WHERE id = ?', [$termId]);
        if ($term === null) {
            throw HttpException::notFound('That term was not found.');
        }

        $dates = self::validateDates($input);

        Database::transaction(static function () use ($actor, $termId, $term, $dates): void {
            Database::update('terms', $dates, ['id' => $termId]);
            AuditService::log('academic.term_dates_set', 'academic', 'Term', $termId,
                self::datesOf($term), $dates, 'SUCCESS', null, $actor->id, $actor->role_name);
        });
    }

    /**
     * Add an academic year and its semesters. Each semester carries its own dates and enrollment
     * window; a semester left blank is skipped (a school may not run a summer term).
     *
     * @param array<string,mixed> $input code, and semesters[<sequence>][start_date|end_date|enrollment_opens_at|enrollment_closes_at]
     * @throws ValidationException|HttpException
     */
    public static function addYear(User $actor, array $input): int
    {
        self::assertCan($actor);

        $code = trim((string) ($input['code'] ?? ''));
        $validator = Validator::make(['code' => $code], ['code' => 'required|max:20'], ['code' => 'Academic year']);
        if (!preg_match('/^\d{4}-\d{4}$/', $code)) {
            $validator->addError('code', 'Use the form 2026-2027.');
        } elseif ((int) Database::scalar('SELECT COUNT(*) FROM academic_years WHERE code = ?', [$code]) > 0) {
            $validator->addError('code', 'That academic year already exists.');
        }

        // Each semester's dates are validated only if the registrar filled it in. At least one is required.
        $semesters = (array) ($input['semesters'] ?? []);
        $toCreate = [];
        foreach (self::SEMESTERS as $sequence => $name) {
            $row = (array) ($semesters[$sequence] ?? []);
            if (trim((string) ($row['start_date'] ?? '')) === '' && trim((string) ($row['end_date'] ?? '')) === '') {
                continue;
            }
            try {
                $toCreate[$sequence] = ['name' => $name, 'sequence' => $sequence] + self::validateDates($row, "semesters.{$sequence}.");
            } catch (ValidationException $e) {
                foreach ($e->errors() as $field => $messages) {
                    $validator->addError($field, $messages[0]);
                }
            }
        }
        if ($toCreate === [] && !$validator->fails()) {
            $validator->addError('semesters.1.start_date', 'Fill in at least one semester.');
        }
        if ($validator->fails()) {
            throw ValidationException::withErrors($validator->errors());
        }

        // The academic year spans its earliest term start to its latest term end.
        $starts = array_column($toCreate, 'start_date');
        $ends = array_column($toCreate, 'end_date');

        return Database::transaction(static function () use ($actor, $code, $toCreate, $starts, $ends): int {
            $yearId = Database::insert('academic_years', [
                'code' => $code, 'start_date' => min($starts), 'end_date' => max($ends), 'is_active' => 0,
            ]);
            foreach ($toCreate as $term) {
                $termId = Database::insert('terms', ['academic_year_id' => $yearId] + $term + ['is_active' => 0]);
                GradingService::seedPeriods($termId);
            }
            AuditService::log('academic.year_added', 'academic', 'AcademicYear', $yearId,
                null, ['code' => $code, 'semesters' => array_column($toCreate, 'name')], 'SUCCESS', null, $actor->id, $actor->role_name);
            return $yearId;
        });
    }

    /**
     * Validate a set of term dates and return them ready to write.
     *
     * @param array<string,mixed> $input
     * @param string $prefix error-field prefix, so a year form can point at the right semester
     * @return array{start_date:string,end_date:string,enrollment_opens_at:?string,enrollment_closes_at:?string}
     * @throws ValidationException
     */
    private static function validateDates(array $input, string $prefix = ''): array
    {
        $start   = trim((string) ($input['start_date'] ?? ''));
        $end     = trim((string) ($input['end_date'] ?? ''));
        $opens   = trim((string) ($input['enrollment_opens_at'] ?? ''));
        $closes  = trim((string) ($input['enrollment_closes_at'] ?? ''));
        $errors  = [];

        $isDate = static fn (string $v) => $v !== '' && (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false;
        $isDateTime = static fn (string $v) => $v !== '' && strtotime(str_replace('T', ' ', $v)) !== false;

        if (!$isDate($start)) {
            $errors["{$prefix}start_date"] = ['Enter the start date.'];
        }
        if (!$isDate($end)) {
            $errors["{$prefix}end_date"] = ['Enter the end date.'];
        }
        if ($errors === [] && $start >= $end) {
            $errors["{$prefix}end_date"] = ['The end date must be after the start date.'];
        }

        // The enrollment window is optional but, if given, must be a sensible pair that ends by the
        // time the term ends.
        $opensAt = null;
        $closesAt = null;
        if ($opens !== '' || $closes !== '') {
            if (!$isDateTime($opens)) {
                $errors["{$prefix}enrollment_opens_at"] = ['Enter when enrollment opens, or clear both dates.'];
            }
            if (!$isDateTime($closes)) {
                $errors["{$prefix}enrollment_closes_at"] = ['Enter when enrollment closes, or clear both dates.'];
            }
            if (!isset($errors["{$prefix}enrollment_opens_at"], $errors["{$prefix}enrollment_closes_at"])) {
                $opensAt = date('Y-m-d H:i:s', (int) strtotime(str_replace('T', ' ', $opens)));
                $closesAt = date('Y-m-d H:i:s', (int) strtotime(str_replace('T', ' ', $closes)));
                if ($opensAt >= $closesAt) {
                    $errors["{$prefix}enrollment_closes_at"] = ['Enrollment must close after it opens.'];
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withErrors($errors);
        }

        return [
            'start_date'           => $start,
            'end_date'             => $end,
            'enrollment_opens_at'  => $opensAt,
            'enrollment_closes_at' => $closesAt,
        ];
    }

    /** @param array<string,mixed> $term @return array<string,mixed> the four date fields, for the audit "before" */
    private static function datesOf(array $term): array
    {
        return [
            'start_date'           => $term['start_date'],
            'end_date'             => $term['end_date'],
            'enrollment_opens_at'  => $term['enrollment_opens_at'],
            'enrollment_closes_at' => $term['enrollment_closes_at'],
        ];
    }

    private static function assertCan(User $actor): void
    {
        if (!$actor->hasPermission('academic.manage')) {
            throw HttpException::forbidden('You are not allowed to change the academic calendar.');
        }
    }
}
