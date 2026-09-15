<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Models\Curriculum;
use App\Models\EnrollmentApplication as App;
use App\Models\SystemSetting;
use App\Models\User;

/**
 * The subjects a student takes, chosen on the enrollment application (docs/09).
 *
 * Only subjects of the student's own program curriculum for the year level and
 * term applied for are offered. Each is checked against the student's record:
 *   - already passed         -> not offered again;
 *   - a prerequisite not yet passed -> shown, but cannot be chosen;
 *   - otherwise              -> can be chosen, and is pre-checked (the regular load).
 * First-year, first-semester subjects have no prerequisites in any curriculum, so
 * a new student can take the whole term. The registrar can still enlist a student
 * in a locked subject later, with an audited override (docs/13).
 */
final class SubjectSelectionService
{
    /**
     * The curriculum an application's subjects come from: the student's own when
     * it is for the same program, otherwise the program's current curriculum.
     *
     * @param array<string,mixed> $application
     * @return array<string,mixed>|null
     */
    public static function curriculumFor(array $application): ?array
    {
        $student = Database::selectOne('SELECT curriculum_id, program_id FROM students WHERE id = ?', [$application['student_id']]);
        if ($student !== null && $student['curriculum_id'] !== null && (int) $student['program_id'] === (int) $application['program_id']) {
            return Curriculum::find((int) $student['curriculum_id']);
        }
        return Curriculum::currentForProgram((int) $application['program_id']);
    }

    /**
     * Every subject offered for this application, with whether it can be chosen.
     *
     * @param array<string,mixed> $application
     * @return array<int,array<string,mixed>> each subject row plus selectable, locked_reason, prerequisite_codes
     */
    public static function options(array $application): array
    {
        $curriculum = self::curriculumFor($application);
        if ($curriculum === null) {
            return [];
        }

        $termSequence = (int) Database::scalar('SELECT sequence FROM terms WHERE id = ?', [$application['term_id']]);
        $subjects = Database::select(
            "SELECT s.*,
                    (SELECT GROUP_CONCAT(pre.code ORDER BY pre.id SEPARATOR ', ')
                       FROM subject_prerequisites sp JOIN subjects pre ON pre.id = sp.prerequisite_subject_id
                      WHERE sp.subject_id = s.id) AS prerequisite_codes,
                    (SELECT GROUP_CONCAT(CONCAT(pre.id, ':', pre.code) ORDER BY pre.id SEPARATOR '|')
                       FROM subject_prerequisites sp JOIN subjects pre ON pre.id = sp.prerequisite_subject_id
                      WHERE sp.subject_id = s.id) AS prerequisite_pairs
               FROM subjects s
              WHERE s.curriculum_id = ? AND s.year_level_id = ? AND s.term_sequence = ? AND s.is_active = 1
              ORDER BY s.id",
            [$curriculum['id'], $application['year_level_id'], $termSequence]
        );

        $studentId = (int) $application['student_id'];
        // The student's record is read once for the whole list, not once per subject.
        $passed = array_flip(AcademicRecordService::passedSubjectIds($studentId));
        $progress = null;
        $options = [];

        foreach ($subjects as $subject) {
            if (isset($passed[(int) $subject['id']])) {
                continue; // already passed: never offered again
            }
            $missing = [];
            foreach (array_filter(explode('|', (string) $subject['prerequisite_pairs'])) as $pair) {
                [$prerequisiteId, $code] = explode(':', $pair, 2);
                if (!isset($passed[(int) $prerequisiteId])) {
                    $missing[] = $code;
                }
            }
            unset($subject['prerequisite_pairs']);
            $reason = $missing !== [] ? 'Requires ' . implode(', ', $missing) . ' first.' : null;

            if ($reason === null && $subject['min_progress_percent'] !== null) {
                $progress ??= AcademicRecordService::progressPercent($studentId, (int) $curriculum['id']);
                if ($progress < (int) $subject['min_progress_percent']) {
                    $reason = "Requires {$subject['min_progress_percent']}% of the curriculum passed (you have {$progress}%).";
                }
            }

            $options[] = $subject + ['selectable' => $reason === null, 'locked_reason' => $reason, 'curriculum_code' => $curriculum['code']];
        }

        return $options;
    }

    /**
     * Pre-select the regular load: every subject that can be chosen. Replaces any
     * earlier choice — used when the application is created and when its program
     * or year level changes.
     */
    public static function selectDefaults(int $applicationId): void
    {
        $application = Database::selectOne('SELECT * FROM enrollment_applications WHERE id = ?', [$applicationId]);
        Database::query('DELETE FROM application_subjects WHERE application_id = ?', [$applicationId]);
        foreach (self::options($application) as $option) {
            if ($option['selectable']) {
                Database::insert('application_subjects', ['application_id' => $applicationId, 'subject_id' => (int) $option['id']]);
            }
        }
    }

    /**
     * The student saves their choice. Allowed only while the form is editable.
     *
     * @param array<int,mixed> $subjectIds
     */
    public static function save(User $student, int $applicationId, array $subjectIds): void
    {
        $application = EnrollmentApplicationService::ownedApplication($student, $applicationId);

        if (!in_array($application['application_status'], App::EDITS_UNLOCKED, true)) {
            throw ValidationException::withMessage(
                'This application is ' . strtolower(App::label($application['application_status'])) . ', so its subjects cannot be changed now.'
            );
        }

        $chosen = array_values(array_unique(array_map('intval', array_filter($subjectIds))));
        $options = [];
        foreach (self::options($application) as $option) {
            $options[(int) $option['id']] = $option;
        }

        $errors = [];
        foreach ($chosen as $subjectId) {
            if (!isset($options[$subjectId])) {
                $errors[] = 'One of the chosen subjects is not offered for your program, year level, and term.';
            } elseif (!$options[$subjectId]['selectable']) {
                $errors[] = "{$options[$subjectId]['code']}: {$options[$subjectId]['locked_reason']}";
            }
        }
        if ($chosen === []) {
            $errors[] = 'Choose at least one subject.';
        }

        $units = array_sum(array_map(static fn ($id) => (float) ($options[$id]['units'] ?? 0), $chosen));
        $limit = self::unitLimit($application);
        if ($units > $limit) {
            $errors[] = sprintf('That is %s units, over the %s-unit limit for the term.', self::number($units), self::number($limit));
        }

        if ($errors !== []) {
            throw ValidationException::withErrors(['subjects' => array_values(array_unique($errors))], $errors[0]);
        }

        Database::transaction(static function () use ($student, $applicationId, $application, $chosen, $units): void {
            Database::selectOne('SELECT id FROM enrollment_applications WHERE id = ? FOR UPDATE', [$applicationId]);
            $before = array_map('intval', array_column(Database::select('SELECT subject_id FROM application_subjects WHERE application_id = ?', [$applicationId]), 'subject_id'));

            Database::query('DELETE FROM application_subjects WHERE application_id = ?', [$applicationId]);
            foreach ($chosen as $subjectId) {
                Database::insert('application_subjects', ['application_id' => $applicationId, 'subject_id' => $subjectId]);
            }

            AuditService::log('enrollment.subjects_chosen', 'enrollment', 'EnrollmentApplication', $applicationId,
                ['subject_ids' => $before], ['subject_ids' => $chosen, 'units' => $units],
                'SUCCESS', null, $student->id, $student->role_name);
        });
    }

    /**
     * The chosen subjects of an application.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function chosen(int $applicationId): array
    {
        return Database::select(
            'SELECT s.* FROM application_subjects aps JOIN subjects s ON s.id = aps.subject_id
              WHERE aps.application_id = ? ORDER BY s.id',
            [$applicationId]
        );
    }

    /** @return array<int,int> */
    public static function chosenIds(int $applicationId): array
    {
        return array_map('intval', array_column(
            Database::select('SELECT subject_id FROM application_subjects WHERE application_id = ?', [$applicationId]),
            'subject_id'
        ));
    }

    /**
     * Why the chosen subjects are not ready to submit or approve, or null.
     *
     * @param array<string,mixed> $application
     */
    public static function problem(array $application): ?string
    {
        if (self::chosenIds((int) $application['id']) === []) {
            return 'Choose the subjects you will take this term first.';
        }
        return null;
    }

    /**
     * The unit limit for an application: the setting, or the curriculum's regular
     * load for that year and term when heavier (the same rule as enlistment).
     *
     * @param array<string,mixed> $application
     */
    public static function unitLimit(array $application): float
    {
        $limit = (float) SystemSetting::get('max_units_per_term', 27);
        $curriculum = self::curriculumFor($application);
        if ($curriculum !== null) {
            $termSequence = (int) Database::scalar('SELECT sequence FROM terms WHERE id = ?', [$application['term_id']]);
            $limit = max($limit, Curriculum::prescribedUnits((int) $curriculum['id'], (int) $application['year_level_id'], $termSequence));
        }
        return $limit;
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
