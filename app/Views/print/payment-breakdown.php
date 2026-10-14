<?php
/**
 * Printable payment breakdown (docs/14).
 *
 * @var string $from
 * @var string $to
 * @var string $scope
 * @var array  $report PaymentBreakdownService::report()
 */
use App\Services\FinanceService;

$money = static fn (int $cents): string => ($cents < 0 ? '-' : '') . money(FinanceService::money(abs($cents)));
$cell = 'border-b border-slate-200 px-2 py-1.5 align-top';
?>
<section class="mt-6 space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl font-extrabold uppercase tracking-wide text-brand-navy">Payment Breakdown</h1>
            <p class="mt-1 text-sm text-slate-600"><?= e(dt($from, 'F j, Y')) ?> to <?= e(dt($to, 'F j, Y')) ?><?= $scope !== '' ? ' · ' . e($scope) : '' ?></p>
        </div>
        <p class="text-right text-xs text-slate-500">Generated <?= e(dt(date('Y-m-d H:i:s'))) ?><br>by <?= e($user->fullName()) ?></p>
    </div>

    <?php if ($report['lines'] === []): ?>
        <p class="text-sm text-slate-600">No payments were recorded in this period.</p>
    <?php else: ?>
        <table class="w-full border-collapse text-xs">
            <thead><tr class="bg-slate-100 text-left"><th class="<?= $cell ?>">Fee line</th><th class="<?= $cell ?> text-right">Assessed</th><th class="<?= $cell ?> text-right">Collected</th><th class="<?= $cell ?> text-right">Share</th></tr></thead>
            <tbody>
                <?php foreach ($report['lines'] as $row): ?>
                    <tr><td class="<?= $cell ?>"><?= e($row['label']) ?></td><td class="<?= $cell ?> text-right tabular-nums"><?= e($money($row['assessed'])) ?></td><td class="<?= $cell ?> text-right tabular-nums"><?= e($money($row['collected'])) ?></td><td class="<?= $cell ?> text-right tabular-nums"><?= e(number_format($row['share'], 1)) ?>%</td></tr>
                <?php endforeach; ?>
                <?php if ($report['discounts'] !== 0): ?>
                    <tr><td class="<?= $cell ?>">Discounts, scholarships, and corrections</td><td class="<?= $cell ?> text-right tabular-nums"><?= e($money($report['discounts'])) ?></td><td class="<?= $cell ?>"></td><td class="<?= $cell ?>"></td></tr>
                <?php endif; ?>
            </tbody>
            <tfoot><tr class="bg-slate-100 font-bold"><td class="<?= $cell ?> text-right">Total</td><td class="<?= $cell ?> text-right tabular-nums"><?= e($money($report['assessed'])) ?></td><td class="<?= $cell ?> text-right tabular-nums"><?= e($money($report['collected'])) ?></td><td class="<?= $cell ?> text-right">100%</td></tr></tfoot>
        </table>

        <div class="grid gap-6 sm:grid-cols-2">
            <table class="w-full border-collapse text-xs">
                <caption class="mb-1 text-left font-semibold text-brand-navy">By payment method</caption>
                <thead><tr class="bg-slate-100 text-left"><th class="<?= $cell ?>">Method</th><th class="<?= $cell ?> text-right">Payments</th><th class="<?= $cell ?> text-right">Collected</th></tr></thead>
                <tbody>
                    <?php foreach ($report['methods'] as $method => $row): ?>
                        <tr><td class="<?= $cell ?>"><?= e($method) ?></td><td class="<?= $cell ?> text-right tabular-nums"><?= e((string) $row['count']) ?></td><td class="<?= $cell ?> text-right tabular-nums"><?= e($money($row['cents'])) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <table class="w-full border-collapse text-xs">
                <caption class="mb-1 text-left font-semibold text-brand-navy">By day</caption>
                <thead><tr class="bg-slate-100 text-left"><th class="<?= $cell ?>">Day</th><th class="<?= $cell ?> text-right">Payments</th><th class="<?= $cell ?> text-right">Collected</th></tr></thead>
                <tbody>
                    <?php foreach ($report['days'] as $day => $row): ?>
                        <tr><td class="<?= $cell ?>"><?= e(dt($day . ' 00:00:00', 'M j, Y')) ?></td><td class="<?= $cell ?> text-right tabular-nums"><?= e((string) $row['count']) ?></td><td class="<?= $cell ?> text-right tabular-nums"><?= e($money($row['cents'])) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?= partial('cashier/prepared-by', ['user' => $user]) ?>
</section>
