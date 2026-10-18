<?php
/**
 * Walk-in for a student who is already registered (docs/09): only the enrollment choices are entered; the
 * application is created on the existing account, with no new account and no set-up link.
 *
 * @var array $student        WalkInService::studentSummary()
 * @var array $missingProfile profile fields still empty
 * @var array $openTerms
 * @var array $programs
 * @var array $yearLevels
 * @var array $studentTypes
 */
use App\Models\Term;

$select = static function (string $name, string $label, array $options): string {
    $error = error_for($name);
    $current = (string) old($name, '');
    $html = '<div><label for="field-' . e($name) . '" class="field-label">' . e($label)
        . ' <span class="text-danger-text" aria-hidden="true">*</span><span class="sr-only">(required)</span></label>'
        . '<select id="field-' . e($name) . '" name="' . e($name) . '" required class="field-input ' . ($error ? 'field-input-error' : '') . '"'
        . ($error ? ' aria-invalid="true" aria-describedby="field-' . e($name) . '-error"' : '') . '>'
        . '<option value="">Select…</option>';
    foreach ($options as $value => $text) {
        $html .= '<option value="' . e($value) . '"' . ($current === (string) $value ? ' selected' : '') . '>' . e($text) . '</option>';
    }
    $html .= '</select>';
    if ($error) {
        $html .= '<p id="field-' . e($name) . '-error" class="field-error"><span>' . e($error) . '</span></p>';
    }

    return $html . '</div>';
};

$termOptions = [];
foreach ($openTerms as $term) {
    $termOptions[$term['id']] = Term::label($term) . ' (closes ' . dt($term['enrollment_closes_at'], 'M j') . ')';
}
$programOptions = [];
foreach ($programs as $program) {
    $programOptions[$program['id']] = $program['code'] . ' · ' . $program['name'];
}
$yearOptions = array_column($yearLevels, 'name', 'id');
$typeOptions = \App\Models\Student::TYPE_LABELS;
?>
<div class="mx-auto max-w-3xl space-y-6">
    <div>
        <a href="<?= e(url('/registrar/walk-in')) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; Walk-in registration</a>
        <p class="eyebrow mt-2">Registrar</p>
        <h2 class="mt-1 text-2xl"><?= e($student['last_name'] . ', ' . $student['first_name']) ?></h2>
        <p class="mt-1 text-sm text-slate-600"><?= e($student['student_number'] ?? 'No student number yet') ?><?= $student['program_code'] ? ' · ' . e($student['program_code']) : '' ?> · <?= e($student['email']) ?></p>
    </div>

    <?php if ($missingProfile !== []): ?>
        <p class="rounded-xl border border-warning-line bg-warning-bg px-4 py-3 text-sm text-warning-text" role="status">
            This student's profile is incomplete (<?= e(implode(', ', $missingProfile)) ?>). The application can still be started, but it cannot be submitted until the profile is complete.
            <a href="<?= e(url('/registrar/students/' . $student['id'])) ?>" class="font-semibold underline">Open the student's record</a>.
        </p>
    <?php endif; ?>

    <?php if ($openTerms === []): ?>
        <div class="card">
            <?= component('empty-state', ['title' => 'Enrollment is closed', 'message' => 'No term is accepting applications right now. Set the enrollment window on the Academic Calendar first.']) ?>
        </div>
    <?php else: ?>
        <form method="POST" action="<?= e(url('/registrar/walk-in/student/' . $student['id'])) ?>" class="space-y-6" novalidate data-student-type-form>
            <?= csrf_field() ?>
            <fieldset class="card space-y-5 p-5 sm:p-6">
                <legend class="float-left mb-1 w-full font-display text-base font-bold text-brand-navy">Enrollment</legend>
                <div class="clear-both"><?= $select('term_id', 'Term', $termOptions) ?></div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <?= $select('student_type', 'Student type', $typeOptions) ?>
                    <?= $select('year_level_id', 'Year level', $yearOptions) ?>
                </div>
                <?= $select('program_id', 'Program', $programOptions) ?>
                <div class="space-y-5 rounded-xl border border-slate-200 bg-slate-50 p-4" data-transferee-fields>
                    <p class="text-sm font-medium text-slate-700">For transferees</p>
                    <?= component('field', ['name' => 'previous_school', 'label' => 'School transferring from', 'value' => old('previous_school', ''), 'help' => 'Required for transferees.']) ?>
                    <?= component('field', ['name' => 'last_school_year', 'label' => 'Last school year attended', 'value' => old('last_school_year', ''), 'help' => 'Example: 2025-2026']) ?>
                </div>
            </fieldset>
            <div class="flex flex-wrap gap-3">
                <button type="submit" class="btn-primary btn-lg" data-busy-label="Starting…">Start the application</button>
                <a href="<?= e(url('/registrar/walk-in')) ?>" class="btn-secondary btn-lg">Cancel</a>
            </div>
        </form>
    <?php endif; ?>
</div>
