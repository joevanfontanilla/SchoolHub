<?php
/**
 * Printable Cash Disbursements Journal (docs/14).
 *
 * @var string $from
 * @var string $to
 * @var array  $report
 */
$cell = 'border-b border-slate-200 px-2 py-1.5 align-top';
?>
<section class="mt-6 space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl font-extrabold uppercase tracking-wide text-brand-navy">Cash Disbursements Journal</h1>
            <p class="mt-1 text-sm text-slate-600"><?= e(dt($from, 'F j, Y')) ?> to <?= e(dt($to, 'F j, Y')) ?></p>
        </div>
        <p class="text-right text-xs text-slate-500">Generated <?= e(dt(date('Y-m-d H:i:s'))) ?><br>by <?= e($user->fullName()) ?></p>
    </div>

    <?php if ($report['rows'] === []): ?>
        <p class="text-sm text-slate-600">No disbursements were recorded in this period.</p>
    <?php else: ?>
        <table class="w-full border-collapse text-xs">
            <thead>
                <tr class="bg-slate-100 text-left">
                    <th class="<?= $cell ?>">Date</th>
                    <th class="<?= $cell ?>">Voucher No.</th>
                    <th class="<?= $cell ?>">Payee</th>
                    <th class="<?= $cell ?>">Particulars</th>
                    <th class="<?= $cell ?>">Method</th>
                    <th class="<?= $cell ?>">Prepared by</th>
                    <th class="<?= $cell ?> text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report['rows'] as $row): ?>
                    <tr>
                        <td class="<?= $cell ?> whitespace-nowrap"><?= e(dt($row['disbursed_at'], 'Y-m-d')) ?></td>
                        <td class="<?= $cell ?> whitespace-nowrap"><?= e($row['voucher_number']) ?></td>
                        <td class="<?= $cell ?>"><?= e($row['payee']) ?></td>
                        <td class="<?= $cell ?>"><?= e($row['particulars']) ?></td>
                        <td class="<?= $cell ?> whitespace-nowrap"><?= e($row['payment_method']) ?></td>
                        <td class="<?= $cell ?>"><?= e(trim($row['first_name'] . ' ' . $row['last_name'])) ?></td>
                        <td class="<?= $cell ?> whitespace-nowrap text-right tabular-nums"><?= e(money($row['amount'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="bg-slate-100 font-bold">
                    <td class="<?= $cell ?> text-right" colspan="6">Total</td>
                    <td class="<?= $cell ?> whitespace-nowrap text-right tabular-nums"><?= e(money($report['total'])) ?></td>
                </tr>
            </tfoot>
        </table>
    <?php endif; ?>

    <?= partial('cashier/prepared-by', ['user' => $user]) ?>
</section>
