<?php
/** Super Admin dashboard (docs/20). */
?>
<div class="space-y-6">
    <div>
        <h2 class="text-lg font-semibold text-brand-navy">Welcome, <?= e($user->first_name) ?></h2>
        <p class="mt-1 text-sm text-slate-600">System administration, accounts, and activity.</p>
    </div>

    <?= partial('dashboard-cards', ['cards' => $cards]) ?>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <?= partial('announcement-list', ['announcements' => $announcements]) ?>
        </div>

        <section class="card" aria-labelledby="health-heading">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 id="health-heading" class="text-base font-semibold text-brand-navy">System health</h2>
            </div>
            <dl class="divide-y divide-slate-100">
                <?php foreach ($health as $label => $value): ?>
                    <div class="flex items-center justify-between gap-4 px-5 py-3">
                        <dt class="text-sm text-slate-600"><?= e($label) ?></dt>
                        <dd class="text-sm font-medium text-brand-navy"><?= e($value) ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        </section>
    </div>
</div>
