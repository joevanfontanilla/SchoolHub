<?php
/**
 * Super Admin dashboard (docs/20).
 *
 * @var array  $cards
 * @var array  $announcements
 * @var array  $checks    health checks that are not OK
 * @var string $overall
 * @var array  $activity  latest audit entries
 */
$links = [
    ['/admin/users/create', 'New staff account', 'plus'],
    ['/admin/backups', 'Back up now', 'database'],
    ['/admin/audit-logs', 'Audit logs', 'list-check'],
    ['/admin/settings', 'System settings', 'settings'],
];
?>
<div class="space-y-6">
    <div>
        <h2 class="text-lg font-semibold text-brand-navy">Welcome, <?= e($user->first_name) ?></h2>
        <p class="mt-1 text-sm text-slate-600">System administration, accounts, and activity.</p>
    </div>

    <?= partial('dashboard-cards', ['cards' => $cards]) ?>

    <nav aria-label="Quick actions" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <?php foreach ($links as [$path, $label, $iconName]): ?>
            <a href="<?= e(url($path)) ?>" class="card flex items-center gap-3 p-4 font-semibold text-brand-navy hover:bg-brand-50"><span class="text-brand-600"><?= icon($iconName, 'h-6 w-6') ?></span> <?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="card" aria-labelledby="activity-heading">
                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                    <h2 id="activity-heading" class="flex items-center gap-2 text-base font-semibold text-brand-navy"><span class="text-brand-600"><?= icon('list-check', 'h-5 w-5') ?></span> Recent activity</h2>
                    <a href="<?= e(url('/admin/audit-logs')) ?>" class="text-sm font-semibold text-brand-600 hover:underline">All</a>
                </div>
                <?php if ($activity === []): ?>
                    <?= component('empty-state', ['title' => 'No activity recorded']) ?>
                <?php else: ?>
                    <ul class="divide-y divide-slate-100">
                        <?php foreach ($activity as $entry): ?>
                            <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm">
                                <span><a href="<?= e(url('/admin/audit-logs/' . $entry['id'])) ?>" class="font-medium text-brand-navy hover:underline"><?= e($entry['action']) ?></a> <span class="text-brand-gray">· <?= e($entry['actor_name'] ?? $entry['actor_role']) ?></span><?= $entry['result'] !== 'SUCCESS' ? ' <span class="font-semibold text-danger-text">· Failure</span>' : '' ?></span>
                                <time class="text-xs text-brand-gray" datetime="<?= e($entry['created_at']) ?>"><?= e(dt($entry['created_at'])) ?></time>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
            <?= partial('announcement-list', ['announcements' => $announcements]) ?>
        </div>

        <section class="card self-start" aria-labelledby="health-heading">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                <h2 id="health-heading" class="text-base font-semibold text-brand-navy">System health</h2>
                <?= component('status-badge', match ($overall) { 'OK' => ['status' => 'APPROVED', 'label' => 'All OK'], 'WARN' => ['status' => 'PENDING', 'label' => 'Check'], default => ['status' => 'REJECTED', 'label' => 'Problem'] }) ?>
            </div>
            <?php if ($checks === []): ?>
                <p class="px-5 py-4 text-sm text-slate-600">Every check passed.</p>
            <?php else: ?>
                <ul class="divide-y divide-slate-100">
                    <?php foreach ($checks as $check): ?>
                        <li class="px-5 py-3 text-sm">
                            <span class="font-medium text-brand-navy"><?= e($check['label']) ?></span>
                            <span class="ml-1 text-xs font-semibold <?= $check['status'] === 'FAIL' ? 'text-danger-text' : 'text-warning-text' ?>"><?= $check['status'] === 'FAIL' ? 'Problem' : 'Check' ?></span>
                            <span class="block text-xs text-slate-600"><?= e($check['detail']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <a href="<?= e(url('/admin/health')) ?>" class="block border-t border-slate-200 px-5 py-3 text-center text-sm font-semibold text-brand-600 hover:bg-brand-50">Full system health</a>
        </section>
    </div>
</div>
