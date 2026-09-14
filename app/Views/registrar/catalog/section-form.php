<?php
/**
 * @var ?array $section
 * @var int    $enrolled
 * @var array  $programs
 * @var array  $yearLevels
 * @var array  $terms
 * @var array  $teachers
 */
use App\Models\Term;

$editing = $section !== null;
$action = $editing ? '/registrar/sections/' . $section['id'] . '/edit' : '/registrar/sections/create';
$failed = errors() !== [];
$val = static fn (string $field, string $default = '') => (string) old($field, $section[$field] ?? $default);
$active = $failed ? (bool) old('is_active', false) : (int) ($section['is_active'] ?? 1) === 1;
$locked = $enrolled > 0;

$select = static function (string $name, string $label, array $options, string $current, ?string $emptyLabel, bool $required = true): string {
    $error = error_for($name);
    $html = '<div><label for="field-' . e($name) . '" class="field-label">' . e($label)
        . ($required ? ' <span class="text-danger-text" aria-hidden="true">*</span>' : '') . '</label>'
        . '<select id="field-' . e($name) . '" name="' . e($name) . '" class="field-input ' . ($error ? 'field-input-error' : '') . '"'
        . ($error ? ' aria-invalid="true"' : '') . '>';
    if ($emptyLabel !== null) {
        $html .= '<option value="">' . e($emptyLabel) . '</option>';
    }
    foreach ($options as $value => $text) {
        $html .= '<option value="' . e($value) . '"' . ($current === (string) $value ? ' selected' : '') . '>' . e($text) . '</option>';
    }
    return $html . '</select>' . ($error ? '<p class="field-error"><span>' . e($error) . '</span></p>' : '') . '</div>';
};

$termOptions = [];
foreach ($terms as $term) {
    $termOptions[$term['id']] = Term::label($term);
}
$teacherOptions = [];
foreach ($teachers as $teacher) {
    $teacherOptions[$teacher['id']] = $teacher['last_name'] . ', ' . $teacher['first_name'];
}
?>
<div class="mx-auto max-w-2xl space-y-6">
    <a href="<?= e(url('/registrar/sections')) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; Sections</a>
    <h2 class="text-2xl"><?= $editing ? 'Edit ' . e($section['code']) : 'New section' ?></h2>

    <?php if ($locked): ?>
        <p class="rounded-xl border border-brand-200 bg-brand-50 px-5 py-4 text-sm text-brand-navy">
            <?= e($enrolled) ?> student<?= $enrolled === 1 ? ' is' : 's are' ?> enrolled here, so the program, year level, and term can no longer change,
            and capacity cannot go below <?= e($enrolled) ?>.
        </p>
    <?php endif; ?>

    <form method="POST" action="<?= e(url($action)) ?>" class="card space-y-5 p-5 sm:p-6" novalidate>
        <?= csrf_field() ?>
        <div class="grid gap-5 sm:grid-cols-2">
            <?= component('field', ['name' => 'code', 'label' => 'Section code', 'required' => true, 'value' => $val('code'), 'help' => 'Example: BSIT-2A. Unique within a term.']) ?>
            <?= component('field', ['name' => 'capacity', 'label' => 'Capacity', 'type' => 'number', 'required' => true, 'value' => $val('capacity', '40')]) ?>
        </div>
        <?= $select('term_id', 'Term', $termOptions, $val('term_id'), 'Select a term…') ?>
        <div class="grid gap-5 sm:grid-cols-2">
            <?= $select('program_id', 'Program', array_column($programs, 'code', 'id'), $val('program_id'), 'Select a program…') ?>
            <?= $select('year_level_id', 'Year level', array_column($yearLevels, 'name', 'id'), $val('year_level_id'), 'Select a year level…') ?>
        </div>
        <?= $select('adviser_user_id', 'Adviser', $teacherOptions, $val('adviser_user_id'), 'No adviser yet', false) ?>
        <label class="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" name="is_active" value="1" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-600" <?= $active ? 'checked' : '' ?>>
            Active (available for enrollment confirmation)
        </label>
        <div class="flex gap-3">
            <button type="submit" class="btn-primary"><?= $editing ? 'Save section' : 'Create section' ?></button>
            <a href="<?= e(url('/registrar/sections')) ?>" class="btn-secondary">Cancel</a>
        </div>
    </form>
</div>
