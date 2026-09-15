<?php
/**
 * Collections report (docs/15): what was collected over a date range, net of
 * reversals, by day and by method, with every entry listed.
 *
 * @var string  $from
 * @var string  $to
 * @var ?string $error
 * @var array   $report  every entry and the totals
 * @var array   $rows    this page's entries
 * @var int     $page
 * @var int     $pages
 * @var array   $chart
 * @var bool    $daily   one bar per day (otherwise per month)
 */
use App\Services\FinanceService;

$query = http_build_query(['from' => $from, 'to' => $to]);
$reversals = count($report['rows']) - $report['count'];
// Net can be negative when a period holds only reversals of earlier payments.
$net = $report['net'] < 0 ? signed_money($report['net']) : money(FinanceService::money($report['net']));
?>
<div class="space-y-6">
    <?= partial('cashier/report-header', ['active' => 'collections', 'heading' => 'Collections Report', 'subtitle' => 'Payments recorded or verified over a period, net of reversals. Dated by when the cashier recorded them.']) ?>

    <form method="GET" action="<?= e(url('/cashier/reports/collections')) ?>" class="card flex flex-wrap items-end gap-4 p-4">
        <div>
            <label for="report-from" class="field-label">From</label>
            <input id="report-from" type="date" name="from" value="<?= e($from) ?>" max="<?= e(date('Y-m-d')) ?>" class="field-input">
        </div>
        <div>
            <label for="report-to" class="field-label">To</label>
            <input id="report-to" type="date" name="to" value="<?= e($to) ?>" class="field-input">
        </div>
        <button type="submit" class="btn-secondary"><?= icon('search', 'h-5 w-5') ?> Show</button>
        <div class="ml-auto flex flex-wrap gap-2">
            <a href="<?= e(url('/cashier/reports/collections/print?' . $query)) ?>" class="btn-secondary"><?= icon('document', 'h-5 w-5') ?> Print</a>
            <a href="<?= e(url('/cashier/reports/collections/export?' . $query)) ?>" class="btn-primary"><?= icon('download', 'h-5 w-5') ?> Export CSV</a>
        </div>
    </form>

    <?php if ($error !== null): ?>
        <p class="flex items-center gap-2 rounded-xl border border-warning-line bg-warning-bg px-4 py-3 text-sm text-warning-text" role="status"><?= icon('alert', 'h-5 w-5 shrink-0') ?> <?= e($error) ?></p>
    <?php endif; ?>

    <div class="grid gap-4 sm:grid-cols-2 2xl:grid-cols-4">
        <?= component('stat-card', ['label' => 'Net collections', 'value' => $net, 'hint' => d($from) . ' to ' . d($to), 'icon' => 'banknotes', 'tone' => 'success']) ?>
        <?= component('stat-card', ['label' => 'Payments', 'value' => (string) $report['count'], 'hint' => 'Gross ' . money(FinanceService::money($report['gross'])), 'icon' => 'receipt']) ?>
        <?= component('stat-card', ['label' => 'Reversals', 'value' => (string) $reversals, 'hint' => $reversals === 0 ? 'None in this period' : signed_money($report['reversed']), 'icon' => 'history', 'tone' => $reversals > 0 ? 'warning' : 'neutral']) ?>
        <?= component('stat-card', ['label' => 'Days with collections', 'value' => (string) count(array_filter($report['by_day'], static fn ($d) => $d['cents'] !== 0)), 'hint' => 'In the selected period', 'icon' => 'calendar']) ?>
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <section class="card p-5 xl:col-span-2" aria-labelledby="chart-heading">
            <h3 id="chart-heading" class="mb-3 flex items-center gap-2 text-base"><span class="text-brand-600"><?= icon('chart-bar', 'h-5 w-5') ?></span> Net collections <?= $daily ? 'by day' : 'by month' ?></h3>
            <?= component('column-chart', ['items' => $chart, 'caption' => 'Net collections from ' . $from . ' to ' . $to]) ?>
        </section>

        <section class="card p-5" aria-labelledby="method-heading">
            <h3 id="method-heading" class="mb-3 flex items-center gap-2 text-base"><span class="text-brand-600"><?= icon('credit-card', 'h-5 w-5') ?></span> By payment method</h3>
            <?php if ($report['by_method'] === []): ?>
                <p class="text-sm text-brand-gray">Nothing collected in this period.</p>
            <?php else: ?>
                <ul class="space-y-3 text-sm">
                    <?php foreach ($report['by_method'] as $method => $cents): ?>
                        <li>
                            <div class="flex justify-between gap-3"><span class="font-medium text-brand-navy"><?= e($method) ?></span><span class="tabular-nums font-semibold"><?= e(money(FinanceService::money(max(0, $cents)))) ?></span></div>
                            <?= component('progress-bar', ['percent' => $report['net'] > 0 ? max(0, $cents) / $report['net'] * 100 : 0, 'label' => $method . ' share of net collections']) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>

    <section class="card" aria-labelledby="entries-heading">
        <h3 id="entries-heading" class="border-b border-brand-100 px-5 py-4 text-base">Entries</h3>
        <?php if ($report['rows'] === []): ?>
            <?= component('empty-state', ['title' => 'No payments in this period']) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                    <thead class="bg-brand-50 text-brand-navy">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Recorded</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Receipt</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Student</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Method</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Amount</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Cashier</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-50 bg-white">
                        <?php foreach ($rows as $row): ?>
                            <?php $reversal = $row['reversed_payment_id'] !== null; ?>
                            <tr class="hover:bg-brand-50/40">
                                <td class="whitespace-nowrap px-4 py-3"><?= e(dt($row['created_at'])) ?></td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <?php if ($reversal): ?>
                                        <?= component('status-badge', ['status' => 'REJECTED', 'label' => 'Reversal']) ?><span class="block text-xs text-brand-gray">of <?= e($row['reversed_receipt_number']) ?></span>
                                    <?php else: ?>
                                        <a href="<?= e(url('/receipts/' . $row['id'])) ?>" class="font-semibold text-brand-600 hover:underline"><?= e($row['receipt_number']) ?></a><span class="block text-xs text-brand-gray"><?= e($row['transaction_number']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3"><a href="<?= e(url('/cashier/accounts/' . $row['fee_assessment_id'])) ?>" class="font-semibold text-brand-navy hover:underline"><?= e($row['last_name'] . ', ' . $row['first_name']) ?></a><span class="block text-xs text-brand-gray"><?= e($row['student_number'] ?? $row['assessment_number']) ?></span></td>
                                <td class="whitespace-nowrap px-4 py-3"><?= e($row['payment_method']) ?><?php if ($row['reference_number']): ?><span class="block text-xs text-brand-gray">Ref. <?= e($row['reference_number']) ?></span><?php endif; ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums <?= $reversal ? 'text-danger-text' : 'text-brand-navy' ?>"><?= e($reversal ? signed_money(FinanceService::signedCents($row['amount'])) : money($row['amount'])) ?></td>
                                <td class="whitespace-nowrap px-4 py-3"><?= e($row['cashier_name']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-brand-50/60 font-semibold text-brand-navy">
                        <tr><th scope="row" colspan="4" class="px-4 py-3 text-right">Net total<?= $pages > 1 ? ' (all pages)' : '' ?></th><td class="whitespace-nowrap px-4 py-3 text-right font-display text-lg tabular-nums"><?= e($net) ?></td><td></td></tr>
                    </tfoot>
                </table>
            </div>
            <?= partial('pagination', ['page' => $page, 'pages' => $pages, 'total' => count($report['rows']), 'path' => '/cashier/reports/collections', 'query' => ['from' => $from, 'to' => $to], 'noun' => 'entry']) ?>
        <?php endif; ?>
    </section>
</div>
