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
    <div class="border-b border-slate-200 px-5 py-4">
        <h2 id="announcements-heading" class="text-base font-semibold text-brand-navy">Announcements</h2>
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
                        <h3 class="text-sm font-medium text-brand-navy"><?= e($announcement['title']) ?></h3>
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
