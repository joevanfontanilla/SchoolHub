<?php
/**
 * The confirmation window used by every action that asks first (docs/21). Filled and opened
 * by app.js for a form with data-confirm or a submit button with data-confirm-click:
 *
 *   data-confirm / data-confirm-click  the question (required)
 *   data-confirm-title                 heading; defaults to the button's words with "?"
 *   data-confirm-tone                  danger | warning | success | primary (guessed when absent)
 *   data-confirm-label                 the confirming button's words; defaults to the button's
 *   data-confirm-requires              selector of a field that must be filled first (a reason)
 *   data-confirm-requires-message      what to say when it is empty
 *
 * A native <dialog>: Escape or "Go back" cancels, focus stays inside and returns to the
 * button. Without JavaScript the action runs without asking, as the server validates anyway.
 */
$tiles = [
    'danger'  => ['bg-danger-bg text-danger-text ring-danger-line', 'alert'],
    'warning' => ['bg-warning-bg text-warning-text ring-warning-line', 'alert'],
    'success' => ['bg-success-bg text-success-text ring-success-line', 'check'],
    'primary' => ['bg-brand-50 text-brand-600 ring-brand-200', 'info'],
];
?>
<dialog class="confirm-dialog" aria-labelledby="confirm-title" aria-describedby="confirm-message" data-confirm-dialog>
    <div class="p-6 sm:p-7">
        <div class="flex items-start gap-4">
            <?php foreach ($tiles as $tone => [$classes, $iconName]): ?>
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl ring-1 <?= $classes ?>" data-confirm-tile="<?= e($tone) ?>" hidden><?= icon($iconName, 'h-6 w-6') ?></span>
            <?php endforeach; ?>
            <div class="min-w-0 pt-0.5">
                <h2 id="confirm-title" class="font-display text-lg font-bold text-brand-navy" data-confirm-heading>Are you sure?</h2>
                <p id="confirm-message" class="mt-1.5 text-sm leading-relaxed text-slate-600" data-confirm-text></p>
            </div>
        </div>
        <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <button type="button" class="btn-secondary" data-confirm-cancel>Go back</button>
            <button type="button" class="btn-primary" data-confirm-accept>Confirm</button>
        </div>
    </div>
</dialog>
