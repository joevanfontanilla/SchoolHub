<?php
/**
 * One announcement (docs/16).
 *
 * @var array   $announcement
 * @var ?string $preview   the state, when a manager opens one that readers cannot see
 * @var ?string $audience  shown to managers only
 */
?>
<div class="mx-auto max-w-3xl space-y-6">
    <a href="<?= e(url('/portal/announcements')) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; Announcements</a>

    <?php if ($preview !== null): ?>
        <p class="flex items-center gap-2 rounded-xl border border-warning-line bg-warning-bg px-4 py-3 text-sm text-warning-text" role="status">
            <?= icon('alert', 'h-5 w-5 shrink-0') ?> <span><strong>Preview.</strong> This announcement is <?= e(strtolower($preview)) ?>, so readers cannot see it right now.</span>
        </p>
    <?php endif; ?>

    <article class="card overflow-hidden">
        <?php if ($announcement['image_path']): ?>
            <img src="<?= e(url('/announcement-images/' . $announcement['id'])) ?>" alt="<?= e($announcement['image_alt'] ?? '') ?>" class="max-h-96 w-full object-cover">
        <?php endif; ?>
        <div class="p-5 sm:p-8">
            <div class="flex flex-wrap items-center gap-2 text-sm text-brand-gray">
                <?php if ((int) $announcement['is_urgent'] === 1): ?><?= component('status-badge', ['status' => 'URGENT', 'label' => 'Urgent']) ?><?php endif; ?>
                <span class="rounded-full bg-brand-50 px-2.5 py-0.5 text-xs font-semibold text-brand-700"><?= e($announcement['category'] ?? 'General') ?></span>
                <?php if ($announcement['published_at']): ?><time datetime="<?= e($announcement['published_at']) ?>"><?= e(dt($announcement['published_at'], 'F j, Y')) ?></time><?php endif; ?>
                <?php if ($announcement['expires_at']): ?><span>· until <?= e(dt($announcement['expires_at'], 'F j, Y g:i A')) ?></span><?php endif; ?>
            </div>
            <h2 class="mt-3 text-2xl sm:text-3xl"><?= e($announcement['title']) ?></h2>

            <div class="mt-6 space-y-4 text-slate-700">
                <?php foreach (preg_split('/\R{2,}/', (string) $announcement['content']) as $paragraph): ?>
                    <p><?= nl2br(e(trim($paragraph))) ?></p>
                <?php endforeach; ?>
            </div>

            <p class="mt-8 border-t border-brand-100 pt-4 text-sm text-brand-gray">
                Posted by <?= e($announcement['author_name'] ?? 'the school') ?>
                <?php if ($audience !== null): ?> · Audience: <?= e($audience) ?><?php endif; ?>
            </p>
        </div>
    </article>

    <?php if ($preview === null && (int) $announcement['is_urgent'] === 1): ?>
        <form method="POST" action="<?= e(url('/portal/announcements/' . $announcement['id'] . '/dismiss')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="return" value="announcement">
            <button type="submit" class="btn-secondary">Hide the banner for this announcement</button>
        </form>
    <?php endif; ?>
</div>
