<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\User;

/**
 * Programs, subjects, and sections — the academic catalog the registrar
 * maintains (docs/11, docs/13).
 *
 * Nothing here is ever deleted: a program, subject, or section that is no
 * longer offered is deactivated, so every past application and enrollment that
 * refers to it stays intact.
 */
final class CatalogService
{
    // -- Programs --------------------------------------------------------------

    /**
     * @param array<string,mixed> $input
     * @return int the program id
     */
    public static function saveProgram(User $user, ?int $id, array $input): int
    {
        self::assertRegistrar($user);
        $input['code'] = strtoupper(trim((string) ($input['code'] ?? '')));

        $validator = Validator::make($input, [
            'code'           => 'required|max:20|alpha_dash',
            'name'           => 'required|max:150',
            'description'    => 'nullable|max:2000',
            'degree_level'   => 'nullable|max:50',
            'years_duration' => 'required|integer',
        ], ['years_duration' => 'Duration']);

        $years = (int) ($input['years_duration'] ?? 0);
        if ($years < 1 || $years > 6) {
            $validator->addError('years_duration', 'Duration must be between 1 and 6 years.');
        }
        self::assertUniqueCode($validator, 'programs', $input['code'], $id);
        self::throwIfFailed($validator);

        $data = [
            'code'           => $input['code'],
            'name'           => trim((string) $input['name']),
            'description'    => trim((string) ($input['description'] ?? '')) ?: null,
            'degree_level'   => trim((string) ($input['degree_level'] ?? '')) ?: null,
            'years_duration' => $years,
            'is_active'      => !empty($input['is_active']) ? 1 : 0,
        ];

        return self::persist($user, 'programs', 'Program', $id, $data);
    }

    // -- Subjects --------------------------------------------------------------

    /**
     * @param array<string,mixed> $input
     * @return int the subject id
     */
    public static function saveSubject(User $user, ?int $id, array $input): int
    {
        self::assertRegistrar($user);
        $input['code'] = strtoupper(trim((string) ($input['code'] ?? '')));

        $validator = Validator::make($input, [
            'code'          => 'required|max:20|alpha_dash',
            'title'         => 'required|max:150',
            'description'   => 'nullable|max:2000',
            'units'         => 'required|numeric',
            'lecture_hours' => 'nullable|integer',
            'lab_hours'     => 'nullable|integer',
            'program_id'    => 'nullable|integer',
            'year_level_id' => 'nullable|integer',
            'term_sequence' => 'nullable|in:1,2,3',
        ], ['term_sequence' => 'Term offered']);

        $units = (float) ($input['units'] ?? 0);
        // Compare with a tolerance: 2.3 * 10 is 22.999999999999996 in floating point,
        // so an exact fmod() test would wrongly reject a valid 2.3.
        $oneDecimal = abs($units * 10 - round($units * 10)) < 1e-9;
        if ($units <= 0 || $units > 10 || !$oneDecimal) {
            $validator->addError('units', 'Units must be more than 0 and at most 10, with at most one decimal place.');
        }
        foreach (['lecture_hours', 'lab_hours'] as $field) {
            $hours = (int) ($input[$field] ?? 0);
            if ($hours < 0 || $hours > 20) {
                $validator->addError($field, 'Hours must be between 0 and 20.');
            }
        }
        self::assertExists($validator, 'program_id', 'programs', $input['program_id'] ?? null);
        self::assertExists($validator, 'year_level_id', 'year_levels', $input['year_level_id'] ?? null);
        self::assertUniqueCode($validator, 'subjects', $input['code'], $id);

        $prerequisites = array_values(array_unique(array_map('intval', array_filter((array) ($input['prerequisites'] ?? [])))));
        if ($id !== null && in_array($id, $prerequisites, true)) {
            $validator->addError('prerequisites', 'A subject cannot be its own prerequisite.');
        }
        foreach ($prerequisites as $prerequisiteId) {
            if ((int) Database::scalar('SELECT COUNT(*) FROM subjects WHERE id = ?', [$prerequisiteId]) === 0) {
                $validator->addError('prerequisites', 'One of the chosen prerequisites no longer exists.');
                break;
            }
        }
        if ($id !== null && $validator->passes()) {
            $cycle = self::prerequisiteCycle($id, $prerequisites);
            if ($cycle !== null) {
                $validator->addError('prerequisites', $cycle);
            }
        }
        self::throwIfFailed($validator);

        $data = [
            'code'          => $input['code'],
            'title'         => trim((string) $input['title']),
            'description'   => trim((string) ($input['description'] ?? '')) ?: null,
            'units'         => number_format($units, 1, '.', ''),
            'lecture_hours' => (int) ($input['lecture_hours'] ?? 0),
            'lab_hours'     => (int) ($input['lab_hours'] ?? 0),
            'program_id'    => self::nullableInt($input['program_id'] ?? null),
            'year_level_id' => self::nullableInt($input['year_level_id'] ?? null),
            'term_sequence' => self::nullableInt($input['term_sequence'] ?? null),
            'is_active'     => !empty($input['is_active']) ? 1 : 0,
        ];

        return Database::transaction(static function () use ($user, $id, $data, $prerequisites): int {
            $subjectId = self::persist($user, 'subjects', 'Subject', $id, $data);

            Database::query('DELETE FROM subject_prerequisites WHERE subject_id = ?', [$subjectId]);
            foreach ($prerequisites as $prerequisiteId) {
                Database::insert('subject_prerequisites', [
                    'subject_id'              => $subjectId,
                    'prerequisite_subject_id' => $prerequisiteId,
                ]);
            }

            return $subjectId;
        });
    }

    /**
     * Would giving $subjectId these prerequisites create a loop? A new subject
     * cannot be in a loop yet, because nothing can already depend on it.
     *
     * @param array<int,int> $prerequisites
     * @return string|null a message naming the loop, or null
     */
    public static function prerequisiteCycle(int $subjectId, array $prerequisites): ?string
    {
        // Every existing edge except this subject's own, which are being replaced.
        $edges = [];
        foreach (Database::select('SELECT subject_id, prerequisite_subject_id FROM subject_prerequisites WHERE subject_id <> ?', [$subjectId]) as $row) {
            $edges[(int) $row['subject_id']][] = (int) $row['prerequisite_subject_id'];
        }

        // From each new prerequisite, follow prerequisites downward. Reaching the
        // subject itself means it would (indirectly) require itself.
        foreach ($prerequisites as $start) {
            $stack = [$start];
            $seen = [];
            while ($stack !== []) {
                $current = array_pop($stack);
                if ($current === $subjectId) {
                    $codes = array_column(
                        Database::select('SELECT id, code FROM subjects WHERE id IN (?, ?)', [$subjectId, $start]),
                        'code',
                        'id'
                    );
                    return sprintf(
                        '%s already requires %s (directly or through other subjects), so %s cannot be its prerequisite.',
                        $codes[$start] ?? 'That subject',
                        $codes[$subjectId] ?? 'this subject',
                        $codes[$start] ?? 'it'
                    );
                }
                if (isset($seen[$current])) {
                    continue;
                }
                $seen[$current] = true;
                foreach ($edges[$current] ?? [] as $next) {
                    $stack[] = $next;
                }
            }
        }

        return null;
    }

    // -- Sections --------------------------------------------------------------

    /**
     * @param array<string,mixed> $input
     * @return int the section id
     */
    public static function saveSection(User $user, ?int $id, array $input): int
    {
        self::assertRegistrar($user);
        $input['code'] = strtoupper(trim((string) ($input['code'] ?? '')));

        $validator = Validator::make($input, [
            'code'            => 'required|max:20|alpha_dash',
            'program_id'      => 'required|integer',
            'year_level_id'   => 'required|integer',
            'term_id'         => 'required|integer',
            'capacity'        => 'required|integer',
            'adviser_user_id' => 'nullable|integer',
        ], ['program_id' => 'Program', 'year_level_id' => 'Year level', 'term_id' => 'Term', 'adviser_user_id' => 'Adviser']);

        self::assertExists($validator, 'program_id', 'programs', $input['program_id'] ?? null);
        self::assertExists($validator, 'year_level_id', 'year_levels', $input['year_level_id'] ?? null);
        self::assertExists($validator, 'term_id', 'terms', $input['term_id'] ?? null);

        $capacity = (int) ($input['capacity'] ?? 0);
        if ($capacity < 1 || $capacity > 500) {
            $validator->addError('capacity', 'Capacity must be between 1 and 500.');
        }

        $adviserId = self::nullableInt($input['adviser_user_id'] ?? null);
        if ($adviserId !== null) {
            $isTeacher = (int) Database::scalar(
                "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND r.name = 'Teacher' AND u.status = 'Active'",
                [$adviserId]
            ) > 0;
            if (!$isTeacher) {
                $validator->addError('adviser_user_id', 'The adviser must be an active teacher.');
            }
        }

        $duplicate = (int) Database::scalar(
            'SELECT COUNT(*) FROM sections WHERE code = ? AND term_id = ? AND id <> ?',
            [$input['code'], (int) ($input['term_id'] ?? 0), $id ?? 0]
        ) > 0;
        if ($duplicate) {
            $validator->addError('code', 'That section code is already used for this term.');
        }

        if ($id !== null) {
            $existing = Database::selectOne('SELECT * FROM sections WHERE id = ?', [$id]);
            if ($existing === null) {
                throw HttpException::notFound('That section was not found.');
            }
            $enrolled = (int) Database::scalar("SELECT COUNT(*) FROM enrollments WHERE section_id = ? AND status = 'ENROLLED'", [$id]);

            if ($capacity < $enrolled) {
                $validator->addError('capacity', "{$enrolled} students are already enrolled, so capacity cannot be lower than that.");
            }
            $moved = (int) $existing['program_id'] !== (int) ($input['program_id'] ?? 0)
                || (int) $existing['year_level_id'] !== (int) ($input['year_level_id'] ?? 0)
                || (int) $existing['term_id'] !== (int) ($input['term_id'] ?? 0);
            if ($enrolled > 0 && $moved) {
                $validator->addError('program_id', 'Students are enrolled in this section, so its program, year level, and term cannot change.');
            }
        }
        self::throwIfFailed($validator);

        return self::persist($user, 'sections', 'Section', $id, [
            'code'            => $input['code'],
            'program_id'      => (int) $input['program_id'],
            'year_level_id'   => (int) $input['year_level_id'],
            'term_id'         => (int) $input['term_id'],
            'capacity'        => $capacity,
            'adviser_user_id' => $adviserId,
            'is_active'       => !empty($input['is_active']) ? 1 : 0,
        ]);
    }

    // -- Shared ----------------------------------------------------------------

    private static function assertRegistrar(User $user): void
    {
        if (!$user->isRegistrar()) {
            throw HttpException::forbidden('Only the registrar can change the academic catalog.');
        }
    }

    /** @param array<string,mixed> $data */
    private static function persist(User $user, string $table, string $entity, ?int $id, array $data): int
    {
        return Database::transaction(static function () use ($user, $table, $entity, $id, $data): int {
            if ($id === null) {
                $newId = Database::insert($table, $data);
                AuditService::log('academic.' . strtolower($entity) . '_created', 'academic', $entity, $newId, null, $data, 'SUCCESS', null, $user->id, $user->role_name);
                return $newId;
            }

            $before = Database::selectOne("SELECT * FROM `{$table}` WHERE id = ? FOR UPDATE", [$id]);
            if ($before === null) {
                throw HttpException::notFound("That {$entity} was not found.");
            }
            Database::update($table, $data, ['id' => $id]);

            $old = array_intersect_key($before, $data);
            $changedOld = array_filter($old, static fn ($v, $k) => (string) $v !== (string) $data[$k], ARRAY_FILTER_USE_BOTH);
            $changedNew = array_intersect_key($data, $changedOld);
            AuditService::log('academic.' . strtolower($entity) . '_updated', 'academic', $entity, $id, $changedOld ?: null, $changedNew ?: null, 'SUCCESS', null, $user->id, $user->role_name);

            return $id;
        });
    }

    private static function assertUniqueCode(Validator $validator, string $table, string $code, ?int $id): void
    {
        if ($code === '') {
            return;
        }
        $taken = (int) Database::scalar("SELECT COUNT(*) FROM `{$table}` WHERE code = ? AND id <> ?", [$code, $id ?? 0]) > 0;
        if ($taken) {
            $validator->addError('code', "The code {$code} is already in use.");
        }
    }

    private static function assertExists(Validator $validator, string $field, string $table, mixed $value): void
    {
        $id = self::nullableInt($value);
        if ($id !== null && (int) Database::scalar("SELECT COUNT(*) FROM `{$table}` WHERE id = ?", [$id]) === 0) {
            $validator->addError($field, 'Please choose a valid option.');
        }
    }

    private static function nullableInt(mixed $value): ?int
    {
        return ($value === null || $value === '' || (int) $value === 0) ? null : (int) $value;
    }

    private static function throwIfFailed(Validator $validator): void
    {
        if ($validator->fails()) {
            throw ValidationException::withErrors($validator->errors());
        }
    }
}
