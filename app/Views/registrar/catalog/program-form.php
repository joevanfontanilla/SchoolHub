<?php
/** @var ?array $program */
$editing = $program !== null;
$action = $editing ? '/registrar/programs/' . $program['id'] . '/edit' : '/registrar/programs/create';
$val = static fn (string $field, string $default = '') => old($field, $program[$field] ?? $default);
$active = errors() !== [] ? (bool) old('is_active', false) : (int) ($program['is_active'] ?? 1) === 1;
?>
<div class="mx-auto max-w-2xl space-y-6">
    <a href="<?= e(url('/registrar/programs')) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; Programs</a>
    <h2 class="text-2xl"><?= $editing ? 'Edit ' . e($program['code']) : 'New program' ?></h2>

    <form method="POST" action="<?= e(url($action)) ?>" class="card space-y-5 p-5 sm:p-6" novalidate>
        <?= csrf_field() ?>
        <div class="grid gap-5 sm:grid-cols-3">
            <?= component('field', ['name' => 'code', 'label' => 'Code', 'required' => true, 'value' => $val('code'), 'help' => 'Example: BSA']) ?>
            <div class="sm:col-span-2">
                <?= component('field', ['name' => 'name', 'label' => 'Name', 'required' => true, 'value' => $val('name')]) ?>
            </div>
        </div>
        <div class="grid gap-5 sm:grid-cols-2">
            <?= component('field', ['name' => 'degree_level', 'label' => 'Degree level', 'value' => $val('degree_level', 'Bachelor')]) ?>
            <?= component('field', ['name' => 'years_duration', 'label' => 'Duration (years)', 'type' => 'number', 'required' => true, 'value' => $val('years_duration', '4')]) ?>
        </div>
        <div>
            <label for="field-description" class="field-label">Description</label>
            <textarea id="field-description" name="description" rows="3" class="field-input"><?= e($val('description')) ?></textarea>
        </div>
        <label class="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" name="is_active" value="1" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-600" <?= $active ? 'checked' : '' ?>>
            Active (offered to applicants)
        </label>
        <div class="flex gap-3">
            <button type="submit" class="btn-primary"><?= $editing ? 'Save program' : 'Create program' ?></button>
            <a href="<?= e(url('/registrar/programs')) ?>" class="btn-secondary">Cancel</a>
        </div>
    </form>
</div>
