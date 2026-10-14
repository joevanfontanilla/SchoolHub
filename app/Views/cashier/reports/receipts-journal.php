<?php
/**
 * Cash Receipts Journal (CRJ, docs/14): every official receipt over a date range in
 * journal form — Date, OR No., Payor, Particulars, Amount. Backed by the same payment
 * data as the Collections report, presented as the accounting-cycle journal.
 *
 * @var string  $from
 * @var string  $to
 * @var ?string $error
 * @var array   $report
 * @var array   $rows
 * @var int     $page
 * @var int     $pages
 */
use App\Services\FinanceService;

$query = http_build_query(['from' => $from, 'to' => $to]);
$net = $report['net'] < 0 ? signed_money($report['net']) : money(FinanceService::money($report['net']));
?>
<div class="space-y-6">
    <?= partial('cashier/report-header', ['active' => 'crj', 'heading' => 'Cash Receipts Journal', 'subtitle' => 'Official receipts issued over the period, in journal form (dated by the receipt).']) ?>

    <form method="GET" data-auto-submit action="<?= e(url('/cashier/reports/receipts-journal')) ?>" class="card flex flex-wrap items-end gap-4 p-4">
        <div>
            <label for="crj-from" class="field-label">From</label>
            <input id="crj-from" type="date" name="from" value="<?= e($from) ?>" max="<?= e(date('Y-m-d')) ?>" class="field-input">
        </div>
        <div>
            <label for="crj-to" class="field-label">To</label>
            <input id="crj-to" type="date" name="to" value="<?= e($to) ?>" class="field-input">
        </div>
        <button type="submit" class="btn-secondary"><?= icon('search', 'h-5 w-5') ?> Show</button>
        <div class="ml-auto flex flex-wrap gap-2">
            <a href="<?= e(url('/cashier/reports/receipts-journal/print?' . $query)) ?>" class="btn-secondary"><?= icon('document', 'h-5 w-5') ?> Print</a>
            <a href="<?= e(url('/cashier/reports/receipts-journal/export?' . $query)) ?>" class="btn-primary"><?= icon('download', 'h-5 w-5') ?> Export CSV</a>
        </div>
    </form>

    <?php if ($error !== null): ?>
        <p class="flex items-center gap-2 rounded-xl border border-warning-line bg-warning-bg px-4 py-3 text-sm text-warning-text" role="status"><?= icon('alert', 'h-5 w-5 shrink-0') ?> <?= e($error) ?></p>
    <?php endif; ?>

    <section class="card" aria-labelledby="crj-heading">
        <h3 id="crj-heading" class="border-b border-brand-100 px-5 py-4 text-base">Cash Receipts Journal · <?= e(d($from)) ?> to <?= e(d($to)) ?></h3>
        <?php if ($report['rows'] === []): ?>
            <?= component('empty-state', ['title' => 'No receipts in this period']) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                    <thead class="bg-brand-50 text-brand-navy">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Date</th>
                            <th scope="col" class="px-4 py-3 font-semibold">OR No.</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Payor</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Particulars</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Method</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Prepared by</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-50 bg-white">
                        <?php foreach ($rows as $row): ?>
                            <?php $reversal = $row['reversed_payment_id'] !== null; ?>
                            <tr class="hover:bg-brand-50/40">
                                <td class="whitespace-nowrap px-4 py-3"><?= e(dt($row['paid_at'], 'M j, Y')) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 font-medium text-brand-navy"><?= e($reversal ? '—' : (string) $row['receipt_number']) ?></td>
                                <td class="px-4 py-3"><?= e($row['last_name'] . ', ' . $row['first_name']) ?><span class="block text-xs text-brand-gray"><?= e($row['student_number'] ?? '') ?></span></td>
                                <td class="px-4 py-3"><?= e($reversal ? 'Reversal of ' . $row['reversed_receipt_number'] : 'Tuition & fees · ' . $row['assessment_number']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3"><?= e($row['payment_method']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3"><?= e($row['cashier_name']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums <?= $reversal ? 'text-danger-text' : 'text-brand-navy' ?>"><?= e($reversal ? signed_money(FinanceService::signedCents($row['amount'])) : money($row['amount'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-brand-50/60 font-semibold text-brand-navy">
                        <tr><th scope="row" colspan="6" class="px-4 py-3 text-right">Total<?= $pages > 1 ? ' (all pages)' : '' ?></th><td class="whitespace-nowrap px-4 py-3 text-right font-display text-lg tabular-nums"><?= e($net) ?></td></tr>
                    </tfoot>
                </table>
            </div>
            <?= partial('pagination', ['page' => $page, 'pages' => $pages, 'total' => count($report['rows']), 'path' => '/cashier/reports/receipts-journal', 'query' => ['from' => $from, 'to' => $to], 'noun' => 'receipt']) ?>
        <?php endif; ?>
    </section>
</div>
