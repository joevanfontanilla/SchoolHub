<?php
/**
 * Form field with a bound label, required marker, and inline error (docs/21).
 *
 * The error is announced with an icon and text, not color alone, and is linked
 * to the input with aria-describedby.
 *
 * @var string  $name
 * @var string  $label
 * @var string  $type
 * @var ?string $value
 * @var ?string $error
 * @var bool    $required
 * @var ?string $help
 * @var ?string $autocomplete
 */
$type = $type ?? 'text';
$id = 'field-' . $name;
$error = $error ?? error_for($name);
$required = $required ?? false;
$help = $help ?? null;
$describedBy = [];

if ($help !== null) {
    $describedBy[] = $id . '-help';
}
if ($error !== null) {
    $describedBy[] = $id . '-error';
}
?>
<div>
    <label for="<?= e($id) ?>" class="field-label">
        <?= e($label) ?>
        <?php if ($required): ?>
            <span class="text-danger-text" aria-hidden="true">*</span>
            <span class="sr-only">(required)</span>
        <?php endif; ?>
    </label>

    <input type="<?= e($type) ?>"
           id="<?= e($id) ?>"
           name="<?= e($name) ?>"
           value="<?= e($value ?? old($name)) ?>"
           class="field-input <?= $error !== null ? 'field-input-error' : '' ?>"
           <?= $required ? 'required' : '' ?>
           <?= isset($autocomplete) ? 'autocomplete="' . e($autocomplete) . '"' : '' ?>
           <?= $describedBy !== [] ? 'aria-describedby="' . e(implode(' ', $describedBy)) . '"' : '' ?>
           <?= $error !== null ? 'aria-invalid="true"' : '' ?>>

    <?php if ($help !== null): ?>
        <p id="<?= e($id) ?>-help" class="mt-1.5 text-xs text-brand-gray"><?= e($help) ?></p>
    <?php endif; ?>

    <?php if ($error !== null): ?>
        <p id="<?= e($id) ?>-error" class="field-error">
            <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
            </svg>
            <span><?= e($error) ?></span>
        </p>
    <?php endif; ?>
</div>
