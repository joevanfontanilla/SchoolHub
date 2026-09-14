<?php
/**
 * @var ?array $fee
 * @var array  $bases
 */
$editing = $fee !== null;
$action = $editing ? '/cashier/fees/' . $fee['id'] . '/edit' : '/cashier/fees/create';
$val = static fn (string $field, string $default = '') => old($field, (string) ($fee[$field] ?? $default));
$active = errors() !== [] ? (bool) old('is_active', false) : (int) ($fee['is_active'] ?? 1) === 1;
$basisError = error_for('basis');
?>
<div class="mx-auto max-w-2xl space-y-6">
    <a href="<?= e(url('/cashier/fees')) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; Fee schedule</a>
    <h2 class="text-2xl"><?= $editing ? 'Edit ' . e($fee['name']) : 'New fee' ?></h2>

    <form method="POST" action="<?= e(url($action)) ?>" class="card space-y-5 p-5 sm:p-6" novalidate>
        <?= csrf_field() ?>
        <div class="grid gap-5 sm:grid-cols-3">
            <?= component('field', ['name' => 'code', 'label' => 'Code', 'required' => true, 'value' => $val('code'), 'help' => 'Example: TUITION']) ?>
            <div class="sm:col-span-2"><?= component('field', ['name' => 'name', 'label' => 'Name', 'required' => true, 'value' => $val('name'), 'help' => 'As shown on the statement of account.']) ?></div>
        </div>
        <div class="grid gap-5 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <label for="field-basis" class="field-label">Charged <span class="text-brand-alert" aria-hidden="true">*</span></label>
                <select id="field-basis" name="basis" class="field-input <?= $basisError ? 'field-input-error' : '' ?>">
                    <?php foreach ($bases as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $val('basis', 'FIXED') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($basisError): ?><p class="field-error"><span><?= e($basisError) ?></span></p><?php endif; ?>
            </div>
            <?= component('field', ['name' => 'amount', 'label' => 'Amount (₱)', 'type' => 'number', 'required' => true, 'value' => $val('amount')]) ?>
        </div>
        <div class="grid gap-5 sm:grid-cols-3">
            <div class="sm:col-span-2"><?= component('field', ['name' => 'description', 'label' => 'Description', 'value' => $val('description')]) ?></div>
            <?= component('field', ['name' => 'sort_order', 'label' => 'Order on statement', 'type' => 'number', 'value' => $val('sort_order', '100')]) ?>
        </div>
        <label class="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" name="is_active" value="1" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-600" <?= $active ? 'checked' : '' ?>>
            Active (included in new assessments)
        </label>
        <div class="flex gap-3">
            <button type="submit" class="btn-primary"><?= $editing ? 'Save fee' : 'Add fee' ?></button>
            <a href="<?= e(url('/cashier/fees')) ?>" class="btn-secondary">Cancel</a>
        </div>
    </form>
</div>
