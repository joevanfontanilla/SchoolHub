<?php
/** A single public announcement (docs/06). */
?>
<div class="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">
    <a href="<?= e(url('/announcements')) ?>" class="text-sm font-medium text-brand-600 hover:underline">
        &larr; All announcements
    </a>

    <article class="mt-6">
        <div class="flex flex-wrap items-center gap-3">
            <time class="text-sm text-brand-gray" datetime="<?= e($announcement['published_at'] ?? '') ?>">
                <?= e(d($announcement['published_at'] ?? null)) ?>
            </time>
            <?php if (!empty($announcement['category'])): ?>
                <span class="badge bg-slate-100 text-slate-700"><?= e($announcement['category']) ?></span>
            <?php endif; ?>
        </div>

        <h1 class="mt-3 text-2xl font-bold text-brand-navy sm:text-3xl"><?= e($announcement['title']) ?></h1>

        <div class="mt-6 space-y-4 text-slate-700">
            <?php foreach (preg_split('/\R{2,}/', (string) $announcement['content']) as $paragraph): ?>
                <p><?= nl2br(e(trim($paragraph))) ?></p>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($announcement['first_name'])): ?>
            <p class="mt-8 border-t border-slate-200 pt-4 text-sm text-brand-gray">
                Posted by <?= e($announcement['first_name'] . ' ' . $announcement['last_name']) ?>
            </p>
        <?php endif; ?>
    </article>
</div>
