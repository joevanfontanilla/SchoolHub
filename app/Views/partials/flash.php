<?php
/**
 * Flash messages, in the same design as the confirmation window: a colored icon tile, a
 * word saying what kind of message it is, and the message. Status is never color alone
 * (docs/21). Each can be dismissed.
 *
 * @var bool $container wrap in the page width and gutters (the public site)
 */
$container ??= false;
$flash = App\Core\Session::pullFlash();
if ($flash === []) {
    return;
}

$styles = [
    'success' => ['border-success-line', 'bg-success-bg text-success-text ring-success-line', 'check', 'Done'],
    'error'   => ['border-danger-line', 'bg-danger-bg text-danger-text ring-danger-line', 'alert', 'Something needs attention'],
    'warning' => ['border-warning-line', 'bg-warning-bg text-warning-text ring-warning-line', 'alert', 'Notice'],
    'info'    => ['border-brand-200', 'bg-brand-50 text-brand-600 ring-brand-200', 'info', 'Information'],
];
?>
<div class="<?= $container ? 'mx-auto max-w-7xl px-4 pt-4 sm:px-6 lg:px-8' : 'mb-5' ?> space-y-3" role="status" aria-live="polite">
    <?php foreach ($flash as $type => $messages): ?>
        <?php [$border, $tile, $iconName, $label] = $styles[$type] ?? $styles['info']; ?>
        <?php foreach ($messages as $message): ?>
            <div class="flex items-start gap-3 rounded-2xl border bg-white p-3 pr-2 shadow-sm <?= $border ?>" data-alert>
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 <?= $tile ?>"><?= icon($iconName, 'h-5 w-5') ?></span>
                <p class="min-w-0 flex-1 pt-0.5 text-sm text-slate-700">
                    <span class="block font-display font-bold text-brand-navy"><?= e($label) ?></span>
                    <?= e($message) ?>
                </p>
                <button type="button" class="rounded-lg p-1.5 text-brand-gray hover:bg-brand-50 hover:text-brand-navy" data-alert-dismiss hidden>
                    <?= icon('x', 'h-4 w-4') ?><span class="sr-only">Dismiss this message</span>
                </button>
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>
</div>
