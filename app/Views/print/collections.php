<?php
/**
 * Printable collections report (docs/15).
 *
 * @var string $from
 * @var string $to
 * @var array  $report
 */
use App\Services\FinanceService;

$cell = 'border-b border-slate-200 px-2 py-1.5';
$net = $report['net'] < 0 ? signed_money($report['net']) : money(FinanceService::money($report['net']));
?>
<section class="mt-6 space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl font-extrabold uppercase tracking-wide text-brand-navy">Collections Report</h1>
            <p class="mt-1 text-sm text-slate-600"><?= e(dt($from, 'F j, Y')) ?> to <?= e(dt($to, 'F j, Y')) ?></p>
        </div>
        <p class="text-right text-xs text-slate-500">Generated <?= e(dt(date('Y-m-d H:i:s'))) ?><br>by <?= e($user->fullName()) ?></p>
    </div>

    <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
        <div class="rounded border border-slate-300 p-3"><dt class="text-slate-500">Payments</dt><dd class="font-display text-lg font-bold"><?= e((string) $report['count']) ?></dd></div>
        <div class="rounded border border-slate-300 p-3"><dt class="text-slate-500">Gross</dt><dd class="font-display text-lg font-bold tabular-nums"><?= e(money(FinanceService::money($report['gross']))) ?></dd></div>
        <div class="rounded border border-slate-300 p-3"><dt class="text-slate-500">Reversals</dt><dd class="font-display text-lg font-bold tabular-nums"><?= e($report['reversed'] === 0 ? money('0') : signed_money($report['reversed'])) ?></dd></div>
        <div class="rounded border border-slate-300 p-3"><dt class="text-slate-500">Net collections</dt><dd class="font-display text-lg font-bold tabular-nums text-brand-navy"><?= e($net) ?></dd></div>
    </dl>

    <div class="grid gap-6 sm:grid-cols-2">
        <div class="overflow-x-auto print:overflow-visible">
            <table class="w-full border border-slate-300 text-sm">
                <caption class="mb-1 text-left font-display font-bold text-brand-navy">By day</caption>
                <thead class="bg-brand-50 text-left text-brand-navy"><tr><th scope="col" class="<?= $cell ?>">Date</th><th scope="col" class="<?= $cell ?> text-right">Payments</th><th scope="col" class="<?= $cell ?> text-right">Net</th></tr></thead>
                <tbody>
                    <?php foreach ($report['by_day'] as $day => $totals): ?>
                        <tr><td class="<?= $cell ?>"><?= e(dt($day, 'D, M j')) ?></td><td class="<?= $cell ?> text-right"><?= e((string) $totals['count']) ?></td><td class="<?= $cell ?> text-right tabular-nums"><?= e($totals['cents'] < 0 ? signed_money($totals['cents']) : money(FinanceService::money($totals['cents']))) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if ($report['by_day'] === []): ?><tr><td colspan="3" class="<?= $cell ?> text-slate-500">No collections.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="overflow-x-auto print:overflow-visible">
            <table class="w-full self-start border border-slate-300 text-sm">
                <caption class="mb-1 text-left font-display font-bold text-brand-navy">By method</caption>
                <thead class="bg-brand-50 text-left text-brand-navy"><tr><th scope="col" class="<?= $cell ?>">Method</th><th scope="col" class="<?= $cell ?> text-right">Net</th></tr></thead>
                <tbody>
                    <?php foreach ($report['by_method'] as $method => $cents): ?>
                        <tr><td class="<?= $cell ?>"><?= e($method) ?></td><td class="<?= $cell ?> text-right tabular-nums"><?= e($cents < 0 ? signed_money($cents) : money(FinanceService::money($cents))) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if ($report['by_method'] === []): ?><tr><td colspan="2" class="<?= $cell ?> text-slate-500">No collections.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="overflow-x-auto print:overflow-visible">
        <table class="w-full border border-slate-300 text-xs">
            <caption class="mb-1 text-left font-display text-sm font-bold text-brand-navy">Entries</caption>
            <thead class="bg-brand-50 text-left text-brand-navy">
                <tr><th scope="col" class="<?= $cell ?>">Recorded</th><th scope="col" class="<?= $cell ?>">Receipt / Txn</th><th scope="col" class="<?= $cell ?>">Student</th><th scope="col" class="<?= $cell ?>">Method</th><th scope="col" class="<?= $cell ?> text-right">Amount</th><th scope="col" class="<?= $cell ?>">Cashier</th></tr>
            </thead>
            <tbody>
                <?php foreach ($report['rows'] as $row): ?>
                    <?php $reversal = $row['reversed_payment_id'] !== null; ?>
                    <tr>
                        <td class="<?= $cell ?> whitespace-nowrap"><?= e(dt($row['created_at'], 'M j g:i A')) ?></td>
                        <td class="<?= $cell ?>"><?= $reversal ? 'Reversal of ' . e($row['reversed_receipt_number']) : e($row['receipt_number']) ?><span class="block text-slate-500"><?= e($row['transaction_number']) ?></span></td>
                        <td class="<?= $cell ?>"><?= e($row['last_name'] . ', ' . $row['first_name']) ?><span class="block text-slate-500"><?= e($row['student_number'] ?? $row['assessment_number']) ?></span></td>
                        <td class="<?= $cell ?>"><?= e($row['payment_method']) ?></td>
                        <td class="<?= $cell ?> whitespace-nowrap text-right tabular-nums"><?= e($reversal ? signed_money(FinanceService::signedCents($row['amount'])) : money($row['amount'])) ?></td>
                        <td class="<?= $cell ?>"><?= e($row['cashier_name']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="font-semibold"><tr><th scope="row" colspan="4" class="px-2 py-2 text-right">Net total</th><td class="px-2 py-2 text-right tabular-nums"><?= e($net) ?></td><td></td></tr></tfoot>
        </table>
    </div>

    <div class="flex justify-end pt-8">
        <div class="w-64 text-center text-sm">
            <?= partial('signature-image', ['userId' => $user->id, 'name' => $user->fullName()]) ?>
            <p class="border-b border-slate-400 pb-1 font-semibold"><?= e($user->fullName()) ?></p>
            <p class="mt-1 text-slate-500">Prepared by</p>
        </div>
    </div>
</section>
