<?php
/**
 * Start an enrollment application (docs/09 steps 3-5).
 *
 * @var array  $openTerms    open terms the student has not already applied to
 * @var bool   $anyTermOpen
 * @var array  $programs
 * @var array  $yearLevels
 * @var array  $studentTypes
 * @var array  $missingProfile
 * @var ?array $current
 */
use App\Models\Term;

$selected = static fn (string $field, mixed $value) => (string) old($field) === (string) $value ? 'selected' : '';

$select = static function (string $name, string $label, array $options, string $help = '') use ($selected): string {
    $error = error_for($name);
    $html = '<div><label for="field-' . e($name) . '" class="field-label">' . e($label)
        . ' <span class="text-danger-text" aria-hidden="true">*</span><span class="sr-only">(required)</span></label>'
        . '<select id="field-' . e($name) . '" name="' . e($name) . '" required class="field-input ' . ($error ? 'field-input-error' : '') . '"'
        . ($error ? ' aria-invalid="true" aria-describedby="field-' . e($name) . '-error"' : ($help ? ' aria-describedby="field-' . e($name) . '-help"' : '')) . '>'
        . '<option value="">Select…</option>';
    foreach ($options as $value => $text) {
        $html .= '<option value="' . e($value) . '" ' . $selected($name, $value) . '>' . e($text) . '</option>';
    }
    $html .= '</select>';
    if ($help && !$error) {
        $html .= '<p id="field-' . e($name) . '-help" class="mt-1.5 text-xs text-brand-gray">' . e($help) . '</p>';
    }
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
        <p class="eyebrow">Enrollment</p>
        <h2 class="mt-1 text-2xl">Apply for enrollment</h2>
        <p class="mt-1 text-sm text-slate-600">
            Choose the term and program. You'll upload your documents on the next page.
        </p>
    </div>

    <?php if ($openTerms === []): ?>
        <div class="card">
            <?php if ($anyTermOpen && $current !== null): ?>
                <?= component('empty-state', [
                    'title'   => 'You already have an application for the open term',
                    'message' => 'Continue application ' . $current['application_number'] . ' instead.',
                ]) ?>
                <div class="pb-8 text-center">
                    <a href="<?= e(url('/student/enrollment/' . $current['id'])) ?>" class="btn-primary">Open my application</a>
                </div>
            <?php else: ?>
                <?= component('empty-state', [
                    'title'   => 'Enrollment is closed',
                    'message' => 'No term is accepting applications right now. Check the announcements for the next schedule.',
                ]) ?>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php if ($missingProfile !== []): ?>
            <div class="rounded-xl border border-warning-line bg-warning-bg px-5 py-4 text-sm text-warning-text" role="note">
                <p>
                    <span class="font-semibold">You can start now,</span> but your profile must be complete before you submit.
                    Still needed: <?= e(implode(', ', $missingProfile)) ?>.
                </p>
                <a href="<?= e(url('/student/profile')) ?>" class="mt-2 inline-block font-semibold underline">Complete my profile</a>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?= e(url('/student/enrollment/apply')) ?>" class="card space-y-5 p-5 sm:p-6" novalidate data-student-type-form>
            <?= csrf_field() ?>

            <?= $select('term_id', 'Term', $termOptions) ?>

            <div class="grid gap-5 sm:grid-cols-2">
                <?= $select('student_type', 'Student type', $typeOptions, 'Decides which documents you will be asked for. It cannot be changed later.') ?>
                <?= $select('year_level_id', 'Year level', $yearOptions) ?>
            </div>

            <?= $select('program_id', 'Program', $programOptions) ?>

            <div class="space-y-5 rounded-xl border border-slate-200 bg-slate-50 p-4" data-transferee-fields>
                <p class="text-sm font-medium text-slate-700">For transferees</p>
                <?= component('field', [
                    'name' => 'previous_school', 'label' => 'School you are transferring from',
                    'help' => 'Required for transferees.',
                ]) ?>
                <?= component('field', [
                    'name' => 'last_school_year', 'label' => 'Last school year attended', 'help' => 'Example: 2025-2026',
                ]) ?>
            </div>

            <div class="flex flex-wrap gap-3 pt-2">
                <button type="submit" class="btn-primary btn-lg">Create application</button>
                <a href="<?= e(url('/student/enrollment')) ?>" class="btn-secondary btn-lg">Back</a>
            </div>
        </form>
    <?php endif; ?>
</div>
