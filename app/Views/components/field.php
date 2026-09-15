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
 * @var ?string $icon       icon name shown inside the field, on the left
 * @var bool    $autofocus
 *
 * A password field gets a button to show and hide what was typed, and a notice when
 * Caps Lock is on (both added by app.js; without JavaScript the field is a normal one).
 */
$type = $type ?? 'text';
$icon = $icon ?? null;
$autofocus = $autofocus ?? false;
$isPassword = $type === 'password';
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

    <div class="relative">
        <?php if ($icon !== null): ?>
            <span class="pointer-events-none absolute inset-y-0 left-3.5 flex items-center text-brand-gray"><?= icon($icon, 'h-5 w-5') ?></span>
        <?php endif; ?>
        <input type="<?= e($type) ?>"
               id="<?= e($id) ?>"
               name="<?= e($name) ?>"
               value="<?= e($value ?? old($name)) ?>"
               class="field-input <?= $icon !== null ? 'pl-11' : '' ?> <?= $isPassword ? 'pr-12' : '' ?> <?= $error !== null ? 'field-input-error' : '' ?>"
               <?= $required ? 'required' : '' ?>
               <?= $autofocus ? 'autofocus' : '' ?>
               <?= $isPassword ? 'data-password' : '' ?>
               <?= isset($autocomplete) ? 'autocomplete="' . e($autocomplete) . '"' : '' ?>
               <?= $describedBy !== [] ? 'aria-describedby="' . e(implode(' ', $describedBy)) . '"' : '' ?>
               <?= $error !== null ? 'aria-invalid="true"' : '' ?>>
        <?php if ($isPassword): ?>
            <button type="button" hidden data-password-toggle aria-controls="<?= e($id) ?>" aria-pressed="false"
                    class="absolute inset-y-0 right-1.5 my-auto flex h-9 w-9 items-center justify-center rounded-lg text-brand-gray hover:bg-brand-50 hover:text-brand-navy focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">
                <span data-password-show><?= icon('eye', 'h-5 w-5') ?></span>
                <span data-password-hide hidden><?= icon('eye-off', 'h-5 w-5') ?></span>
                <span class="sr-only" data-password-label>Show password</span>
            </button>
        <?php endif; ?>
    </div>

    <?php if ($isPassword): ?>
        <p id="<?= e($id) ?>-caps" class="mt-1.5 flex items-center gap-1.5 text-xs font-medium text-warning-text" role="status" hidden data-caps-warning>
            <?= icon('alert', 'h-4 w-4') ?> Caps Lock is on.
        </p>
    <?php endif; ?>

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
