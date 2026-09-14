<?php
/**
 * Error page.
 *
 * @var int     $status
 * @var string  $message
 * @var ?string $detail  populated only when app.debug is on and the status is 5xx
 */
$headings = [
    401 => 'Sign-in required',
    403 => 'Access denied',
    404 => 'Page not found',
    405 => 'Action not allowed',
    429 => 'Too many attempts',
];
$heading = $headings[$status] ?? 'Something went wrong';
?>
<div class="text-center">
    <p class="text-sm font-semibold uppercase tracking-wider text-brand-600">Error <?= e($status) ?></p>
    <h1 class="mt-2 text-2xl font-bold text-brand-navy sm:text-3xl"><?= e($heading) ?></h1>
    <p class="mt-3 text-slate-600"><?= e($message) ?></p>

    <div class="mt-8 flex flex-wrap justify-center gap-3">
        <a href="<?= e(url('/')) ?>" class="btn-primary">Go to the home page</a>
        <?php if (App\Core\Auth::check()): ?>
            <a href="<?= e(url(App\Core\Auth::homePath())) ?>" class="btn-secondary">My dashboard</a>
        <?php else: ?>
            <a href="<?= e(url('/login')) ?>" class="btn-secondary">Sign in</a>
        <?php endif; ?>
    </div>

    <?php if (!empty($detail)): ?>
        <details class="mt-10 text-left">
            <summary class="cursor-pointer text-sm font-medium text-slate-700">Technical details (debug mode)</summary>
            <pre class="mt-3 overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-100"><?= e($detail) ?></pre>
        </details>
    <?php endif; ?>
</div>
