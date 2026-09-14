<?php
/**
 * Cashier dashboard (docs/14).
 *
 * @var array $cards
 * @var array $pendingQueue
 * @var array $recent
 * @var array $week       net collections per day, last 7 days
 * @var int   $weekTotal  centavos
 * @var array $announcements
 */
?>
<div class="space-y-6">
    <div>
        <h2 class="text-lg font-semibold text-brand-navy">Welcome, <?= e($user->first_name) ?></h2>
        <p class="mt-1 text-sm text-slate-600">Payments to verify, student accounts, and today's collections.</p>
    </div>

    <?= partial('dashboard-cards', ['cards' => $cards]) ?>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="card p-5" aria-labelledby="week-heading">
                <div class="mb-2 flex flex-wrap items-center justify-between gap-3">
                    <h2 id="week-heading" class="flex items-center gap-2 text-base font-semibold text-brand-navy"><span class="text-brand-600"><?= icon('chart-bar', 'h-5 w-5') ?></span> Collections, last 7 days</h2>
                    <span class="flex items-center gap-3 text-sm"><span class="font-semibold tabular-nums text-brand-navy"><?= e($weekTotal < 0 ? signed_money($weekTotal) : money(\App\Services\FinanceService::money($weekTotal))) ?> net</span> <a href="<?= e(url('/cashier/reports/collections')) ?>" class="font-semibold text-brand-600 hover:underline">Reports</a></span>
                </div>
                <?= component('column-chart', ['items' => $week, 'caption' => 'Net collections for each of the last 7 days']) ?>
            </section>

            <section class="card" aria-labelledby="queue-heading">
                <div class="flex items-center justify-between border-b border-brand-100 px-5 py-4">
                    <h2 id="queue-heading" class="flex items-center gap-2 text-base font-semibold text-brand-navy"><span class="text-brand-600"><?= icon('inbox', 'h-5 w-5') ?></span> Payments to verify</h2>
                    <a href="<?= e(url('/cashier/payments')) ?>" class="text-sm font-semibold text-brand-600 hover:underline">All</a>
                </div>
                <?php if ($pendingQueue === []): ?>
                    <?= component('empty-state', ['title' => 'The queue is clear', 'message' => 'Online payments students send appear here.']) ?>
                <?php else: ?>
                    <ul class="divide-y divide-brand-50">
                        <?php foreach ($pendingQueue as $row): ?>
                            <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm">
                                <span><span class="font-semibold text-brand-navy"><?= e(money($row['amount'])) ?></span> · <?= e($row['last_name'] . ', ' . $row['first_name']) ?> · <?= e($row['method']) ?> · <?= e(dt($row['submitted_at'])) ?></span>
                                <a href="<?= e(url('/cashier/payments/' . $row['id'])) ?>" class="btn-primary">Verify</a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <section class="card" aria-labelledby="recent-heading">
                <div class="border-b border-brand-100 px-5 py-4">
                    <h2 id="recent-heading" class="flex items-center gap-2 text-base font-semibold text-brand-navy"><span class="text-brand-600"><?= icon('receipt', 'h-5 w-5') ?></span> Recent payments</h2>
                </div>
                <?php if ($recent === []): ?>
                    <?= component('empty-state', ['title' => 'No payments recorded', 'message' => 'Payments you verify or record appear here with their receipts.']) ?>
                <?php else: ?>
                    <ul class="divide-y divide-brand-50">
                        <?php foreach ($recent as $payment): ?>
                            <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm">
                                <span><span class="font-semibold text-brand-navy"><?= e($payment['reversed_payment_id'] !== null ? signed_money(\App\Services\FinanceService::signedCents($payment['amount'])) : money($payment['amount'])) ?></span> · <?= e($payment['last_name'] . ', ' . $payment['first_name']) ?> · <?= e($payment['payment_method']) ?> · <?= e(dt($payment['created_at'])) ?></span>
                                <?php if ($payment['reversed_payment_id'] !== null): ?>
                                    <a href="<?= e(url('/cashier/accounts/' . $payment['fee_assessment_id'])) ?>" class="font-semibold text-danger-text hover:underline">Reversal</a>
                                <?php else: ?>
                                    <a href="<?= e(url('/receipts/' . $payment['id'])) ?>" class="font-semibold text-brand-600 hover:underline"><?= e($payment['receipt_number']) ?></a>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </div>

        <?= partial('announcement-list', ['announcements' => $announcements]) ?>
    </div>
</div>
