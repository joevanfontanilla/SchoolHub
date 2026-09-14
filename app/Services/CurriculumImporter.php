<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;

/**
 * Loads a curriculum from a data file in database/seeds/curricula (docs/28).
 *
 * check() validates a file on its own, without touching the database: codes,
 * units, that every prerequisite exists in the same curriculum and is taken in
 * an EARLIER term, and that each term's units match the total printed on the
 * school's document. A mismatch against the printed total is a warning, not an
 * error — the document itself may be wrong, and the registrar decides.
 *
 * import() refuses a file with errors, and never overwrites: a curriculum whose
 * code already exists is skipped, so edits the registrar has made since are kept.
 */
final class CurriculumImporter
{
    /**
     * @param array<string,mixed> $data
     * @return array{errors:array<int,string>,warnings:array<int,string>}
     */
    public static function check(array $data): array
    {
        $errors = [];
        $warnings = [];

        foreach (['program', 'code', 'name', 'effective_school_year', 'terms'] as $key) {
            if (empty($data[$key])) {
                $errors[] = "Missing '{$key}'.";
            }
        }
        if ($errors !== []) {
            return ['errors' => $errors, 'warnings' => $warnings];
        }

        $yearProblem = CatalogService::schoolYearProblem((string) $data['effective_school_year']);
        if ($yearProblem !== null) {
            $errors[] = $yearProblem;
        }

        /** @var array<string,array{order:int,label:string}> $placed code => where it is taken */
        $placed = [];
        $rows = [];
        $seenTerms = [];

        foreach ((array) $data['terms'] as $index => $term) {
            [$year, $termSequence, $printedTotal, $subjects] = array_pad((array) $term, 4, null);
            $label = self::termLabel((int) $year, (int) $termSequence);

            if (!is_int($year) || $year < 1 || $year > 6 || !in_array($termSequence, [1, 2, 3], true) || !is_array($subjects) || $subjects === []) {
                $errors[] = "Term #" . ($index + 1) . ' must be [year 1-6, term 1-3, printed total, non-empty subject list].';
                continue;
            }
            if (isset($seenTerms[$label])) {
                $errors[] = "{$label} appears twice.";
            }
            $seenTerms[$label] = true;

            $order = $year * 10 + $termSequence;
            $sum = 0.0;

            foreach ($subjects as $subject) {
                [$code, $title, $units, $prerequisites, $options] = array_pad((array) $subject, 5, null);
                $normalized = CatalogService::normalizeCode((string) $code);
                $where = "{$label}, {$normalized}";

                $codeProblem = CatalogService::subjectCodeProblem($normalized);
                if ($codeProblem !== null) {
                    $errors[] = "{$where}: {$codeProblem}";
                }
                if ($normalized !== (string) $code) {
                    $errors[] = "{$label}: write the code \"{$code}\" as \"{$normalized}\".";
                }
                if (trim((string) $title) === '' || mb_strlen((string) $title) > 150) {
                    $errors[] = "{$where}: the title is required and at most 150 characters.";
                }
                $unitsProblem = CatalogService::unitsProblem((float) $units);
                if (!is_int($units) && !is_float($units) || $unitsProblem !== null) {
                    $errors[] = "{$where}: " . ($unitsProblem ?? 'units must be a number.');
                }

                $options = (array) $options;
                $labUnits = (float) ($options['lab_units'] ?? 0);
                if ($labUnits < 0 || $labUnits > (float) $units) {
                    $errors[] = "{$where}: lab units must be between 0 and the subject's units.";
                }
                $progress = $options['min_progress_percent'] ?? null;
                if ($progress !== null && (!is_int($progress) || $progress < 1 || $progress > 100)) {
                    $errors[] = "{$where}: min_progress_percent must be a whole number from 1 to 100.";
                }
                $unknown = array_diff(array_keys($options), ['lecture_hours', 'lab_hours', 'lab_units', 'min_progress_percent', 'description']);
                if ($unknown !== []) {
                    $errors[] = "{$where}: unknown option(s) " . implode(', ', $unknown) . '.';
                }

                if (isset($placed[$normalized])) {
                    $errors[] = "{$normalized} is used twice ({$placed[$normalized]['label']} and {$label}). A code must be unique within a curriculum.";
                } else {
                    $placed[$normalized] = ['order' => $order, 'label' => $label];
                }

                $rows[] = [$normalized, $order, $label, (array) $prerequisites];
                $sum += (float) $units;
            }

            if ($printedTotal !== null && abs($sum - (float) $printedTotal) > 0.001) {
                $warnings[] = sprintf('%s: the subjects add up to %s units, but the document prints %s.', $label, self::number($sum), self::number((float) $printedTotal));
            }
        }

        foreach ($rows as [$code, $order, $label, $prerequisites]) {
            foreach ($prerequisites as $prerequisite) {
                $prerequisite = (string) $prerequisite;
                if (!isset($placed[$prerequisite])) {
                    $errors[] = "{$label}, {$code}: prerequisite {$prerequisite} is not in this curriculum.";
                } elseif ($placed[$prerequisite]['order'] >= $order) {
                    $errors[] = "{$label}, {$code}: prerequisite {$prerequisite} is taken in {$placed[$prerequisite]['label']}, which is not an earlier term.";
                }
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @param array<string,mixed> $data
     * @return array{curriculum_id:int,created:bool,subjects:int}
     * @throws RuntimeException when the file has errors or its program does not exist
     */
    public static function import(array $data): array
    {
        $result = self::check($data);
        if ($result['errors'] !== []) {
            throw new RuntimeException("{$data['code']} has errors:\n  - " . implode("\n  - ", $result['errors']));
        }

        $existing = Database::scalar('SELECT id FROM curricula WHERE code = ?', [$data['code']]);
        if ($existing !== null) {
            return ['curriculum_id' => (int) $existing, 'created' => false, 'subjects' => 0];
        }

        $programId = Database::scalar('SELECT id FROM programs WHERE code = ?', [$data['program']]);
        if ($programId === null) {
            throw new RuntimeException("Program {$data['program']} does not exist. Run: php database/seed.php");
        }
        $yearLevels = array_column(Database::select('SELECT id, sequence FROM year_levels'), 'id', 'sequence');

        return Database::transaction(static function () use ($data, $programId, $yearLevels): array {
            $curriculumId = Database::insert('curricula', [
                'program_id'            => (int) $programId,
                'code'                  => (string) $data['code'],
                'name'                  => (string) $data['name'],
                'effective_school_year' => (string) $data['effective_school_year'],
                'notes'                 => $data['notes'] ?? null,
                'is_active'             => 1,
            ]);

            $ids = [];
            $prerequisites = [];

            foreach ($data['terms'] as [$year, $termSequence, , $subjects]) {
                if (!isset($yearLevels[$year])) {
                    throw new RuntimeException("Year level {$year} is not seeded.");
                }
                foreach ($subjects as $subject) {
                    [$code, $title, $units, $requires, $options] = array_pad($subject, 5, null);
                    $options = (array) $options;

                    $ids[$code] = Database::insert('subjects', [
                        'code'                 => $code,
                        'title'                => $title,
                        'description'          => $options['description'] ?? null,
                        'units'                => number_format((float) $units, 1, '.', ''),
                        'lab_units'            => number_format((float) ($options['lab_units'] ?? 0), 1, '.', ''),
                        'lecture_hours'        => (int) ($options['lecture_hours'] ?? 0),
                        'lab_hours'            => (int) ($options['lab_hours'] ?? 0),
                        'program_id'           => (int) $programId,
                        'curriculum_id'        => $curriculumId,
                        'year_level_id'        => (int) $yearLevels[$year],
                        'term_sequence'        => $termSequence,
                        'min_progress_percent' => $options['min_progress_percent'] ?? null,
                        'is_active'            => 1,
                    ]);
                    $prerequisites[$code] = (array) $requires;
                }
            }

            foreach ($prerequisites as $code => $requires) {
                foreach ($requires as $required) {
                    Database::insert('subject_prerequisites', [
                        'subject_id'              => $ids[$code],
                        'prerequisite_subject_id' => $ids[$required],
                    ]);
                }
            }

            AuditService::log('academic.curriculum_imported', 'academic', 'Curriculum', $curriculumId, null, [
                'code'     => $data['code'],
                'program'  => $data['program'],
                'subjects' => count($ids),
            ], 'SUCCESS', null, null, 'System');

            return ['curriculum_id' => $curriculumId, 'created' => true, 'subjects' => count($ids)];
        });
    }

    /** @return array<string,mixed> */
    public static function load(string $path): array
    {
        $data = require $path;
        if (!is_array($data)) {
            throw new RuntimeException(basename($path) . ' must return an array.');
        }
        return $data;
    }

    public static function termLabel(int $year, int $termSequence): string
    {
        $years = [1 => '1st year', 2 => '2nd year', 3 => '3rd year', 4 => '4th year', 5 => '5th year', 6 => '6th year'];
        $terms = [1 => '1st semester', 2 => '2nd semester', 3 => 'summer'];
        return ($years[$year] ?? "Year {$year}") . ', ' . ($terms[$termSequence] ?? "term {$termSequence}");
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
