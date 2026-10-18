<?php
/**
 * Registrar dashboard (docs/11).
 *
 * @var array $queue        oldest needs-action applications
 * @var int   $needsAction
 * @var int   $enrolled
 */
?>
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h2 class="text-xl">Welcome, <?= e($user->first_name) ?></h2>
            <p class="mt-1 text-sm text-slate-600">
                <?= $needsAction > 0
                    ? e($needsAction) . ' application' . ($needsAction === 1 ? ' needs' : 's need') . ' your attention.'
                    : 'No applications are waiting for you.' ?>
                <?= e($enrolled) ?> enrolled so far.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <?php if (auth_user()?->hasPermission('enrollment.walkin')): ?>
                <a href="<?= e(url('/registrar/walk-in')) ?>" class="btn-secondary"><?= icon('plus', 'h-5 w-5') ?> Walk-in enrollment</a>
            <?php endif; ?>
            <a href="<?= e(url('/registrar/applications')) ?>" class="btn-primary">Open the queue</a>
        </div>
    </div>

    <?= partial('dashboard-cards', ['cards' => $cards]) ?>

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="card lg:col-span-2" aria-labelledby="queue-heading">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                <h2 id="queue-heading" class="text-base">Needs action</h2>
                <a href="<?= e(url('/registrar/applications')) ?>" class="text-sm font-semibold text-brand-600 hover:underline">See all</a>
            </div>
            <?php if ($queue === []): ?>
                <?= component('empty-state', ['title' => 'All caught up', 'message' => 'New submissions will appear here.']) ?>
            <?php else: ?>
                <ul class="divide-y divide-slate-100">
                    <?php foreach (array_slice($queue, 0, 8) as $row): ?>
                        <li>
                            <a href="<?= e(url('/registrar/applications/' . $row['id'])) ?>" class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 hover:bg-brand-50/50">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-brand-navy"><?= e($row['last_name'] . ', ' . $row['first_name']) ?></p>
                                    <p class="text-xs text-brand-gray">
                                        <?= e($row['application_number']) ?> · <?= e($row['program_code']) ?> · submitted <?= e(dt($row['submitted_at'], 'M j, g:i A')) ?>
                                    </p>
                                </div>
                                <?= component('status-badge', ['status' => $row['application_status']]) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <?= partial('announcement-list', ['announcements' => $announcements]) ?>
    </div>
</div>
