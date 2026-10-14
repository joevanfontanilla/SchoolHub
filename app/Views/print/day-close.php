<?php
/**
 * Turnover slip: what the cashier handed over at the end of the day (docs/14).
 *
 * @var array $close
 * @var array $summary DayCloseService::summary()
 */
use App\Services\FinanceService;

$money = static fn (int $cents): string => money(FinanceService::money($cents));
$row = 'flex justify-between gap-4 border-b border-slate-100 py-2';
?>
<section class="mt-6 space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl font-extrabold uppercase tracking-wide text-brand-navy">Turnover Slip</h1>
            <p class="mt-1 text-sm text-slate-600">Cashier report for <?= e(dt($close['close_date'] . ' 00:00:00', 'F j, Y')) ?></p>
        </div>
        <p class="text-right text-sm text-slate-600">Closed <?= e(dt($close['closed_at'])) ?></p>
    </div>

    <div class="grid gap-6 sm:grid-cols-2">
        <dl class="text-sm">
            <div class="<?= $row ?>"><dt class="text-slate-500">Payments recorded</dt><dd class="font-semibold"><?= e((string) $close['payment_count']) ?></dd></div>
            <?php foreach ($summary['methods'] as $method => $cents): ?>
                <div class="<?= $row ?>"><dt class="text-slate-500"><?= e($method) ?></dt><dd class="font-semibold tabular-nums"><?= e($money($cents)) ?></dd></div>
            <?php endforeach; ?>
        </dl>
        <dl class="text-sm">
            <div class="<?= $row ?>"><dt class="text-slate-500">Cash received</dt><dd class="font-semibold tabular-nums"><?= e(money($close['received_cash'])) ?></dd></div>
            <div class="<?= $row ?>"><dt class="text-slate-500">Cash paid out</dt><dd class="font-semibold tabular-nums"><?= e(money($close['paid_out_cash'])) ?></dd></div>
            <div class="<?= $row ?>"><dt class="text-slate-500">Expected cash</dt><dd class="font-semibold tabular-nums"><?= e(money($close['expected_cash'])) ?></dd></div>
            <div class="<?= $row ?>"><dt class="text-slate-500">Cash counted</dt><dd class="font-display text-lg font-bold tabular-nums text-brand-navy"><?= e(money($close['counted_cash'])) ?></dd></div>
            <div class="<?= $row ?>"><dt class="text-slate-500">Difference</dt><dd class="font-semibold tabular-nums"><?= e(signed_money(FinanceService::signedCents($close['difference']))) ?></dd></div>
        </dl>
    </div>

    <?php if ($close['explanation']): ?>
        <p class="text-sm"><span class="text-slate-500">Explanation:</span> <?= e($close['explanation']) ?></p>
    <?php endif; ?>

    <div class="mt-12 grid gap-8 sm:grid-cols-2">
        <div class="text-center text-sm"><?= partial('signature-image', ['userId' => (int) $close['closed_by'], 'name' => $close['closed_by_name']]) ?><p class="border-b border-slate-400 pb-1 font-semibold"><?= e($close['closed_by_name']) ?></p><p class="mt-1 text-slate-500">Cashier</p></div>
        <div class="text-center text-sm"><p class="border-b border-slate-400 pb-1">&nbsp;</p><p class="mt-1 text-slate-500">Received by</p></div>
    </div>
</section>
