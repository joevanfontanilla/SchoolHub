<?php
/**
 * Printable Cash Receipts Journal (docs/14).
 *
 * @var string $from
 * @var string $to
 * @var array  $report
 */
use App\Services\FinanceService;

$cell = 'border-b border-slate-200 px-2 py-1.5 align-top';
$net = $report['net'] < 0 ? signed_money($report['net']) : money(FinanceService::money($report['net']));
?>
<section class="mt-6 space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl font-extrabold uppercase tracking-wide text-brand-navy">Cash Receipts Journal</h1>
            <p class="mt-1 text-sm text-slate-600"><?= e(dt($from, 'F j, Y')) ?> to <?= e(dt($to, 'F j, Y')) ?></p>
        </div>
        <p class="text-right text-xs text-slate-500">Generated <?= e(dt(date('Y-m-d H:i:s'))) ?><br>by <?= e($user->fullName()) ?></p>
    </div>

    <?php if ($report['rows'] === []): ?>
        <p class="text-sm text-slate-600">No receipts were issued in this period.</p>
    <?php else: ?>
        <table class="w-full border-collapse text-xs">
            <thead>
                <tr class="bg-slate-100 text-left">
                    <th class="<?= $cell ?>">Date</th>
                    <th class="<?= $cell ?>">OR No.</th>
                    <th class="<?= $cell ?>">Payor</th>
                    <th class="<?= $cell ?>">Particulars</th>
                    <th class="<?= $cell ?>">Method</th>
                    <th class="<?= $cell ?> text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report['rows'] as $row): ?>
                    <?php $reversal = $row['reversed_payment_id'] !== null; ?>
                    <tr>
                        <td class="<?= $cell ?> whitespace-nowrap"><?= e(dt($row['paid_at'], 'Y-m-d')) ?></td>
                        <td class="<?= $cell ?> whitespace-nowrap"><?= e($reversal ? '—' : (string) $row['receipt_number']) ?></td>
                        <td class="<?= $cell ?>"><?= e($row['last_name'] . ', ' . $row['first_name']) ?></td>
                        <td class="<?= $cell ?>"><?= e($reversal ? 'Reversal of ' . $row['reversed_receipt_number'] : 'Tuition & fees · ' . $row['assessment_number']) ?></td>
                        <td class="<?= $cell ?> whitespace-nowrap"><?= e($row['payment_method']) ?></td>
                        <td class="<?= $cell ?> whitespace-nowrap text-right tabular-nums"><?= e($reversal ? signed_money(FinanceService::signedCents($row['amount'])) : money($row['amount'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="bg-slate-100 font-bold">
                    <td class="<?= $cell ?> text-right" colspan="5">Total</td>
                    <td class="<?= $cell ?> whitespace-nowrap text-right tabular-nums"><?= e($net) ?></td>
                </tr>
            </tfoot>
        </table>
    <?php endif; ?>
</section>
