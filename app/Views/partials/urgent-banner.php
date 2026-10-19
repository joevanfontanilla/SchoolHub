<?php
/**
 * Urgent announcements for the signed-in user, at the top of every portal page
 * (docs/16, docs/21 "important alerts"). Already filtered to the user's audience
 * and to what they have not dismissed.
 *
 * @var array $urgent
 */
if ($urgent === []) {
    return;
}
?>
<section aria-label="Urgent announcements" class="relative z-10 mb-6 space-y-3">
    <?php foreach ($urgent as $announcement): ?>
        <div class="flex flex-wrap items-center gap-3 rounded-2xl border border-danger-line bg-white p-3 pr-2 shadow-sm sm:flex-nowrap" role="alert">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-danger-bg text-danger-text ring-1 ring-danger-line"><?= icon('megaphone', 'h-5 w-5') ?></span>
            <p class="min-w-0 flex-1 text-sm text-slate-700">
                <span class="block font-display font-bold text-danger-text">Urgent announcement</span>
                <a href="<?= e(url('/portal/announcements/' . $announcement['id'])) ?>" class="font-semibold text-brand-navy underline decoration-brand-200 underline-offset-2 hover:decoration-2"><?= e($announcement['title']) ?></a>
                <?php if ($announcement['expires_at']): ?><span class="text-xs text-brand-gray">· until <?= e(dt($announcement['expires_at'])) ?></span><?php endif; ?>
            </p>
            <form method="POST" action="<?= e(url('/portal/announcements/' . $announcement['id'] . '/dismiss')) ?>" class="ml-auto">
                <?= csrf_field() ?>
                <button type="submit" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-brand-gray hover:bg-brand-50 hover:text-brand-navy">
                    <?= icon('x', 'h-4 w-4') ?> Dismiss<span class="sr-only">: <?= e($announcement['title']) ?></span>
                </button>
            </form>
        </div>
    <?php endforeach; ?>
</section>
