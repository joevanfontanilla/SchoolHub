<?php
/**
 * System health (docs/20).
 *
 * @var array  $checks
 * @var string $overall OK | WARN | FAIL
 * @var array  $errors  recent PHP error log lines
 */
$tone = static fn (string $status) => component('status-badge', match ($status) {
    'OK'   => ['status' => 'APPROVED', 'label' => 'OK'],
    'WARN' => ['status' => 'PENDING', 'label' => 'Check'],
    default => ['status' => 'REJECTED', 'label' => 'Problem'],
});
$groups = [];
foreach ($checks as $check) {
    $groups[$check['group']][] = $check;
}
?>
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">Monitoring</p>
            <h2 class="mt-1 text-2xl">System Health</h2>
            <p class="mt-1 text-sm text-slate-600">Checked just now.</p>
        </div>
        <div class="flex items-center gap-2 text-sm font-semibold text-brand-navy">
            Overall <?= $tone($overall) ?>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <?php foreach ($groups as $group => $items): ?>
            <section class="card" aria-labelledby="health-<?= e(strtolower($group)) ?>">
                <h3 id="health-<?= e(strtolower($group)) ?>" class="border-b border-brand-100 px-5 py-4 text-base"><?= e($group) ?></h3>
                <dl class="divide-y divide-brand-50">
                    <?php foreach ($items as $item): ?>
                        <div class="flex flex-wrap items-start justify-between gap-3 px-5 py-3">
                            <dt class="text-sm font-medium text-brand-navy"><?= e($item['label']) ?><span class="block text-xs font-normal text-slate-600"><?= e($item['detail']) ?></span></dt>
                            <dd><?= $tone($item['status']) ?></dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
            </section>
        <?php endforeach; ?>
    </div>

    <section class="card" aria-labelledby="errors-heading">
        <h3 id="errors-heading" class="border-b border-brand-100 px-5 py-4 text-base">Recent PHP errors <span class="text-xs font-normal text-brand-gray">(storage/logs/php-error.log)</span></h3>
        <?php if ($errors === []): ?>
            <p class="px-5 py-4 text-sm text-brand-gray">No errors logged.</p>
        <?php else: ?>
            <pre class="table-scroll max-h-96 overflow-y-auto whitespace-pre-wrap break-words p-5 text-xs text-slate-800"><?= e(implode("\n", $errors)) ?></pre>
        <?php endif; ?>
    </section>
</div>
