<?php
/**
 * Sign-in summary (docs/07): attempts over a period, each day's failures, and where
 * failures come from. The full list of attempts is under Sign-in Activity.
 *
 * @var string  $from
 * @var string  $to
 * @var ?string $error
 * @var array   $report
 * @var array   $chart   failed attempts per day or month
 */
$rate = $report['total'] > 0 ? round($report['success'] / $report['total'] * 100) : null;
$activity = static fn (array $params): string => url('/admin/login-activity') . '?' . http_build_query($params + ['from' => $from, 'to' => $to]);
?>
<div class="space-y-6">
    <?= partial('admin/report-header', [
        'active' => 'sign-ins', 'heading' => 'Sign-in Summary', 'path' => '/admin/reports/sign-ins', 'from' => $from, 'to' => $to, 'error' => $error,
        'subtitle' => 'Sign-in attempts over a period. Many failures from one address or against one account can mean someone is guessing passwords.',
    ]) ?>

    <div class="grid gap-4 sm:grid-cols-2 2xl:grid-cols-4">
        <?= component('stat-card', ['label' => 'Attempts', 'value' => number_format($report['total']), 'hint' => d($from) . ' to ' . d($to), 'icon' => 'key']) ?>
        <?= component('stat-card', ['label' => 'Successful', 'value' => number_format($report['success']), 'hint' => $rate === null ? 'No attempts' : $rate . '% of attempts', 'icon' => 'check', 'tone' => 'success']) ?>
        <?= component('stat-card', ['label' => 'Failed', 'value' => number_format($report['failed']), 'hint' => $report['failed'] === 0 ? 'None' : 'Wrong password, unknown account, or locked', 'icon' => 'alert', 'tone' => $report['failed'] > 0 ? 'warning' : 'neutral']) ?>
        <?= component('stat-card', ['label' => 'Accounts signed in', 'value' => number_format($report['accounts']), 'hint' => 'From ' . number_format($report['ips']) . ' address' . ($report['ips'] === 1 ? '' : 'es'), 'icon' => 'users']) ?>
    </div>

    <section class="card p-5" aria-labelledby="chart-heading">
        <h3 id="chart-heading" class="mb-3 flex items-center gap-2 text-base"><span class="text-brand-600"><?= icon('chart-bar', 'h-5 w-5') ?></span> Failed attempts <?= count($report['days']) <= 31 ? 'by day' : 'by month' ?></h3>
        <?= component('column-chart', ['items' => $chart, 'caption' => 'Failed sign-in attempts from ' . $from . ' to ' . $to]) ?>
    </section>

    <div class="grid gap-6 xl:grid-cols-2">
        <section class="card min-w-0" aria-labelledby="ips-heading">
            <h3 id="ips-heading" class="border-b border-brand-100 px-5 py-4 text-base">Addresses with the most failures</h3>
            <?php if ($report['ip_failures'] === []): ?>
                <?= component('empty-state', ['title' => 'No failed attempts', 'message' => 'Every attempt in this period succeeded.']) ?>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                        <thead class="bg-brand-50 text-brand-navy">
                            <tr><th scope="col" class="px-4 py-3 font-semibold">IP address</th><th scope="col" class="px-4 py-3 text-right font-semibold">Failed</th><th scope="col" class="px-4 py-3 text-right font-semibold">Succeeded</th><th scope="col" class="px-4 py-3 text-right font-semibold">Logins tried</th><th scope="col" class="px-4 py-3 font-semibold">Last attempt</th></tr>
                        </thead>
                        <tbody class="divide-y divide-brand-50 bg-white">
                            <?php foreach ($report['ip_failures'] as $row): ?>
                                <tr>
                                    <td class="px-4 py-3 font-mono text-xs"><a href="<?= e($activity(['ip' => $row['ip_address']])) ?>" class="text-brand-600 hover:underline"><?= e($row['ip_address']) ?></a></td>
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums"><?= e(number_format((int) $row['failed'])) ?></td>
                                    <td class="px-4 py-3 text-right tabular-nums"><?= e(number_format((int) $row['success'])) ?></td>
                                    <td class="px-4 py-3 text-right tabular-nums"><?= e(number_format((int) $row['logins'])) ?></td>
                                    <td class="whitespace-nowrap px-4 py-3 text-slate-600"><?= e(dt($row['last_attempt'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="card min-w-0" aria-labelledby="logins-heading">
            <h3 id="logins-heading" class="border-b border-brand-100 px-5 py-4 text-base">Logins with the most failures</h3>
            <?php if ($report['login_failures'] === []): ?>
                <?= component('empty-state', ['title' => 'No failed attempts', 'message' => 'Every attempt in this period succeeded.']) ?>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                        <thead class="bg-brand-50 text-brand-navy">
                            <tr><th scope="col" class="px-4 py-3 font-semibold">Login tried</th><th scope="col" class="px-4 py-3 text-right font-semibold">Failed</th><th scope="col" class="px-4 py-3 text-right font-semibold">Succeeded</th><th scope="col" class="px-4 py-3 text-right font-semibold">Addresses</th><th scope="col" class="px-4 py-3 font-semibold">Account</th></tr>
                        </thead>
                        <tbody class="divide-y divide-brand-50 bg-white">
                            <?php foreach ($report['login_failures'] as $row): ?>
                                <tr>
                                    <td class="px-4 py-3"><a href="<?= e($activity(['login' => $row['username_attempted']])) ?>" class="text-brand-600 hover:underline"><?= e($row['username_attempted']) ?></a></td>
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums"><?= e(number_format((int) $row['failed'])) ?></td>
                                    <td class="px-4 py-3 text-right tabular-nums"><?= e(number_format((int) $row['success'])) ?></td>
                                    <td class="px-4 py-3 text-right tabular-nums"><?= e(number_format((int) $row['ips'])) ?></td>
                                    <td class="whitespace-nowrap px-4 py-3">
                                        <?php if ($row['user_id'] !== null): ?>
                                            <a href="<?= e(url('/admin/users/' . $row['user_id'])) ?>" class="text-brand-600 hover:underline"><?= e($row['status']) ?></a>
                                        <?php else: ?>
                                            <span class="text-brand-gray">No such account</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>
