<?php
/** Public announcements listing with pagination (docs/06, docs/21). */
?>
<div class="mx-auto max-w-4xl px-4 py-12 sm:px-6 lg:px-8">
    <h1 class="text-2xl font-bold text-brand-navy sm:text-3xl">Announcements</h1>
    <p class="mt-2 text-slate-600">News and notices from the school.</p>

    <?php if ($announcements === []): ?>
        <div class="card mt-8">
            <?= component('empty-state', [
                'title'   => 'No announcements yet',
                'message' => 'Published announcements will appear here.',
            ]) ?>
        </div>
    <?php else: ?>
        <div class="mt-8 space-y-5">
            <?php foreach ($announcements as $announcement): ?>
                <article class="card p-6">
                    <div class="flex flex-wrap items-center gap-3">
                        <time class="text-xs text-brand-gray" datetime="<?= e($announcement['published_at'] ?? '') ?>">
                            <?= e(d($announcement['published_at'] ?? null)) ?>
                        </time>
                        <?php if (!empty($announcement['category'])): ?>
                            <span class="badge bg-slate-100 text-slate-700"><?= e($announcement['category']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($announcement['is_urgent'])): ?>
                            <?= component('status-badge', ['status' => 'URGENT', 'label' => 'Urgent']) ?>
                        <?php endif; ?>
                    </div>
                    <h2 class="mt-2 text-lg font-semibold text-brand-navy">
                        <a class="hover:text-brand-600 hover:underline" href="<?= e(url('/announcements/' . $announcement['slug'])) ?>">
                            <?= e($announcement['title']) ?>
                        </a>
                    </h2>
                    <p class="mt-2 text-sm text-slate-600">
                        <?= e(str_limit(strip_tags((string) $announcement['content']), 220)) ?>
                    </p>
                </article>
            <?php endforeach; ?>
        </div>

        <?php if ($pages > 1): ?>
            <nav class="mt-8 flex items-center justify-between" aria-label="Announcements pagination">
                <?php if ($page > 1): ?>
                    <a href="<?= e(url('/announcements?page=' . ($page - 1))) ?>" class="btn-secondary">Previous</a>
                <?php else: ?>
                    <span></span>
                <?php endif; ?>

                <p class="text-sm text-slate-600">Page <?= e($page) ?> of <?= e($pages) ?></p>

                <?php if ($page < $pages): ?>
                    <a href="<?= e(url('/announcements?page=' . ($page + 1))) ?>" class="btn-secondary">Next</a>
                <?php else: ?>
                    <span></span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
