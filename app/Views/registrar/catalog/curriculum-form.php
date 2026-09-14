<?php
/**
 * @var ?array $curriculum
 * @var array  $programs
 */
$editing = $curriculum !== null;
$action = $editing ? '/registrar/curricula/' . $curriculum['id'] . '/edit' : '/registrar/curricula/create';
$back = $editing ? '/registrar/curricula/' . $curriculum['id'] : '/registrar/curricula';
$val = static fn (string $field, string $default = '') => old($field, $curriculum[$field] ?? $default);
$active = errors() !== [] ? (bool) old('is_active', false) : (int) ($curriculum['is_active'] ?? 1) === 1;
$programError = error_for('program_id');
?>
<div class="mx-auto max-w-2xl space-y-6">
    <a href="<?= e(url($back)) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; <?= $editing ? e($curriculum['code']) : 'Curricula' ?></a>
    <h2 class="text-2xl"><?= $editing ? 'Edit ' . e($curriculum['code']) : 'New curriculum' ?></h2>

    <form method="POST" action="<?= e(url($action)) ?>" class="card space-y-5 p-5 sm:p-6" novalidate>
        <?= csrf_field() ?>
        <div>
            <label for="field-program_id" class="field-label">Program <span class="text-brand-alert" aria-hidden="true">*</span></label>
            <select id="field-program_id" name="program_id" class="field-input <?= $programError ? 'field-input-error' : '' ?>" required
                    <?= $programError ? 'aria-invalid="true" aria-describedby="error-program_id"' : '' ?>>
                <option value="">Choose a program</option>
                <?php foreach ($programs as $program): ?>
                    <option value="<?= e($program['id']) ?>" <?= (string) $val('program_id') === (string) $program['id'] ? 'selected' : '' ?>><?= e($program['code'] . ' · ' . $program['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($programError): ?><p id="error-program_id" class="field-error"><span><?= e($programError) ?></span></p><?php endif; ?>
        </div>
        <div class="grid gap-5 sm:grid-cols-2">
            <?= component('field', ['name' => 'code', 'label' => 'Code', 'required' => true, 'value' => $val('code'), 'help' => 'Example: BSA-2024']) ?>
            <?= component('field', ['name' => 'effective_school_year', 'label' => 'Effective school year', 'required' => true, 'value' => $val('effective_school_year'), 'help' => 'Example: 2024-2025']) ?>
        </div>
        <?= component('field', ['name' => 'name', 'label' => 'Name', 'required' => true, 'value' => $val('name'), 'help' => 'As printed on the curriculum.']) ?>
        <?= component('field', ['name' => 'notes', 'label' => 'Notes', 'value' => $val('notes'), 'help' => 'Optional. For example, the CHED memorandum or the senior high strand it is for.']) ?>
        <label class="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" name="is_active" value="1" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-600" <?= $active ? 'checked' : '' ?>>
            Active (can be given to newly enrolled students)
        </label>
        <div class="flex gap-3">
            <button type="submit" class="btn-primary"><?= $editing ? 'Save curriculum' : 'Create curriculum' ?></button>
            <a href="<?= e(url($back)) ?>" class="btn-secondary">Cancel</a>
        </div>
    </form>
</div>
