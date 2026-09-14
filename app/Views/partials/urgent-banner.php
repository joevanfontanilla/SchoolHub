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
<section aria-label="Urgent announcements" class="mb-5 space-y-2">
    <?php foreach ($urgent as $announcement): ?>
        <div class="flex flex-wrap items-center gap-3 rounded-xl border border-danger-line bg-danger-bg px-4 py-3 text-sm text-danger-text" role="alert">
            <?= icon('megaphone', 'h-5 w-5 shrink-0') ?>
            <p class="min-w-0 flex-1">
                <span class="font-bold uppercase tracking-wide">Urgent:</span>
                <a href="<?= e(url('/portal/announcements/' . $announcement['id'])) ?>" class="font-semibold underline decoration-danger-line underline-offset-2 hover:decoration-2"><?= e($announcement['title']) ?></a>
                <?php if ($announcement['expires_at']): ?><span class="text-xs">· until <?= e(dt($announcement['expires_at'])) ?></span><?php endif; ?>
            </p>
            <form method="POST" action="<?= e(url('/portal/announcements/' . $announcement['id'] . '/dismiss')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold hover:bg-white/60">
                    <?= icon('x', 'h-4 w-4') ?> Dismiss<span class="sr-only">: <?= e($announcement['title']) ?></span>
                </button>
            </form>
        </div>
    <?php endforeach; ?>
</section>
