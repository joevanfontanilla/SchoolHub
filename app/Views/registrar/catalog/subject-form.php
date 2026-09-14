<?php
/**
 * @var ?array $subject
 * @var array  $programs
 * @var array  $yearLevels
 * @var array  $allSubjects      every other subject, for prerequisites
 * @var array  $prerequisiteIds
 */
$editing = $subject !== null;
$action = $editing ? '/registrar/subjects/' . $subject['id'] . '/edit' : '/registrar/subjects/create';
$failed = errors() !== [];
$val = static fn (string $field, string $default = '') => old($field, $subject[$field] ?? $default);
$active = $failed ? (bool) old('is_active', false) : (int) ($subject['is_active'] ?? 1) === 1;
$chosen = array_map('intval', $failed ? (array) old('prerequisites', []) : $prerequisiteIds);
$units = $subject !== null ? rtrim(rtrim((string) $subject['units'], '0'), '.') : '3';

$select = static function (string $name, string $label, array $options, string $current, string $emptyLabel) : string {
    $error = error_for($name);
    $html = '<div><label for="field-' . e($name) . '" class="field-label">' . e($label) . '</label>'
        . '<select id="field-' . e($name) . '" name="' . e($name) . '" class="field-input ' . ($error ? 'field-input-error' : '') . '">'
        . '<option value="">' . e($emptyLabel) . '</option>';
    foreach ($options as $value => $text) {
        $html .= '<option value="' . e($value) . '"' . ((string) $current === (string) $value ? ' selected' : '') . '>' . e($text) . '</option>';
    }
    return $html . '</select>' . ($error ? '<p class="field-error"><span>' . e($error) . '</span></p>' : '') . '</div>';
};
?>
<div class="mx-auto max-w-3xl space-y-6">
    <a href="<?= e(url('/registrar/subjects')) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; Subjects</a>
    <h2 class="text-2xl"><?= $editing ? 'Edit ' . e($subject['code']) : 'New subject' ?></h2>

    <form method="POST" action="<?= e(url($action)) ?>" class="card space-y-5 p-5 sm:p-6" novalidate>
        <?= csrf_field() ?>
        <div class="grid gap-5 sm:grid-cols-3">
            <?= component('field', ['name' => 'code', 'label' => 'Code', 'required' => true, 'value' => $val('code'), 'help' => 'Example: IT-201']) ?>
            <div class="sm:col-span-2">
                <?= component('field', ['name' => 'title', 'label' => 'Title', 'required' => true, 'value' => $val('title')]) ?>
            </div>
        </div>
        <div class="grid gap-5 sm:grid-cols-3">
            <?= component('field', ['name' => 'units', 'label' => 'Units', 'type' => 'number', 'required' => true, 'value' => old('units', $units)]) ?>
            <?= component('field', ['name' => 'lecture_hours', 'label' => 'Lecture hours / week', 'type' => 'number', 'value' => $val('lecture_hours', '3')]) ?>
            <?= component('field', ['name' => 'lab_hours', 'label' => 'Lab hours / week', 'type' => 'number', 'value' => $val('lab_hours', '0')]) ?>
        </div>
        <div class="grid gap-5 sm:grid-cols-3">
            <?= $select('program_id', 'Program', array_column(array_map(static fn ($p) => ['id' => $p['id'], 'label' => $p['code'] . ' · ' . $p['name']], $programs), 'label', 'id'), (string) $val('program_id'), 'General (all programs)') ?>
            <?= $select('year_level_id', 'Year level', array_column($yearLevels, 'name', 'id'), (string) $val('year_level_id'), 'Any year') ?>
            <?= $select('term_sequence', 'Term offered', ['1' => '1st semester', '2' => '2nd semester', '3' => 'Summer'], (string) $val('term_sequence'), 'Any term') ?>
        </div>
        <div>
            <label for="field-description" class="field-label">Description</label>
            <textarea id="field-description" name="description" rows="3" class="field-input"><?= e($val('description')) ?></textarea>
        </div>

        <fieldset>
            <legend class="field-label">Prerequisites</legend>
            <p class="-mt-1 mb-2 text-xs text-brand-gray">Subjects a student must pass first. A subject cannot require itself, directly or through another subject.</p>
            <?php if ($error = error_for('prerequisites')): ?>
                <p class="field-error mb-2" role="alert"><span><?= e($error) ?></span></p>
            <?php endif; ?>
            <?php if ($allSubjects === []): ?>
                <p class="text-sm text-brand-gray">No other subjects exist yet.</p>
            <?php else: ?>
                <div class="grid max-h-64 gap-1 overflow-y-auto rounded-lg border border-slate-200 p-3 sm:grid-cols-2">
                    <?php foreach ($allSubjects as $other): ?>
                        <label class="flex items-start gap-2 rounded px-2 py-1.5 text-sm hover:bg-slate-50">
                            <input type="checkbox" name="prerequisites[]" value="<?= e($other['id']) ?>"
                                   class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-600"
                                   <?= in_array((int) $other['id'], $chosen, true) ? 'checked' : '' ?>>
                            <span><span class="font-semibold text-brand-navy"><?= e($other['code']) ?></span> <?= e($other['title']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </fieldset>

        <label class="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" name="is_active" value="1" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-600" <?= $active ? 'checked' : '' ?>>
            Active
        </label>
        <div class="flex gap-3">
            <button type="submit" class="btn-primary"><?= $editing ? 'Save subject' : 'Create subject' ?></button>
            <a href="<?= e(url('/registrar/subjects')) ?>" class="btn-secondary">Cancel</a>
        </div>
    </form>
</div>
