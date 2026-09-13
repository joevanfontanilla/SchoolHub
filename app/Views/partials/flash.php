<?php
/**
 * Flash messages.
 *
 * Each message carries an icon and a word as well as a color, so status is
 * never communicated by color alone (docs/21).
 */
$flash = App\Core\Session::pullFlash();
if ($flash === []) {
    return;
}

$styles = [
    'success' => ['bg-success-bg border-success-line text-success-text',  'Success', 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z'],
    'error'   => ['bg-danger-bg border-danger-line text-danger-text',        'Error',   'M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z'],
    'warning' => ['bg-warning-bg border-warning-line text-warning-text',  'Notice',  'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126Z'],
    'info'    => ['bg-brand-50 border-brand-200 text-brand-navy',     'Info',    'M11.25 11.25l.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.852l.041-.02M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z'],
];
?>
<div class="mx-auto max-w-7xl px-4 pt-4 sm:px-6 lg:px-8" role="status" aria-live="polite">
    <?php foreach ($flash as $type => $messages): ?>
        <?php [$classes, $label, $icon] = $styles[$type] ?? $styles['info']; ?>
        <?php foreach ($messages as $message): ?>
            <div class="mb-3 flex items-start gap-3 rounded-lg border px-4 py-3 <?= $classes ?>">
                <svg class="mt-0.5 h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="<?= $icon ?>" />
                </svg>
                <p class="text-sm"><span class="font-semibold"><?= e($label) ?>:</span> <?= e($message) ?></p>
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>
</div>
