<?php
/**
 * Cash Disbursements Journal (CDJ, docs/14): every disbursement voucher over a date range —
 * Date, Voucher No., Payee, Particulars, Amount.
 *
 * @var string  $from
 * @var string  $to
 * @var ?string $error
 * @var array   $report  ['rows' => ..., 'count' => int, 'total' => string]
 * @var array   $rows
 * @var int     $page
 * @var int     $pages
 */
$query = http_build_query(['from' => $from, 'to' => $to]);
?>
<div class="space-y-6">
    <?= partial('cashier/report-header', ['active' => 'cdj', 'heading' => 'Cash Disbursements Journal', 'subtitle' => 'Money paid out on vouchers over the period, in journal form.']) ?>

    <form method="GET" data-auto-submit action="<?= e(url('/cashier/reports/disbursements-journal')) ?>" class="card flex flex-wrap items-end gap-4 p-4">
        <div>
            <label for="cdj-from" class="field-label">From</label>
            <input id="cdj-from" type="date" name="from" value="<?= e($from) ?>" max="<?= e(date('Y-m-d')) ?>" class="field-input">
        </div>
        <div>
            <label for="cdj-to" class="field-label">To</label>
            <input id="cdj-to" type="date" name="to" value="<?= e($to) ?>" class="field-input">
        </div>
        <button type="submit" class="btn-secondary"><?= icon('search', 'h-5 w-5') ?> Show</button>
        <div class="ml-auto flex flex-wrap gap-2">
            <a href="<?= e(url('/cashier/disbursements')) ?>" class="btn-secondary"><?= icon('list-check', 'h-5 w-5') ?> All vouchers</a>
            <a href="<?= e(url('/cashier/disbursements/create')) ?>" class="btn-secondary"><?= icon('plus', 'h-5 w-5') ?> New voucher</a>
            <a href="<?= e(url('/cashier/reports/disbursements-journal/print?' . $query)) ?>" class="btn-secondary"><?= icon('document', 'h-5 w-5') ?> Print</a>
            <a href="<?= e(url('/cashier/reports/disbursements-journal/export?' . $query)) ?>" class="btn-primary"><?= icon('download', 'h-5 w-5') ?> Export CSV</a>
        </div>
    </form>

    <?php if ($error !== null): ?>
        <p class="flex items-center gap-2 rounded-xl border border-warning-line bg-warning-bg px-4 py-3 text-sm text-warning-text" role="status"><?= icon('alert', 'h-5 w-5 shrink-0') ?> <?= e($error) ?></p>
    <?php endif; ?>

    <section class="card" aria-labelledby="cdj-heading">
        <h3 id="cdj-heading" class="border-b border-brand-100 px-5 py-4 text-base">Cash Disbursements Journal · <?= e(d($from)) ?> to <?= e(d($to)) ?></h3>
        <?php if ($report['rows'] === []): ?>
            <?= component('empty-state', ['title' => 'No disbursements in this period', 'message' => 'Record one with "New voucher".']) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                    <thead class="bg-brand-50 text-brand-navy">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Date</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Voucher No.</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Payee</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Particulars</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Method</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-50 bg-white">
                        <?php foreach ($rows as $row): ?>
                            <tr class="hover:bg-brand-50/40">
                                <td class="whitespace-nowrap px-4 py-3"><?= e(dt($row['disbursed_at'], 'M j, Y')) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 font-medium text-brand-navy">
                                    <a href="<?= e(url('/cashier/disbursements/' . $row['id'] . '/voucher')) ?>" class="hover:underline"><?= e($row['voucher_number']) ?></a>
                                </td>
                                <td class="px-4 py-3"><?= e($row['payee']) ?></td>
                                <td class="px-4 py-3"><?= e($row['particulars']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3"><?= e($row['payment_method']) ?><?php if ($row['reference_number']): ?><span class="block text-xs text-brand-gray">Ref. <?= e($row['reference_number']) ?></span><?php endif; ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums text-brand-navy"><?= e(money($row['amount'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-brand-50/60 font-semibold text-brand-navy">
                        <tr><th scope="row" colspan="5" class="px-4 py-3 text-right">Total<?= $pages > 1 ? ' (all pages)' : '' ?></th><td class="whitespace-nowrap px-4 py-3 text-right font-display text-lg tabular-nums"><?= e(money($report['total'])) ?></td></tr>
                    </tfoot>
                </table>
            </div>
            <?= partial('pagination', ['page' => $page, 'pages' => $pages, 'total' => count($report['rows']), 'path' => '/cashier/reports/disbursements-journal', 'query' => ['from' => $from, 'to' => $to], 'noun' => 'voucher']) ?>
        <?php endif; ?>
    </section>
</div>
