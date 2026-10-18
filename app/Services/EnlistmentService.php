<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Models\Curriculum;
use App\Models\SystemSetting;
use App\Models\User;

/**
 * Putting enrolled students into classes (docs/13). The only writer of
 * class_enlistments.
 *
 * Block sections: an enrolled student is enlisted in every class of their
 * section, automatically. A class the student cannot take yet — prerequisite
 * not passed, curriculum progress not reached, unit load exceeded, time clash —
 * is skipped and reported, never silently added. The registrar can then add or
 * drop individual classes; prerequisite, progress, and load problems can be
 * overridden with a recorded reason, a time clash never can.
 */
final class EnlistmentService
{
    public const ENLISTED = 'ENLISTED';
    public const DROPPED  = 'DROPPED';

    /**
     * Enlist one enrollment in every active class of its section.
     *
     * @return array{enlisted:array<int,string>,skipped:array<int,array{code:string,reason:string}>}
     */
    public static function enlistBlock(int $enrollmentId, User $actor): array
    {
        return Database::transaction(static function () use ($enrollmentId, $actor): array {
            $enrollment = self::lockEnrollment($enrollmentId);
            $report = ['enlisted' => [], 'skipped' => []];

            $classes = self::sectionClassesFor($enrollment);

            foreach ($classes as $class) {
                // Already in it, or deliberately dropped by the registrar: leave it alone.
                $existing = Database::scalar(
                    'SELECT status FROM class_enlistments WHERE enrollment_id = ? AND class_schedule_id = ?',
                    [$enrollmentId, $class['id']]
                );
                if ($existing !== null) {
                    continue;
                }

                $problems = self::problems($enrollment, (int) $class['id']);
                if ($problems !== []) {
                    $report['skipped'][] = ['code' => $class['code'], 'reason' => implode(' ', array_column($problems, 'message'))];
                    continue;
                }

                self::insertEnlistment($enrollmentId, (int) $class['id'], $actor, null);
                $report['enlisted'][] = $class['code'];
            }

            self::recomputeUnits($enrollmentId);

            if ($report['enlisted'] !== [] || $report['skipped'] !== []) {
                AuditService::log('enlistment.block', 'academic', 'Enrollment', $enrollmentId, null, $report, 'SUCCESS', null, $actor->id, $actor->role_name);
            }

            return $report;
        });
    }

    /**
     * Enlist every enrolled student of a section in its classes — used when the
     * registrar adds a class to a section that already has students.
     *
     * @return array<int,array{enlisted:array<int,string>,skipped:array<int,array{code:string,reason:string}>}> keyed by enrollment id
     */
    public static function syncSection(int $sectionId, User $actor): array
    {
        $reports = [];
        $enrollments = Database::select("SELECT id FROM enrollments WHERE section_id = ? AND status = 'ENROLLED'", [$sectionId]);
        foreach ($enrollments as $enrollment) {
            $reports[(int) $enrollment['id']] = self::enlistBlock((int) $enrollment['id'], $actor);
        }
        return $reports;
    }

    /**
     * The registrar adds one class for a student.
     *
     * @throws ValidationException|HttpException
     */
    public static function addClass(User $registrar, int $enrollmentId, int $classId, ?string $overrideReason): void
    {
        self::assertRegistrar($registrar);
        $overrideReason = trim((string) $overrideReason);

        Database::transaction(static function () use ($registrar, $enrollmentId, $classId, $overrideReason): void {
            $enrollment = self::lockEnrollment($enrollmentId);
            $class = Database::selectOne(
                'SELECT cs.*, sub.code FROM class_schedules cs JOIN subjects sub ON sub.id = cs.subject_id WHERE cs.id = ?',
                [$classId]
            );
            if ($class === null) {
                throw HttpException::notFound('That class was not found.');
            }
            if ($class['status'] !== 'ACTIVE') {
                throw ValidationException::withMessage("{$class['code']} is closed.");
            }
            if ((int) $class['term_id'] !== (int) $enrollment['term_id']) {
                throw ValidationException::withMessage("{$class['code']} is not offered in this student's term.");
            }
            // Another program's subject is the wrong class for this student, not a rule to override (docs/13).
            $wrongProgram = \App\Models\ClassSchedule::programProblem($enrollmentId, $classId);
            if ($wrongProgram !== null) {
                throw ValidationException::withMessage($wrongProgram);
            }

            $existing = Database::selectOne(
                'SELECT * FROM class_enlistments WHERE enrollment_id = ? AND class_schedule_id = ?',
                [$enrollmentId, $classId]
            );
            if ($existing !== null && $existing['status'] === self::ENLISTED) {
                throw ValidationException::withMessage("The student is already enlisted in {$class['code']}.");
            }

            $problems = self::problems($enrollment, $classId);

            $clashes = array_filter($problems, static fn ($p) => $p['type'] === 'conflict');
            if ($clashes !== []) {
                throw ValidationException::withMessage(implode(' ', array_column($clashes, 'message')) . ' A time conflict cannot be overridden.');
            }
            if ($problems !== [] && $overrideReason === '') {
                throw ValidationException::withErrors(
                    ['override_reason' => ['To enlist anyway, give a reason for the override.']],
                    implode(' ', array_column($problems, 'message'))
                );
            }

            $recordedReason = $problems !== [] ? $overrideReason : null;

            if ($existing !== null) {
                // Re-enlisting after a drop: revive the same row so the history
                // (who dropped it, when, why) stays in the audit log.
                Database::update('class_enlistments', [
                    'status'          => self::ENLISTED,
                    'override_reason' => $recordedReason,
                    'enlisted_at'     => date('Y-m-d H:i:s'),
                    'enlisted_by'     => $registrar->id,
                    'dropped_at'      => null,
                    'dropped_by'      => null,
                    'drop_reason'     => null,
                ], ['id' => $existing['id']]);
                $enlistmentId = (int) $existing['id'];
            } else {
                $enlistmentId = self::insertEnlistment($enrollmentId, $classId, $registrar, $recordedReason);
            }

            self::recomputeUnits($enrollmentId);

            AuditService::log('enlistment.added', 'academic', 'ClassEnlistment', $enlistmentId, null, [
                'class'     => $class['code'],
                'overrides' => array_column($problems, 'type'),
            ], 'SUCCESS', $recordedReason, $registrar->id, $registrar->role_name);
        });
    }

    /** The registrar drops one class for a student. The row is kept as DROPPED. */
    public static function dropClass(User $registrar, int $enlistmentId, string $reason): void
    {
        self::assertRegistrar($registrar);
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withErrors(['drop_reason' => ['Give the reason for dropping this class.']], 'A reason is required to drop a class.');
        }

        Database::transaction(static function () use ($registrar, $enlistmentId, $reason): void {
            $enlistment = Database::selectOne(
                'SELECT ce.*, sub.code FROM class_enlistments ce
                   JOIN class_schedules cs ON cs.id = ce.class_schedule_id
                   JOIN subjects sub       ON sub.id = cs.subject_id
                  WHERE ce.id = ? FOR UPDATE',
                [$enlistmentId]
            );
            if ($enlistment === null) {
                throw HttpException::notFound('That enlistment was not found.');
            }
            if ($enlistment['status'] !== self::ENLISTED) {
                throw ValidationException::withMessage("{$enlistment['code']} is already dropped.");
            }

            Database::update('class_enlistments', [
                'status'      => self::DROPPED,
                'dropped_at'  => date('Y-m-d H:i:s'),
                'dropped_by'  => $registrar->id,
                'drop_reason' => $reason,
            ], ['id' => $enlistmentId]);

            self::recomputeUnits((int) $enlistment['enrollment_id']);

            AuditService::log('enlistment.dropped', 'academic', 'ClassEnlistment', $enlistmentId, ['status' => self::ENLISTED], ['status' => self::DROPPED, 'class' => $enlistment['code']], 'SUCCESS', $reason, $registrar->id, $registrar->role_name);
        });
    }

    /**
     * Why this enrollment cannot simply take this class. Empty means it can.
     *
     * @param array<string,mixed> $enrollment
     * @return array<int,array{type:string,message:string}>
     */
    public static function problems(array $enrollment, int $classId): array
    {
        $problems = [];
        $class = Database::selectOne(
            'SELECT cs.*, sub.code, sub.units, sub.curriculum_id, sub.min_progress_percent
               FROM class_schedules cs JOIN subjects sub ON sub.id = cs.subject_id WHERE cs.id = ?',
            [$classId]
        );
        $studentId = (int) $enrollment['student_id'];

        $missing = self::missingPrerequisites($studentId, (int) $class['subject_id']);
        if ($missing !== []) {
            $problems[] = ['type' => 'prerequisite', 'message' => "Prerequisite not passed: " . implode(', ', $missing) . '.'];
        }

        // "80% of the course": a share of the curriculum's units must be passed first.
        if ($class['min_progress_percent'] !== null && $class['curriculum_id'] !== null) {
            $progress = AcademicRecordService::progressPercent($studentId, (int) $class['curriculum_id']);
            if ($progress < (int) $class['min_progress_percent']) {
                $problems[] = ['type' => 'progress', 'message' => sprintf(
                    '%s requires %d%% of the curriculum passed; the student has %d%%.',
                    $class['code'],
                    $class['min_progress_percent'],
                    $progress
                )];
            }
        }

        $max = self::unitLimit($enrollment);
        $current = (float) Database::scalar(
            "SELECT COALESCE(SUM(sub.units), 0)
               FROM class_enlistments ce
               JOIN class_schedules cs ON cs.id = ce.class_schedule_id
               JOIN subjects sub       ON sub.id = cs.subject_id
              WHERE ce.enrollment_id = ? AND ce.status = 'ENLISTED' AND ce.class_schedule_id <> ?",
            [$enrollment['id'], $classId]
        );
        if ($current + (float) $class['units'] > $max) {
            $problems[] = ['type' => 'load', 'message' => sprintf(
                'Unit load would be %s, over the %s-unit limit.',
                rtrim(rtrim(number_format($current + (float) $class['units'], 1), '0'), '.'),
                rtrim(rtrim(number_format($max, 1), '0'), '.')
            )];
        }

        $clash = ClassScheduleService::studentClash((int) $enrollment['id'], $classId);
        if ($clash !== null) {
            $problems[] = ['type' => 'conflict', 'message' => $clash];
        }

        return $problems;
    }

    /**
     * Section classes this enrollment is not in, and why — for the registrar's
     * "not enlisted" flags. Computed on read; nothing is stored.
     *
     * @return array<int,array{class_id:int,code:string,title:string,dropped:bool,problems:array<int,array{type:string,message:string}>}>
     */
    public static function notEnlisted(int $enrollmentId): array
    {
        $enrollment = Database::selectOne('SELECT * FROM enrollments WHERE id = ?', [$enrollmentId]);
        if ($enrollment === null) {
            return [];
        }

        $chosen = self::chosenSubjectIds($enrollment);
        $rows = Database::select(
            "SELECT cs.id, cs.subject_id, sub.code, sub.title, ce.status AS enlistment_status
               FROM class_schedules cs
               JOIN subjects sub ON sub.id = cs.subject_id
          LEFT JOIN class_enlistments ce ON ce.class_schedule_id = cs.id AND ce.enrollment_id = ?
              WHERE cs.section_id = ? AND cs.status = 'ACTIVE'
                AND (ce.id IS NULL OR ce.status = 'DROPPED')
              ORDER BY sub.code",
            [$enrollmentId, $enrollment['section_id']]
        );

        if ($chosen !== null) {
            $rows = array_values(array_filter($rows, static fn ($row) => in_array((int) $row['subject_id'], $chosen, true)));
        }

        return array_map(static fn (array $row) => [
            'class_id' => (int) $row['id'],
            'code'     => $row['code'],
            'title'    => $row['title'],
            'dropped'  => $row['enlistment_status'] === 'DROPPED',
            'problems' => self::problems($enrollment, (int) $row['id']),
        ], $rows);
    }

    /**
     * The subjects the student chose on their application (docs/09), or null for an
     * enrollment made before subject selection existed, which takes the whole block.
     *
     * @param array<string,mixed> $enrollment
     * @return array<int,int>|null
     */
    public static function chosenSubjectIds(array $enrollment): ?array
    {
        $ids = SubjectSelectionService::chosenIds((int) $enrollment['application_id']);
        return $ids === [] ? null : $ids;
    }

    /**
     * The section's active classes this enrollment should be in: all of them for a
     * block enrollment, or only those for the subjects the student chose.
     *
     * @param array<string,mixed> $enrollment
     * @return array<int,array{id:int,code:string,subject_id:int}>
     */
    private static function sectionClassesFor(array $enrollment): array
    {
        $classes = Database::select(
            "SELECT cs.id, cs.subject_id, sub.code FROM class_schedules cs JOIN subjects sub ON sub.id = cs.subject_id
              WHERE cs.section_id = ? AND cs.status = 'ACTIVE' ORDER BY sub.code",
            [$enrollment['section_id']]
        );
        $chosen = self::chosenSubjectIds($enrollment);
        if ($chosen === null) {
            return $classes;
        }
        return array_values(array_filter($classes, static fn ($class) => in_array((int) $class['subject_id'], $chosen, true)));
    }

    /** @return array<int,string> codes of prerequisites the student has not passed */
    public static function missingPrerequisites(int $studentId, int $subjectId): array
    {
        $prerequisites = Database::select(
            'SELECT s.id, s.code FROM subject_prerequisites sp JOIN subjects s ON s.id = sp.prerequisite_subject_id
              WHERE sp.subject_id = ? ORDER BY s.id',
            [$subjectId]
        );
        if ($prerequisites === []) {
            return [];
        }

        $passed = array_flip(AcademicRecordService::passedSubjectIds($studentId));
        $missing = [];
        foreach ($prerequisites as $prerequisite) {
            if (!isset($passed[(int) $prerequisite['id']])) {
                $missing[] = $prerequisite['code'];
            }
        }
        return $missing;
    }

    /**
     * The most units this enrollment may carry without an override: the
     * max_units_per_term setting, or the regular load its curriculum prescribes
     * for the section's year level and term when that is heavier (docs/28 — BSA
     * first year, first semester is 29 units). Above that is an overload.
     *
     * @param array<string,mixed> $enrollment
     */
    public static function unitLimit(array $enrollment): float
    {
        $limit = (float) SystemSetting::get('max_units_per_term', 27);

        $context = Database::selectOne(
            'SELECT st.curriculum_id, sec.year_level_id, t.sequence AS term_sequence
               FROM enrollments e
               JOIN students st ON st.id = e.student_id
               JOIN sections sec ON sec.id = e.section_id
               JOIN terms t ON t.id = e.term_id
              WHERE e.id = ?',
            [$enrollment['id']]
        );
        if ($context !== null && $context['curriculum_id'] !== null) {
            $limit = max($limit, Curriculum::prescribedUnits((int) $context['curriculum_id'], (int) $context['year_level_id'], (int) $context['term_sequence']));
        }

        return $limit;
    }

    public static function recomputeUnits(int $enrollmentId): void
    {
        $units = (float) Database::scalar(
            "SELECT COALESCE(SUM(sub.units), 0)
               FROM class_enlistments ce
               JOIN class_schedules cs ON cs.id = ce.class_schedule_id
               JOIN subjects sub       ON sub.id = cs.subject_id
              WHERE ce.enrollment_id = ? AND ce.status = 'ENLISTED'",
            [$enrollmentId]
        );
        Database::update('enrollments', ['total_units' => number_format($units, 1, '.', '')], ['id' => $enrollmentId]);
    }

    /** @return array<string,mixed> */
    private static function lockEnrollment(int $enrollmentId): array
    {
        $enrollment = Database::selectOne('SELECT * FROM enrollments WHERE id = ? FOR UPDATE', [$enrollmentId]);
        if ($enrollment === null) {
            throw HttpException::notFound('That enrollment was not found.');
        }
        if ($enrollment['status'] !== 'ENROLLED') {
            throw ValidationException::withMessage('Only an active enrollment can be enlisted in classes.');
        }
        return $enrollment;
    }

    private static function insertEnlistment(int $enrollmentId, int $classId, User $actor, ?string $overrideReason): int
    {
        return Database::insert('class_enlistments', [
            'enrollment_id'     => $enrollmentId,
            'class_schedule_id' => $classId,
            'status'            => self::ENLISTED,
            'override_reason'   => $overrideReason,
            'enlisted_at'       => date('Y-m-d H:i:s'),
            'enlisted_by'       => $actor->id,
        ]);
    }

    private static function assertRegistrar(User $user): void
    {
        if (!$user->isRegistrar()) {
            throw HttpException::forbidden('Only the registrar can change a student\'s classes.');
        }
    }
}
