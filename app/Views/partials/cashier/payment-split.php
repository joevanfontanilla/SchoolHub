<?php
/**
 * "Where the payments went" for one student account (docs/14, Payment breakdown): each line with what was
 * assessed and what has been paid toward it, by the allocation rule (old account first, then in proportion).
 *
 * @var array $split PaymentBreakdownService::forAssessment()
 */
use App\Services\FinanceService;

$money = static fn (int $cents): string => ($cents < 0 ? '-' : '') . money(FinanceService::money(abs($cents)));
$cell = 'px-3 py-2';
?>
<?php if ($split['rows'] === []): ?>
    <p class="text-sm text-brand-gray">This account has no charges yet.</p>
<?php else: ?>
    <div class="table-scroll">
        <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
            <caption class="sr-only">Payments split across the account's fee lines</caption>
            <thead class="bg-brand-50 text-brand-navy">
                <tr><th scope="col" class="<?= $cell ?> font-semibold">Fee line</th><th scope="col" class="<?= $cell ?> text-right font-semibold">Assessed</th><th scope="col" class="<?= $cell ?> text-right font-semibold">Paid toward it</th><th scope="col" class="<?= $cell ?> text-right font-semibold">Share</th></tr>
            </thead>
            <tbody class="divide-y divide-brand-50">
                <?php foreach ($split['rows'] as $row): ?>
                    <tr>
                        <td class="<?= $cell ?>"><?= e($row['label']) ?></td>
                        <td class="<?= $cell ?> whitespace-nowrap text-right tabular-nums"><?= e($money($row['assessed'])) ?></td>
                        <td class="<?= $cell ?> whitespace-nowrap text-right font-semibold tabular-nums"><?= e($money($row['paid'])) ?></td>
                        <td class="<?= $cell ?> whitespace-nowrap text-right tabular-nums text-slate-600"><?= e(number_format($row['share'], 1)) ?>%</td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($split['discounts'] !== 0): ?>
                    <tr><td class="<?= $cell ?> text-slate-600">Discounts, scholarships, and corrections</td><td class="<?= $cell ?> whitespace-nowrap text-right tabular-nums text-slate-600"><?= e($money($split['discounts'])) ?></td><td class="<?= $cell ?>"></td><td class="<?= $cell ?>"></td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot class="bg-brand-50/60 font-semibold text-brand-navy">
                <tr><th scope="row" class="<?= $cell ?> text-right">Amount due and paid</th><td class="<?= $cell ?> whitespace-nowrap text-right tabular-nums"><?= e($money($split['assessed'])) ?></td><td class="<?= $cell ?> whitespace-nowrap text-right tabular-nums"><?= e($money($split['paid'])) ?></td><td class="<?= $cell ?>"></td></tr>
                <tr><th scope="row" class="<?= $cell ?> text-right font-normal text-slate-600">Balance</th><td colspan="3" class="<?= $cell ?> whitespace-nowrap text-right tabular-nums"><?= e($money($split['balance'])) ?></td></tr>
            </tfoot>
        </table>
    </div>
    <p class="mt-2 text-xs text-brand-gray">Old account first, then in proportion to what each line was charged. This shows where the money went; it does not change the balance.</p>
<?php endif; ?>
