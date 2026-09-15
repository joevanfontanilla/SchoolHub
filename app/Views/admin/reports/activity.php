<?php
/**
 * Activity summary (docs/19): how much was recorded in the audit log over a period, by
 * module, role, person, and action. Counts only; each entry's values stay in the log.
 *
 * @var string  $from
 * @var string  $to
 * @var ?string $error
 * @var array   $report
 * @var array   $chart   entries per day or month
 */
$log = static fn (array $params): string => url('/admin/audit-logs') . '?' . http_build_query($params + ['from' => $from, 'to' => $to]);
$head = 'px-4 py-3 font-semibold';
$num = 'px-4 py-3 text-right tabular-nums';
?>
<div class="space-y-6">
    <?= partial('admin/report-header', [
        'active' => 'activity', 'heading' => 'Activity Summary', 'path' => '/admin/reports/activity', 'from' => $from, 'to' => $to, 'error' => $error,
        'subtitle' => 'What was recorded in the audit log over a period. Open a group to see its entries.',
    ]) ?>

    <div class="grid gap-4 sm:grid-cols-2 2xl:grid-cols-4">
        <?= component('stat-card', ['label' => 'Audit entries', 'value' => number_format($report['total']), 'hint' => d($from) . ' to ' . d($to), 'icon' => 'list-check']) ?>
        <?= component('stat-card', ['label' => 'Refused or failed', 'value' => number_format($report['failures']), 'hint' => $report['total'] > 0 ? round($report['failures'] / $report['total'] * 100) . '% of entries' : 'None', 'icon' => 'alert', 'tone' => $report['failures'] > 0 ? 'warning' : 'neutral']) ?>
        <?= component('stat-card', ['label' => 'Modules', 'value' => (string) count($report['modules']), 'hint' => 'With at least one entry', 'icon' => 'layers']) ?>
        <?= component('stat-card', ['label' => 'People', 'value' => (string) count($report['actors']), 'hint' => count($report['actors']) === 10 ? 'The 10 most active are listed' : 'Recorded an action', 'icon' => 'users']) ?>
    </div>

    <section class="card p-5" aria-labelledby="chart-heading">
        <h3 id="chart-heading" class="mb-3 flex items-center gap-2 text-base"><span class="text-brand-600"><?= icon('chart-bar', 'h-5 w-5') ?></span> Entries <?= count($report['days']) <= 31 ? 'by day' : 'by month' ?></h3>
        <?= component('column-chart', ['items' => $chart, 'caption' => 'Audit log entries from ' . $from . ' to ' . $to]) ?>
    </section>

    <?php if ($report['total'] === 0): ?>
        <section class="card"><?= component('empty-state', ['title' => 'Nothing recorded', 'message' => 'The audit log has no entries in this period.']) ?></section>
    <?php else: ?>
        <div class="grid gap-6 xl:grid-cols-2">
            <section class="card min-w-0" aria-labelledby="modules-heading">
                <h3 id="modules-heading" class="border-b border-brand-100 px-5 py-4 text-base">By module</h3>
                <div class="table-scroll">
                    <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                        <thead class="bg-brand-50 text-brand-navy"><tr><th scope="col" class="<?= $head ?>">Module</th><th scope="col" class="<?= $head ?> text-right">Entries</th><th scope="col" class="<?= $head ?> text-right">Failures</th></tr></thead>
                        <tbody class="divide-y divide-brand-50 bg-white">
                            <?php foreach ($report['modules'] as $row): ?>
                                <tr>
                                    <td class="px-4 py-3"><a href="<?= e($log(['module' => $row['module']])) ?>" class="font-semibold text-brand-600 hover:underline"><?= e($row['module']) ?></a></td>
                                    <td class="<?= $num ?> font-semibold"><?= e(number_format((int) $row['entries'])) ?></td>
                                    <td class="<?= $num ?>"><?= e(number_format((int) $row['failures'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="card min-w-0" aria-labelledby="roles-heading">
                <h3 id="roles-heading" class="border-b border-brand-100 px-5 py-4 text-base">By role</h3>
                <div class="table-scroll">
                    <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                        <thead class="bg-brand-50 text-brand-navy"><tr><th scope="col" class="<?= $head ?>">Role</th><th scope="col" class="<?= $head ?> text-right">Entries</th><th scope="col" class="<?= $head ?> text-right">Failures</th></tr></thead>
                        <tbody class="divide-y divide-brand-50 bg-white">
                            <?php foreach ($report['roles'] as $row): ?>
                                <tr>
                                    <th scope="row" class="px-4 py-3 text-left font-semibold text-brand-navy"><?= e($row['actor_role']) ?></th>
                                    <td class="<?= $num ?> font-semibold"><?= e(number_format((int) $row['entries'])) ?></td>
                                    <td class="<?= $num ?>"><?= e(number_format((int) $row['failures'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <section class="card" aria-labelledby="actors-heading">
            <h3 id="actors-heading" class="border-b border-brand-100 px-5 py-4 text-base">Most active people</h3>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                    <thead class="bg-brand-50 text-brand-navy"><tr><th scope="col" class="<?= $head ?>">Person</th><th scope="col" class="<?= $head ?>">Role</th><th scope="col" class="<?= $head ?> text-right">Entries</th><th scope="col" class="<?= $head ?> text-right">Failures</th><th scope="col" class="<?= $head ?>">Last entry</th></tr></thead>
                    <tbody class="divide-y divide-brand-50 bg-white">
                        <?php foreach ($report['actors'] as $row): ?>
                            <tr>
                                <td class="px-4 py-3"><a href="<?= e($log(['user_id' => $row['user_id']])) ?>" class="font-semibold text-brand-600 hover:underline"><?= e(trim((string) $row['actor_name']) ?: '(removed account)') ?></a><?php if ($row['username'] !== null): ?><span class="block text-xs text-brand-gray"><?= e($row['username']) ?></span><?php endif; ?></td>
                                <td class="px-4 py-3"><?= e($row['actor_role']) ?></td>
                                <td class="<?= $num ?> font-semibold"><?= e(number_format((int) $row['entries'])) ?></td>
                                <td class="<?= $num ?>"><?= e(number_format((int) $row['failures'])) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600"><?= e(dt($row['last_entry'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card" aria-labelledby="actions-heading">
            <h3 id="actions-heading" class="border-b border-brand-100 px-5 py-4 text-base">Most frequent actions</h3>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                    <thead class="bg-brand-50 text-brand-navy"><tr><th scope="col" class="<?= $head ?>">Action</th><th scope="col" class="<?= $head ?>">Module</th><th scope="col" class="<?= $head ?> text-right">Entries</th><th scope="col" class="<?= $head ?> text-right">Failures</th></tr></thead>
                    <tbody class="divide-y divide-brand-50 bg-white">
                        <?php foreach ($report['actions'] as $row): ?>
                            <tr>
                                <td class="px-4 py-3"><a href="<?= e($log(['action' => $row['action']])) ?>" class="font-mono text-xs font-semibold text-brand-600 hover:underline"><?= e($row['action']) ?></a></td>
                                <td class="px-4 py-3"><?= e($row['module']) ?></td>
                                <td class="<?= $num ?> font-semibold"><?= e(number_format((int) $row['entries'])) ?></td>
                                <td class="<?= $num ?>"><?= e(number_format((int) $row['failures'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>
