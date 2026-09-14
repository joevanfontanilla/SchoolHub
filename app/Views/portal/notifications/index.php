<?php
/**
 * The notifications inbox (docs/16).
 *
 * @var string $filter  all | unread | archived
 * @var array  $notifications
 * @var array  $counts
 * @var int    $page
 * @var int    $pages
 * @var int    $total
 */
$tabs = ['all' => 'Inbox', 'unread' => 'Unread', 'archived' => 'Archived'];
$typeIcon = static fn (string $type): string => match (strtok($type, '.')) {
    'announcement' => 'megaphone',
    'finance'      => 'banknotes',
    'grades'       => 'chart-bar',
    'requirement'  => 'requirements',
    'enrollment'   => 'enrollment',
    default        => 'bell',
};
?>
<div class="mx-auto max-w-4xl space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">Communication</p>
            <h2 class="mt-1 text-2xl">Notifications</h2>
            <p class="mt-1 text-sm text-slate-600">Updates about your account, enrollment, payments, and announcements.</p>
        </div>
        <?php if ($counts['unread'] > 0): ?>
            <form method="POST" action="<?= e(url('/portal/notifications/read-all')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn-secondary"><?= icon('check', 'h-5 w-5') ?> Mark all as read</button>
            </form>
        <?php endif; ?>
    </div>

    <nav aria-label="Notification folders" class="flex flex-wrap gap-2">
        <?php foreach ($tabs as $key => $label): ?>
            <a href="<?= e(url('/portal/notifications' . ($key !== 'all' ? '?filter=' . $key : ''))) ?>" <?= $filter === $key ? 'aria-current="page"' : '' ?>
               class="inline-flex items-center gap-2 rounded-full border px-4 py-1.5 text-sm font-semibold <?= $filter === $key ? 'border-brand-600 bg-brand-600 text-white' : 'border-brand-100 bg-white text-brand-navy hover:bg-brand-50' ?>">
                <?= e($label) ?> <span class="rounded-full px-1.5 text-xs <?= $filter === $key ? 'bg-white/20' : 'bg-brand-50' ?>"><?= e((string) $counts[$key]) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <section class="card">
        <?php if ($notifications === []): ?>
            <?= component('empty-state', ['title' => match ($filter) { 'unread' => 'You are all caught up', 'archived' => 'Nothing archived', default => 'No notifications yet' }, 'message' => $filter === 'all' ? 'Updates about your enrollment, payments, and school news will appear here.' : null]) ?>
        <?php else: ?>
            <ul class="divide-y divide-brand-50">
                <?php foreach ($notifications as $notification): ?>
                    <?php $unread = $notification['state'] === 'UNREAD'; ?>
                    <li class="flex gap-4 px-5 py-4 <?= $unread ? 'bg-brand-50/50' : '' ?>">
                        <span class="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full <?= $unread ? 'bg-brand-600 text-white' : 'bg-brand-50 text-brand-600' ?>"><?= icon($typeIcon((string) $notification['type']), 'h-5 w-5') ?></span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <form method="POST" action="<?= e(url('/portal/notifications/' . $notification['id'] . '/open')) ?>" class="min-w-0">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="text-left font-semibold text-brand-navy hover:underline <?= $unread ? '' : 'font-medium' ?>">
                                        <?= e($notification['title']) ?>
                                    </button>
                                </form>
                                <span class="flex shrink-0 items-center gap-2">
                                    <?php if ($unread): ?><?= component('status-badge', ['status' => 'UNREAD', 'label' => 'New']) ?><?php endif; ?>
                                    <time class="text-xs text-brand-gray" datetime="<?= e($notification['created_at']) ?>"><?= e(dt($notification['created_at'])) ?></time>
                                </span>
                            </div>
                            <?php if ($notification['body']): ?>
                                <p class="mt-1 text-sm text-slate-600"><?= e(str_limit((string) $notification['body'], 240)) ?></p>
                            <?php endif; ?>
                            <div class="mt-2 flex flex-wrap gap-4 text-xs">
                                <?php if ($filter === 'archived'): ?>
                                    <form method="POST" action="<?= e(url('/portal/notifications/' . $notification['id'] . '/restore')) ?>">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="font-semibold text-brand-600 hover:underline">Move to inbox</button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" action="<?= e(url('/portal/notifications/' . $notification['id'] . '/archive')) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="filter" value="<?= e($filter) ?>">
                                        <button type="submit" class="font-semibold text-brand-gray hover:text-brand-navy hover:underline">Archive<span class="sr-only">: <?= e($notification['title']) ?></span></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?= partial('pagination', ['page' => $page, 'pages' => $pages, 'total' => $total, 'path' => '/portal/notifications', 'query' => ['filter' => $filter === 'all' ? null : $filter], 'noun' => 'notification']) ?>
        <?php endif; ?>
    </section>
</div>
