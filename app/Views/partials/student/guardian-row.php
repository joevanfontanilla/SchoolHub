<?php
/**
 * One guardian row in the profile form. Also rendered inside a <template> with
 * index "__INDEX__", which the repeater script replaces when a row is added.
 *
 * @var int|string $index
 * @var array      $guardian
 */
$field = static fn (string $name) => "guardians[{$index}][{$name}]";
$id = static fn (string $name) => "guardian-{$index}-{$name}";
$err = static fn (string $name) => is_int($index) ? error_for("guardians.{$index}.{$name}") : null;
$val = static fn (string $name) => (string) ($guardian[$name] ?? '');
?>
<div class="rounded-xl border border-slate-200 p-4" data-repeater-item>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ([
            ['full_name', 'Full name', 'text', true],
            ['relationship', 'Relationship', 'text', true],
            ['mobile', 'Mobile number', 'tel', true],
            ['email', 'Email', 'email', false],
        ] as [$name, $label, $type, $required]): ?>
            <?php $error = $err($name); ?>
            <div>
                <label for="<?= e($id($name)) ?>" class="field-label">
                    <?= e($label) ?>
                    <?php if ($required): ?><span class="text-danger-text" aria-hidden="true">*</span><span class="sr-only">(required)</span><?php endif; ?>
                </label>
                <input type="<?= e($type) ?>" id="<?= e($id($name)) ?>" name="<?= e($field($name)) ?>" value="<?= e($val($name)) ?>"
                       class="field-input <?= $error ? 'field-input-error' : '' ?>"
                       <?= $name === 'relationship' ? 'placeholder="Mother, Father, Guardian…"' : '' ?>
                       <?= $error ? 'aria-invalid="true" aria-describedby="' . e($id($name)) . '-error"' : '' ?>>
                <?php if ($error): ?>
                    <p id="<?= e($id($name)) ?>-error" class="field-error"><span><?= e($error) ?></span></p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="sm:col-span-2">
            <label for="<?= e($id('address')) ?>" class="field-label">Address</label>
            <input type="text" id="<?= e($id('address')) ?>" name="<?= e($field('address')) ?>" value="<?= e($val('address')) ?>" class="field-input">
        </div>
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
        <label class="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" name="<?= e($field('is_emergency_contact')) ?>" value="1"
                   class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-600"
                   <?= !empty($guardian['is_emergency_contact']) ? 'checked' : '' ?>>
            Emergency contact
        </label>
        <button type="button" class="text-sm font-semibold text-danger-text hover:underline" data-repeater-remove>
            Remove
        </button>
    </div>
</div>
