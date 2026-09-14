<?php
/**
 * @var ?array $room
 * @var array  $types
 */
$editing = $room !== null;
$action = $editing ? '/registrar/rooms/' . $room['id'] . '/edit' : '/registrar/rooms/create';
$val = static fn (string $field, string $default = '') => old($field, $room[$field] ?? $default);
$active = errors() !== [] ? (bool) old('is_active', false) : (int) ($room['is_active'] ?? 1) === 1;
$typeError = error_for('type');
?>
<div class="mx-auto max-w-2xl space-y-6">
    <a href="<?= e(url('/registrar/rooms')) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; Rooms</a>
    <h2 class="text-2xl"><?= $editing ? 'Edit ' . e($room['code']) : 'New room' ?></h2>

    <form method="POST" action="<?= e(url($action)) ?>" class="card space-y-5 p-5 sm:p-6" novalidate>
        <?= csrf_field() ?>
        <div class="grid gap-5 sm:grid-cols-3">
            <?= component('field', ['name' => 'code', 'label' => 'Code', 'required' => true, 'value' => $val('code'), 'help' => 'Example: RM-201']) ?>
            <div class="sm:col-span-2">
                <?= component('field', ['name' => 'name', 'label' => 'Name', 'required' => true, 'value' => $val('name')]) ?>
            </div>
        </div>
        <div class="grid gap-5 sm:grid-cols-3">
            <?= component('field', ['name' => 'building', 'label' => 'Building', 'value' => $val('building')]) ?>
            <div>
                <label for="field-type" class="field-label">Type <span class="text-brand-alert" aria-hidden="true">*</span></label>
                <select id="field-type" name="type" class="field-input <?= $typeError ? 'field-input-error' : '' ?>" required>
                    <?php foreach ($types as $type): ?>
                        <option value="<?= e($type) ?>" <?= $val('type', 'Lecture') === $type ? 'selected' : '' ?>><?= e($type) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($typeError): ?><p class="field-error"><span><?= e($typeError) ?></span></p><?php endif; ?>
            </div>
            <?= component('field', ['name' => 'capacity', 'label' => 'Capacity', 'type' => 'number', 'required' => true, 'value' => $val('capacity', '40')]) ?>
        </div>
        <label class="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" name="is_active" value="1" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-600" <?= $active ? 'checked' : '' ?>>
            Active (can be scheduled)
        </label>
        <div class="flex gap-3">
            <button type="submit" class="btn-primary"><?= $editing ? 'Save room' : 'Create room' ?></button>
            <a href="<?= e(url('/registrar/rooms')) ?>" class="btn-secondary">Cancel</a>
        </div>
    </form>
</div>
