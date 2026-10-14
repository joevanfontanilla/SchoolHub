<?php
/**
 * The cash book sheet, laid out like the school's paper book (docs/14): Cash Inflow on the left,
 * Cash Outflow on the right, then the summary. Shared by the page and the printout.
 *
 * @var array $book CashBookService::month()
 */
use App\Services\FinanceService;

$money = static fn (int $cents): string => ($cents < 0 ? '-' : '') . money(FinanceService::money(abs($cents)));
$cell = 'border border-slate-300 px-2 py-1.5';
$head = $cell . ' bg-brand-50 text-left font-semibold text-brand-navy';
$counted = $book['counted'];
?>
<div class="grid gap-4 lg:grid-cols-2 print:grid-cols-2">
    <table class="w-full self-start border border-slate-300 text-sm">
        <caption class="border border-b-0 border-slate-300 bg-slate-100 px-2 py-1.5 text-center font-display font-semibold text-brand-navy">Cash Inflow</caption>
        <thead>
            <tr><th scope="col" class="<?= $head ?>">Date</th><th scope="col" class="<?= $head ?>">Transaction details</th><th scope="col" class="<?= $head ?> text-right">Amount</th></tr>
        </thead>
        <tbody>
            <tr class="bg-slate-50 font-semibold">
                <td class="<?= $cell ?> whitespace-nowrap"><?= e(d($book['from'] . ' 00:00:00')) ?></td>
                <td class="<?= $cell ?> text-right">Balance C/F</td>
                <td class="<?= $cell ?> text-right tabular-nums <?= $book['carriedForward'] < 0 ? 'text-danger-text' : '' ?>"><?= e($money($book['carriedForward'])) ?></td>
            </tr>
            <?php foreach ($book['inflows'] as $row): ?>
                <tr>
                    <td class="<?= $cell ?> whitespace-nowrap"><?= e(d($row['at'])) ?></td>
                    <td class="<?= $cell ?>"><span class="font-semibold text-brand-navy"><?= e($row['ref']) ?></span> <?= e($row['particulars']) ?></td>
                    <td class="<?= $cell ?> text-right tabular-nums <?= $row['amount'] < 0 ? 'text-danger-text' : '' ?>"><?= e($money($row['amount'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="bg-brand-50/60 font-semibold text-brand-navy"><th scope="row" colspan="2" class="<?= $cell ?> text-right">Total inflow</th><td class="<?= $cell ?> text-right tabular-nums"><?= e($money($book['totalInflow'])) ?></td></tr>
        </tfoot>
    </table>

    <table class="w-full self-start border border-slate-300 text-sm">
        <caption class="border border-b-0 border-slate-300 bg-slate-100 px-2 py-1.5 text-center font-display font-semibold text-brand-navy">Cash Outflow</caption>
        <thead>
            <tr><th scope="col" class="<?= $head ?>">Date</th><th scope="col" class="<?= $head ?>">Transaction details</th><th scope="col" class="<?= $head ?> text-right">Amount</th></tr>
        </thead>
        <tbody>
            <?php foreach ($book['outflows'] as $row): ?>
                <tr>
                    <td class="<?= $cell ?> whitespace-nowrap"><?= e(d($row['at'])) ?></td>
                    <td class="<?= $cell ?>"><span class="font-semibold text-brand-navy"><?= e($row['ref']) ?></span> <?= e($row['particulars']) ?></td>
                    <td class="<?= $cell ?> text-right tabular-nums"><?= e($money($row['amount'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($book['outflows'] === []): ?>
                <tr><td colspan="3" class="<?= $cell ?> text-center text-brand-gray">Nothing paid out this month.</td></tr>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr class="bg-brand-50/60 font-semibold text-brand-navy"><th scope="row" colspan="2" class="<?= $cell ?> text-right">Total outflow</th><td class="<?= $cell ?> text-right tabular-nums"><?= e($money($book['totalOutflow'])) ?></td></tr>
        </tfoot>
    </table>
</div>

<table class="mt-4 w-full border border-slate-300 text-sm sm:w-2/3 lg:w-1/2">
    <caption class="border border-b-0 border-slate-300 bg-slate-100 px-2 py-1.5 text-center font-display font-semibold text-brand-navy">Cash Book Summary</caption>
    <tbody>
        <tr><th scope="row" class="<?= $cell ?> text-left font-medium">Cash inflow (with balance C/F)</th><td class="<?= $cell ?> text-right tabular-nums"><?= e($money($book['totalInflow'])) ?></td></tr>
        <tr><th scope="row" class="<?= $cell ?> text-left font-medium">Cash outflow</th><td class="<?= $cell ?> text-right tabular-nums"><?= e($money($book['totalOutflow'])) ?></td></tr>
        <tr class="bg-brand-50/60 font-semibold text-brand-navy"><th scope="row" class="<?= $cell ?> text-left">Closing balance</th><td class="<?= $cell ?> text-right font-display text-base tabular-nums <?= $book['closing'] < 0 ? 'text-danger-text' : '' ?>"><?= e($money($book['closing'])) ?></td></tr>
        <?php if ($counted !== null): ?>
            <tr><th scope="row" class="<?= $cell ?> text-left font-medium">Counted at day close<span class="block text-xs font-normal text-brand-gray"><?= e((string) $counted['closedDays']) ?> day<?= $counted['closedDays'] === 1 ? '' : 's' ?> closed<?= $counted['openDays'] > 0 ? ', ' . e((string) $counted['openDays']) . ' with cash activity not closed' : '' ?></span></th><td class="<?= $cell ?> text-right tabular-nums"><?= e($money($counted['counted'])) ?></td></tr>
            <tr><th scope="row" class="<?= $cell ?> text-left font-medium">Over/short</th><td class="<?= $cell ?> text-right tabular-nums <?= $counted['difference'] < 0 ? 'text-danger-text' : '' ?>"><?= e($money($counted['difference'])) ?></td></tr>
        <?php endif; ?>
    </tbody>
</table>
