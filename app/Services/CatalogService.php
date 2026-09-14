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

    // -- Curricula -------------------------------------------------------------

    /**
     * @param array<string,mixed> $input
     * @return int the curriculum id
     */
    public static function saveCurriculum(User $user, ?int $id, array $input): int
    {
        self::assertRegistrar($user);
        $input['code'] = strtoupper(trim((string) ($input['code'] ?? '')));
        $schoolYear = trim((string) ($input['effective_school_year'] ?? ''));

        $validator = Validator::make($input, [
            'program_id'            => 'required|integer',
            'code'                  => 'required|max:30|alpha_dash',
            'name'                  => 'required|max:150',
            'effective_school_year' => 'required',
            'notes'                 => 'nullable|max:255',
        ], ['program_id' => 'Program', 'effective_school_year' => 'Effective school year']);

        self::assertExists($validator, 'program_id', 'programs', $input['program_id'] ?? null);
        if ($schoolYear !== '' && ($problem = self::schoolYearProblem($schoolYear)) !== null) {
            $validator->addError('effective_school_year', $problem);
        }
        self::assertUniqueCode($validator, 'curricula', $input['code'], $id);

        if ($id !== null) {
            $existing = Database::selectOne('SELECT * FROM curricula WHERE id = ?', [$id]);
            if ($existing === null) {
                throw HttpException::notFound('That curriculum was not found.');
            }
            $inUse = (int) Database::scalar(
                'SELECT (SELECT COUNT(*) FROM subjects WHERE curriculum_id = ?) + (SELECT COUNT(*) FROM students WHERE curriculum_id = ?)',
                [$id, $id]
            );
            if ($inUse > 0 && (int) $existing['program_id'] !== (int) ($input['program_id'] ?? 0)) {
                $validator->addError('program_id', 'This curriculum already has subjects or students, so its program cannot change.');
            }
        }
        self::throwIfFailed($validator);

        return self::persist($user, 'curricula', 'Curriculum', $id, [
            'program_id'            => (int) $input['program_id'],
            'code'                  => $input['code'],
            'name'                  => trim((string) $input['name']),
            'effective_school_year' => $schoolYear,
            'notes'                 => trim((string) ($input['notes'] ?? '')) ?: null,
            'is_active'             => !empty($input['is_active']) ? 1 : 0,
        ]);
    }

    // -- Subjects --------------------------------------------------------------

    /**
     * A subject belongs to one curriculum, or to none (a general subject). The
     * curriculum is chosen when the subject is created and never changes: moving
     * it would silently change the meaning of every class and grade built on it.
     *
     * @param array<string,mixed> $input
     * @return int the subject id
     */
    public static function saveSubject(User $user, ?int $id, array $input): int
    {
        self::assertRegistrar($user);
        $input['code'] = self::normalizeCode((string) ($input['code'] ?? ''));

        $existing = null;
        if ($id !== null) {
            $existing = Database::selectOne('SELECT * FROM subjects WHERE id = ?', [$id]);
            if ($existing === null) {
                throw HttpException::notFound('That subject was not found.');
            }
        }

        $validator = Validator::make($input, [
            'code'                 => 'required|max:20',
            'title'                => 'required|max:150',
            'description'          => 'nullable|max:2000',
            'units'                => 'required|numeric',
            'lab_units'            => 'nullable|numeric',
            'lecture_hours'        => 'nullable|integer',
            'lab_hours'            => 'nullable|integer',
            'curriculum_id'        => 'nullable|integer',
            'year_level_id'        => 'nullable|integer',
            'term_sequence'        => 'nullable|in:1,2,3',
            'min_progress_percent' => 'nullable|integer',
        ], ['term_sequence' => 'Term offered', 'lab_units' => 'Lab units', 'min_progress_percent' => 'Required progress']);

        if ($input['code'] !== '' && ($problem = self::subjectCodeProblem($input['code'])) !== null) {
            $validator->addError('code', $problem);
        }

        $units = (float) ($input['units'] ?? 0);
        if (($problem = self::unitsProblem($units)) !== null) {
            $validator->addError('units', $problem);
        }
        $labUnits = (float) ($input['lab_units'] ?? 0);
        if ($labUnits < 0 || $labUnits > $units || abs($labUnits * 10 - round($labUnits * 10)) >= 1e-9) {
            $validator->addError('lab_units', 'Lab units must be between 0 and the subject\'s units, with at most one decimal place.');
        }
        foreach (['lecture_hours', 'lab_hours'] as $field) {
            $hours = (int) ($input[$field] ?? 0);
            if ($hours < 0 || $hours > 20) {
                $validator->addError($field, 'Hours must be between 0 and 20.');
            }
        }
        $progress = self::nullableInt($input['min_progress_percent'] ?? null);
        if ($progress !== null && ($progress < 1 || $progress > 100)) {
            $validator->addError('min_progress_percent', 'Required progress must be from 1 to 100 percent, or blank.');
        }

        // Fixed after creation, whatever the form sends.
        $curriculumId = $existing !== null
            ? ($existing['curriculum_id'] === null ? null : (int) $existing['curriculum_id'])
            : self::nullableInt($input['curriculum_id'] ?? null);
        $curriculum = $curriculumId === null ? null : Database::selectOne('SELECT * FROM curricula WHERE id = ?', [$curriculumId]);
        if ($curriculumId !== null && $curriculum === null) {
            $validator->addError('curriculum_id', 'Please choose a valid curriculum.');
        }

        self::assertExists($validator, 'year_level_id', 'year_levels', $input['year_level_id'] ?? null);

        if ($input['code'] !== '') {
            $taken = (int) Database::scalar(
                'SELECT COUNT(*) FROM subjects WHERE code = ? AND curriculum_id <=> ? AND id <> ?',
                [$input['code'], $curriculumId, $id ?? 0]
            ) > 0;
            if ($taken) {
                $validator->addError('code', $curriculum === null
                    ? "The code {$input['code']} is already used by another general subject."
                    : "The code {$input['code']} is already used in {$curriculum['code']}.");
            }
        }

        $prerequisites = array_values(array_unique(array_map('intval', array_filter((array) ($input['prerequisites'] ?? [])))));
        if ($id !== null && in_array($id, $prerequisites, true)) {
            $validator->addError('prerequisites', 'A subject cannot be its own prerequisite.');
        }
        foreach ($prerequisites as $prerequisiteId) {
            $prerequisite = Database::selectOne('SELECT code, curriculum_id FROM subjects WHERE id = ?', [$prerequisiteId]);
            if ($prerequisite === null) {
                $validator->addError('prerequisites', 'One of the chosen prerequisites no longer exists.');
                break;
            }
            // From the same curriculum, or a general subject.
            if ($prerequisite['curriculum_id'] !== null && (int) $prerequisite['curriculum_id'] !== $curriculumId) {
                $validator->addError('prerequisites', "{$prerequisite['code']} belongs to a different curriculum.");
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
            'code'                 => $input['code'],
            'title'                => trim((string) $input['title']),
            'description'          => trim((string) ($input['description'] ?? '')) ?: null,
            'units'                => number_format($units, 1, '.', ''),
            'lab_units'            => number_format($labUnits, 1, '.', ''),
            'lecture_hours'        => (int) ($input['lecture_hours'] ?? 0),
            'lab_hours'            => (int) ($input['lab_hours'] ?? 0),
            'program_id'           => $curriculum === null ? null : (int) $curriculum['program_id'],
            'curriculum_id'        => $curriculumId,
            'year_level_id'        => self::nullableInt($input['year_level_id'] ?? null),
            'term_sequence'        => self::nullableInt($input['term_sequence'] ?? null),
            'min_progress_percent' => $progress,
            'is_active'            => !empty($input['is_active']) ? 1 : 0,
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

    // -- Rooms -----------------------------------------------------------------

    /**
     * @param array<string,mixed> $input
     * @return int the room id
     */
    public static function saveRoom(User $user, ?int $id, array $input): int
    {
        self::assertRegistrar($user);
        $input['code'] = strtoupper(trim((string) ($input['code'] ?? '')));

        $validator = Validator::make($input, [
            'code'     => 'required|max:20|alpha_dash',
            'name'     => 'required|max:100',
            'building' => 'nullable|max:100',
            'capacity' => 'required|integer',
            'type'     => 'required|in:Lecture,Laboratory,Computer Laboratory,Gymnasium,Other',
        ]);
        $capacity = (int) ($input['capacity'] ?? 0);
        if ($capacity < 1 || $capacity > 1000) {
            $validator->addError('capacity', 'Capacity must be between 1 and 1000.');
        }
        self::assertUniqueCode($validator, 'rooms', $input['code'], $id);
        self::throwIfFailed($validator);

        return self::persist($user, 'rooms', 'Room', $id, [
            'code'      => $input['code'],
            'name'      => trim((string) $input['name']),
            'building'  => trim((string) ($input['building'] ?? '')) ?: null,
            'capacity'  => $capacity,
            'type'      => (string) $input['type'],
            'is_active' => !empty($input['is_active']) ? 1 : 0,
        ]);
    }

    // -- Shared rules (also used by CurriculumImporter) --------------------------

    /** Subject codes are stored upper-case with single spaces: "pub  ad 2" is "PUB AD 2". */
    public static function normalizeCode(string $code): string
    {
        return strtoupper(trim((string) preg_replace('/\s+/', ' ', $code)));
    }

    /** Why a (normalized) subject code is not acceptable, or null. */
    public static function subjectCodeProblem(string $code): ?string
    {
        if ($code === '' || mb_strlen($code) > 20) {
            return 'The code is required and at most 20 characters.';
        }
        if (!preg_match('/^[A-Z0-9][A-Z0-9 ._-]*$/', $code)) {
            return 'The code may contain only letters, numbers, spaces, dots, dashes, and underscores, and must start with a letter or number.';
        }
        return null;
    }

    /** Why a unit value is not acceptable, or null. */
    public static function unitsProblem(float $units): ?string
    {
        // Compare with a tolerance: 2.3 * 10 is 22.999999999999996 in floating point,
        // so an exact fmod() test would wrongly reject a valid 2.3.
        $oneDecimal = abs($units * 10 - round($units * 10)) < 1e-9;
        if ($units <= 0 || $units > 10 || !$oneDecimal) {
            return 'Units must be more than 0 and at most 10, with at most one decimal place.';
        }
        return null;
    }

    /** "2024-2025": two consecutive years. Returns why not, or null. */
    public static function schoolYearProblem(string $schoolYear): ?string
    {
        if (!preg_match('/^(\d{4})-(\d{4})$/', $schoolYear, $m) || (int) $m[2] !== (int) $m[1] + 1 || (int) $m[1] < 1990 || (int) $m[1] > 2100) {
            return 'Write the school year as two consecutive years, for example 2024-2025.';
        }
        return null;
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
