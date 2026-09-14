<?php
/**
 * Announcements panel for the dashboards.
 *
 * The list is already filtered by audience in the query (Announcement::forUser)
 * — this partial does not do the filtering (docs/16).
 *
 * @var array $announcements
 */
?>
<section class="card" aria-labelledby="announcements-heading">
    <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
        <h2 id="announcements-heading" class="flex items-center gap-2 text-base font-semibold text-brand-navy"><span class="text-brand-600"><?= icon('megaphone', 'h-5 w-5') ?></span> Announcements</h2>
        <a href="<?= e(url('/portal/announcements')) ?>" class="text-sm font-semibold text-brand-600 hover:underline">All</a>
    </div>

    <?php if ($announcements === []): ?>
        <?= component('empty-state', [
            'title'   => 'No announcements',
            'message' => 'School announcements will appear here.',
        ]) ?>
    <?php else: ?>
        <ul class="divide-y divide-slate-100">
            <?php foreach ($announcements as $announcement): ?>
                <li class="px-5 py-4">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="text-sm font-medium text-brand-navy">
                            <?php if (!empty($announcement['is_urgent'])): ?><span class="mr-1 text-xs font-bold uppercase text-danger-text">Urgent:</span><?php endif; ?>
                            <a href="<?= e(url('/portal/announcements/' . $announcement['id'])) ?>" class="hover:underline"><?= e($announcement['title']) ?></a>
                        </h3>
                        <time class="text-xs text-brand-gray" datetime="<?= e($announcement['published_at'] ?? '') ?>">
                            <?= e(d($announcement['published_at'] ?? null)) ?>
                        </time>
                    </div>
                    <p class="mt-1 text-sm text-slate-600">
                        <?= e(str_limit(strip_tags((string) $announcement['content']), 160)) ?>
                    </p>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
