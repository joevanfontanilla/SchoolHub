<?php
/**
 * Close the day (docs/14, Daily operations at the window).
 *
 * @var string $date
 * @var array  $summary DayCloseService::summary()
 * @var ?array $close   the day's close record
 * @var array  $history recent closes
 */
use App\Services\FinanceService;

$money = static fn (int $cents): string => money(FinanceService::money($cents));
$closed = $close !== null && $close['status'] === 'CLOSED';
$isToday = $date === date('Y-m-d');
$dayLabel = dt($date . ' 00:00:00', 'F j, Y');
?>
<div class="mx-auto max-w-5xl space-y-6">
    <div>
        <p class="eyebrow">Cashier</p>
        <h2 class="mt-1 text-2xl">Close the day</h2>
        <p class="mt-1 text-sm text-slate-600">Count the drawer, compare it with what the records expect, and sign off. A closed day takes no new payments until the administrator reopens it.</p>
    </div>

    <form method="GET" data-auto-submit action="<?= e(url('/cashier/day-close')) ?>" class="card flex flex-wrap items-end gap-4 p-4">
        <div>
            <label for="close-date" class="field-label">Day</label>
            <input id="close-date" type="date" name="date" value="<?= e($date) ?>" max="<?= e(date('Y-m-d')) ?>" class="field-input">
        </div>
        <button type="submit" class="btn-secondary"><?= icon('search', 'h-5 w-5') ?> Show</button>
        <p class="ml-auto text-sm font-semibold text-brand-navy"><?= e($dayLabel) ?><?= $isToday ? ' (today)' : '' ?></p>
    </form>

    <?php if ($close !== null && $close['status'] === 'REOPENED'): ?>
        <p class="rounded-xl border border-warning-line bg-warning-bg px-4 py-3 text-sm text-warning-text" role="status">
            This day was reopened by <?= e($close['reopened_by_name']) ?> on <?= e(dt($close['reopened_at'])) ?>: <?= e($close['reopen_reason']) ?>. Count again and close it.
        </p>
    <?php endif; ?>

    <div class="grid gap-4 sm:grid-cols-2 2xl:grid-cols-4">
        <?= component('stat-card', ['label' => 'Cash received', 'value' => $money($summary['receivedCash']), 'hint' => $summary['paymentCount'] . ' payment' . ($summary['paymentCount'] === 1 ? '' : 's') . ', ' . $summary['reversalCount'] . ' reversal' . ($summary['reversalCount'] === 1 ? '' : 's'), 'icon' => 'banknotes', 'tone' => 'success']) ?>
        <?= component('stat-card', ['label' => 'Cash paid out', 'value' => $money($summary['paidOutCash']), 'hint' => 'Cash vouchers', 'icon' => 'credit-card']) ?>
        <?= component('stat-card', ['label' => 'Expected in the drawer', 'value' => $money($summary['expectedCash']), 'hint' => 'Received minus paid out', 'icon' => 'receipt']) ?>
        <?= component('stat-card', ['label' => 'Not cash', 'value' => $money($summary['otherReceived']), 'hint' => 'GCash, cheques, bank. Not counted.', 'icon' => 'inbox']) ?>
    </div>

    <section class="card" aria-labelledby="methods-heading">
        <h3 id="methods-heading" class="border-b border-brand-100 px-5 py-4 text-base">Collected by method</h3>
        <?php if ($summary['methods'] === []): ?>
            <?= component('empty-state', ['title' => 'Nothing recorded on this day', 'message' => 'Payments recorded or verified on this day appear here.']) ?>
        <?php else: ?>
            <ul class="divide-y divide-brand-50 text-sm">
                <?php foreach ($summary['methods'] as $method => $cents): ?>
                    <li class="flex justify-between gap-3 px-5 py-3"><span><?= e($method) ?></span><span class="font-semibold tabular-nums text-brand-navy"><?= e($money($cents)) ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <?php if ($closed): ?>
        <section class="card border-success-line bg-success-bg p-5" aria-labelledby="closed-heading">
            <h3 id="closed-heading" class="text-base text-success-text">Closed by <?= e($close['closed_by_name']) ?> on <?= e(dt($close['closed_at'])) ?></h3>
            <dl class="mt-3 grid gap-3 text-sm text-success-text sm:grid-cols-3">
                <div><dt class="text-xs font-semibold uppercase tracking-wider">Expected</dt><dd class="mt-1 font-semibold tabular-nums"><?= e(money($close['expected_cash'])) ?></dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wider">Counted</dt><dd class="mt-1 font-semibold tabular-nums"><?= e(money($close['counted_cash'])) ?></dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wider">Difference</dt><dd class="mt-1 font-semibold tabular-nums"><?= e(signed_money(FinanceService::signedCents($close['difference']))) ?></dd></div>
            </dl>
            <?php if ($close['explanation']): ?><p class="mt-3 text-sm text-success-text">Explanation: <?= e($close['explanation']) ?></p><?php endif; ?>
            <p class="mt-4"><a href="<?= e(url('/cashier/day-close/print?date=' . $date)) ?>" target="_blank" rel="noopener" class="btn-primary"><?= icon('document', 'h-5 w-5') ?> Print turnover slip</a></p>
        </section>
    <?php else: ?>
        <section class="card p-5" aria-labelledby="count-heading">
            <h3 id="count-heading" class="text-base">Count the drawer</h3>
            <p class="mt-1 text-sm text-slate-600">Expected cash is <strong class="text-brand-navy"><?= e($money($summary['expectedCash'])) ?></strong>. Enter what you counted; the difference is worked out for you.</p>
            <form method="POST" action="<?= e(url('/cashier/day-close')) ?>" class="mt-4 grid gap-4 sm:grid-cols-2" novalidate
                  data-confirm="Closing the day locks it: no payment, reversal, or voucher can be recorded on it until the administrator reopens it." data-confirm-title="Close <?= e($dayLabel) ?>?" data-confirm-tone="primary" data-confirm-label="Close the day">
                <?= csrf_field() ?>
                <input type="hidden" name="date" value="<?= e($date) ?>">
                <?= component('field', ['name' => 'counted_cash', 'label' => 'Cash counted (₱)', 'type' => 'number', 'required' => true, 'value' => old('counted_cash'), 'help' => 'The cash in the drawer, as one total.']) ?>
                <?= component('field', ['name' => 'explanation', 'label' => 'Explanation', 'value' => old('explanation'), 'help' => 'Required when the count is short or over.']) ?>
                <div class="sm:col-span-2">
                    <button type="submit" class="btn-primary w-full sm:w-auto" data-busy-label="Closing…"><?= icon('calendar-check', 'h-5 w-5') ?> Close the day</button>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <section class="card" aria-labelledby="history-heading">
        <h3 id="history-heading" class="border-b border-brand-100 px-5 py-4 text-base">Recent closes</h3>
        <?php if ($history === []): ?>
            <?= component('empty-state', ['title' => 'No days closed yet', 'message' => 'Closed days appear here.']) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                    <thead class="bg-brand-50 text-brand-navy">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Day</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Expected</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Counted</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Difference</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-50 bg-white">
                        <?php foreach ($history as $row): ?>
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3"><a href="<?= e(url('/cashier/day-close?date=' . $row['close_date'])) ?>" class="font-semibold text-brand-navy hover:underline"><?= e(dt($row['close_date'] . ' 00:00:00', 'M j, Y')) ?></a></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums"><?= e(money($row['expected_cash'])) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums"><?= e(money($row['counted_cash'])) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums"><?= e(signed_money(FinanceService::signedCents($row['difference']))) ?></td>
                                <td class="whitespace-nowrap px-4 py-3"><?= $row['status'] === 'CLOSED' ? component('status-badge', ['status' => 'APPROVED', 'label' => 'Closed']) : component('status-badge', ['status' => 'PENDING', 'label' => 'Reopened']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
