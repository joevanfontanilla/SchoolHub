<?php
/**
 * Announcements for the signed-in user (docs/16). Already filtered to their audience.
 *
 * @var string $category
 * @var array  $announcements
 * @var int    $page
 * @var int    $pages
 * @var int    $total
 * @var bool   $canManage
 */
use App\Models\Announcement;
?>
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">Communication</p>
            <h2 class="mt-1 text-2xl">Announcements</h2>
            <p class="mt-1 text-sm text-slate-600">News from the school for you. Urgent ones are listed first.</p>
        </div>
        <?php if ($canManage): ?>
            <a href="<?= e(url('/manage/announcements')) ?>" class="btn-secondary"><?= icon('pencil', 'h-5 w-5') ?> Manage announcements</a>
        <?php endif; ?>
    </div>

    <nav aria-label="Categories" class="flex flex-wrap gap-2">
        <?php foreach (['' => 'All', ...array_combine(Announcement::CATEGORIES, Announcement::CATEGORIES)] as $value => $label): ?>
            <a href="<?= e(url('/portal/announcements' . ($value !== '' ? '?category=' . rawurlencode($value) : ''))) ?>" <?= $category === $value ? 'aria-current="page"' : '' ?>
               class="rounded-full border px-4 py-1.5 text-sm font-semibold <?= $category === $value ? 'border-brand-600 bg-brand-600 text-white' : 'border-brand-100 bg-white text-brand-navy hover:bg-brand-50' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($announcements === []): ?>
        <section class="card"><?= component('empty-state', ['title' => 'No announcements', 'message' => $category !== '' ? 'Nothing in this category right now.' : 'School announcements for you will appear here.']) ?></section>
    <?php else: ?>
        <ul class="grid gap-4 lg:grid-cols-2">
            <?php foreach ($announcements as $announcement): ?>
                <li class="card flex flex-col overflow-hidden <?= (int) $announcement['is_urgent'] === 1 ? 'ring-2 ring-danger-line' : '' ?>">
                    <?php if ($announcement['image_path']): ?>
                        <img src="<?= e(url('/announcement-images/' . $announcement['id'])) ?>" alt="<?= e($announcement['image_alt'] ?? '') ?>" class="h-40 w-full object-cover" loading="lazy">
                    <?php endif; ?>
                    <div class="flex flex-1 flex-col p-5">
                        <div class="flex flex-wrap items-center gap-2 text-xs text-brand-gray">
                            <?php if ((int) $announcement['is_urgent'] === 1): ?><?= component('status-badge', ['status' => 'URGENT', 'label' => 'Urgent']) ?><?php endif; ?>
                            <span class="rounded-full bg-brand-50 px-2 py-0.5 font-semibold text-brand-700"><?= e($announcement['category'] ?? 'General') ?></span>
                            <time datetime="<?= e($announcement['published_at']) ?>"><?= e(d($announcement['published_at'])) ?></time>
                        </div>
                        <h3 class="mt-2 text-lg"><a href="<?= e(url('/portal/announcements/' . $announcement['id'])) ?>" class="text-brand-navy hover:underline"><?= e($announcement['title']) ?></a></h3>
                        <p class="mt-2 flex-1 text-sm text-slate-600"><?= e(str_limit(preg_replace('/\s+/', ' ', (string) $announcement['content']), 220)) ?></p>
                        <a href="<?= e(url('/portal/announcements/' . $announcement['id'])) ?>" class="mt-3 text-sm font-semibold text-brand-600 hover:underline">Read more<span class="sr-only">: <?= e($announcement['title']) ?></span></a>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="card"><?= partial('pagination', ['page' => $page, 'pages' => $pages, 'total' => $total, 'path' => '/portal/announcements', 'query' => ['category' => $category], 'noun' => 'announcement']) ?></div>
    <?php endif; ?>
</div>
