<?php
/**
 * @var ?array $fee
 * @var array  $bases
 * @var array  $programs
 * @var array  $yearLevels
 * @var array  $keys  fee key => name of the general line a scoped fee can replace
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

    <form method="POST" action="<?= e(url($action)) ?>" class="card space-y-5 p-5 sm:p-6" novalidate
          data-confirm="Assessments issued from now on use these amounts. Assessments already issued keep theirs." data-confirm-title="<?= $editing ? 'Save this fee?' : 'Add this fee?' ?>" data-confirm-tone="primary">
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
        <fieldset class="space-y-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
            <legend class="px-1 text-sm font-semibold text-brand-navy">Applies to</legend>
            <p class="text-sm text-slate-600">Leave both blank for every student. Choose a program and/or a year level to limit this fee, for example a different tuition rate for one program.</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="field-program_id" class="field-label">Program</label>
                    <select id="field-program_id" name="program_id" class="field-input">
                        <option value="">All programs</option>
                        <?php foreach ($programs as $program): ?>
                            <option value="<?= e($program['id']) ?>" <?= $val('program_id') === (string) $program['id'] ? 'selected' : '' ?>><?= e($program['code']) ?> · <?= e($program['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (($e = error_for('program_id')) !== null): ?><p class="field-error"><span><?= e($e) ?></span></p><?php endif; ?>
                </div>
                <div>
                    <label for="field-year_level_id" class="field-label">Year level</label>
                    <select id="field-year_level_id" name="year_level_id" class="field-input">
                        <option value="">All year levels</option>
                        <?php foreach ($yearLevels as $yearLevel): ?>
                            <option value="<?= e($yearLevel['id']) ?>" <?= $val('year_level_id') === (string) $yearLevel['id'] ? 'selected' : '' ?>><?= e($yearLevel['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (($e = error_for('year_level_id')) !== null): ?><p class="field-error"><span><?= e($e) ?></span></p><?php endif; ?>
                </div>
            </div>
            <div>
                <label for="field-replaces" class="field-label">Replaces the general fee</label>
                <select id="field-replaces" name="replaces" class="field-input">
                    <option value="">None, an extra charge for this program or year</option>
                    <?php foreach ($keys as $key => $name): ?>
                        <option value="<?= e($key) ?>" <?= old('replaces', $fee !== null && ($fee['program_id'] !== null || $fee['year_level_id'] !== null) ? (string) $fee['fee_key'] : '') === $key ? 'selected' : '' ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="mt-1.5 text-xs text-brand-gray">For students it applies to, this line is charged instead of the general one.</p>
                <?php if (($e = error_for('replaces')) !== null): ?><p class="field-error"><span><?= e($e) ?></span></p><?php endif; ?>
            </div>
        </fieldset>
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
