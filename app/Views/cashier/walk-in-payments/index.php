<?php
/**
 * Walk-in payments: money received in person at the window (docs/14).
 *
 * @var string      $q
 * @var array       $results   accounts matching the search, with a balance
 * @var ?array      $account   the chosen account
 * @var string      $suggested amount still needed to meet the payment policy, in pesos, or ''
 * @var array       $methods
 * @var ?array      $received  the payment just recorded
 * @var array       $today     counter payments recorded today
 */
use App\Services\FinanceService;

$todayTotal = array_sum(array_map(static fn ($p) => FinanceService::cents($p['amount']), $today));
?>
<div class="mx-auto max-w-5xl space-y-6">
    <div>
        <p class="eyebrow">Cashier</p>
        <h2 class="mt-1 text-2xl">Walk-in payments</h2>
        <p class="mt-1 text-sm text-slate-600">Payments received in person. Find the student, record the amount, and hand over the official receipt.</p>
    </div>

    <?php if ($received !== null): ?>
        <section class="card flex flex-wrap items-center justify-between gap-4 border-success-line bg-success-bg p-5" aria-labelledby="received-heading">
            <div>
                <h3 id="received-heading" class="text-base text-success-text">Receipt <?= e($received['receipt_number']) ?> issued</h3>
                <p class="mt-1 text-sm text-success-text">
                    <?= e(money($received['amount'])) ?> from <?= e($received['last_name'] . ', ' . $received['first_name']) ?> · <?= e($received['payment_method']) ?>
                    · balance now <?= e(money($received['balance'])) ?>
                </p>
            </div>
            <a href="<?= e(url('/receipts/' . $received['id'])) ?>" target="_blank" rel="noopener" class="btn-primary"><?= icon('receipt', 'h-5 w-5') ?> Print receipt<span class="sr-only"> <?= e($received['receipt_number']) ?></span></a>
        </section>
    <?php endif; ?>

    <section class="card p-5" aria-labelledby="find-heading">
        <h3 id="find-heading" class="text-base">Find the student</h3>
        <form method="GET" data-auto-submit action="<?= e(url('/cashier/walk-in-payments')) ?>" class="mt-3 flex gap-2">
            <label for="walkin-q" class="sr-only">Name, student number, application number, or assessment number</label>
            <input type="search" id="walkin-q" name="q" value="<?= e($q) ?>" placeholder="Name, student no., APP or ASM number" class="field-input" autofocus>
            <button type="submit" class="btn-secondary shrink-0">Search</button>
        </form>

        <?php if ($q !== ''): ?>
            <?php if ($results === []): ?>
                <p class="mt-4 text-sm text-slate-600">No account with a balance matches “<?= e($q) ?>”. Only active assessments that still owe something can take a payment.</p>
            <?php else: ?>
                <div class="table-scroll mt-4">
                    <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Student</th>
                                <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Term</th>
                                <th scope="col" class="px-4 py-3 text-right font-display font-semibold text-brand-navy">Balance</th>
                                <th scope="col" class="px-4 py-3"><span class="sr-only">Action</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($results as $row): ?>
                                <tr class="hover:bg-brand-50/50">
                                    <td class="px-4 py-3"><span class="font-semibold text-brand-navy"><?= e($row['last_name'] . ', ' . $row['first_name']) ?></span><span class="block text-xs text-brand-gray"><?= e($row['student_number'] ?? $row['application_number']) ?> · <?= e($row['program_code']) ?></span></td>
                                    <td class="whitespace-nowrap px-4 py-3"><?= e($row['term_name']) ?><span class="block text-xs text-brand-gray"><?= e($row['assessment_number']) ?></span></td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums text-brand-navy"><?= e(money($row['balance'])) ?></td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right"><a href="<?= e(url('/cashier/walk-in-payments?q=' . urlencode($q) . '&account=' . $row['id'])) ?>#record" class="font-semibold text-brand-600 hover:underline">Receive payment<span class="sr-only"> from <?= e($row['first_name'] . ' ' . $row['last_name']) ?></span></a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <?php if ($account !== null): ?>
        <?php $open = FinanceService::cents($account['balance']) > 0; ?>
        <section id="record" class="card p-5" aria-labelledby="record-heading">
            <h3 id="record-heading" class="flex items-center gap-2 text-base"><span class="text-brand-600"><?= icon('banknotes', 'h-5 w-5') ?></span> Receive a payment</h3>
            <dl class="mt-3 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div><dt class="text-xs font-semibold uppercase tracking-wider text-brand-gray">Student</dt><dd class="mt-1 font-medium text-slate-800"><?= e($account['last_name'] . ', ' . $account['first_name']) ?></dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wider text-brand-gray">Account</dt><dd class="mt-1 text-slate-800"><?= e($account['assessment_number']) ?> · <?= e($account['term_name']) ?></dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wider text-brand-gray">Paid so far</dt><dd class="mt-1 tabular-nums text-slate-800"><?= e(money($account['total_paid'])) ?></dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wider text-brand-gray">Balance</dt><dd class="mt-1 font-semibold tabular-nums text-brand-navy"><?= e(money($account['balance'])) ?></dd></div>
            </dl>

            <?php if (!$open): ?>
                <p class="mt-4 rounded-lg bg-success-bg px-3 py-2 text-sm text-success-text">This account is fully paid.</p>
            <?php else: ?>
                <?php if ($suggested !== ''): ?>
                    <p class="mt-4 rounded-lg bg-warning-bg px-3 py-2 text-sm text-warning-text">
                        Not enrolled yet. At least <strong><?= e(money($suggested)) ?></strong> more is needed to meet the payment policy, so the student can be enrolled.
                    </p>
                <?php endif; ?>
                <form method="POST" action="<?= e(url('/cashier/walk-in-payments/' . $account['id'])) ?>" class="mt-4 grid gap-4 sm:grid-cols-2" novalidate
                      data-confirm="Record this payment and issue an official receipt? Payments cannot be edited or deleted afterwards." data-confirm-title="Record this payment?" data-confirm-tone="primary" data-confirm-label="Record and issue receipt">
                    <?= csrf_field() ?>
                    <?= component('field', ['name' => 'amount', 'label' => 'Amount received (₱)', 'type' => 'number', 'required' => true, 'value' => old('amount', $suggested), 'help' => 'Up to the balance of ' . money($account['balance']) . '.']) ?>
                    <div>
                        <label for="field-payment_method" class="field-label">Method <span class="text-danger-text" aria-hidden="true">*</span><span class="sr-only">(required)</span></label>
                        <select id="field-payment_method" name="payment_method" class="field-input">
                            <?php foreach ($methods as $method): ?>
                                <option value="<?= e($method) ?>" <?= old('payment_method', 'Cash') === $method ? 'selected' : '' ?>><?= e($method) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?= component('field', ['name' => 'reference_number', 'label' => 'Reference number', 'value' => old('reference_number'), 'help' => 'For non-cash payments.']) ?>
                    <?= component('field', ['name' => 'remarks', 'label' => 'Remarks', 'value' => old('remarks')]) ?>
                    <div class="sm:col-span-2">
                        <button type="submit" class="btn-primary w-full sm:w-auto" data-busy-label="Recording…"><?= icon('receipt', 'h-5 w-5') ?> Record and issue receipt</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="card" aria-labelledby="today-heading">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-5 py-4">
            <h3 id="today-heading" class="text-base">Recorded at the window today</h3>
            <p class="text-sm text-brand-gray"><?= e(count($today)) ?> <?= count($today) === 1 ? 'payment' : 'payments' ?> · <strong class="text-brand-navy"><?= e(money(FinanceService::money($todayTotal))) ?></strong></p>
        </div>
        <?php if ($today === []): ?>
            <?= component('empty-state', ['title' => 'No walk-in payments yet today', 'message' => 'Payments you record here appear in this list.']) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Receipt</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Payor</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Method</th>
                            <th scope="col" class="px-4 py-3 text-right font-display font-semibold text-brand-navy">Amount</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Print</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($today as $row): ?>
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3 font-semibold text-brand-navy"><?= e($row['receipt_number']) ?><span class="block text-xs font-normal text-brand-gray"><?= e(dt($row['paid_at'], 'g:i A')) ?></span></td>
                                <td class="px-4 py-3"><?= e($row['last_name'] . ', ' . $row['first_name']) ?><span class="block text-xs text-brand-gray"><?= e($row['student_number'] ?? $row['assessment_number']) ?></span></td>
                                <td class="whitespace-nowrap px-4 py-3"><?= e($row['payment_method']) ?><?php if (!empty($row['reference_number'])): ?><span class="block text-xs text-brand-gray">Ref. <?= e($row['reference_number']) ?></span><?php endif; ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums"><?= e(money($row['amount'])) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right"><a href="<?= e(url('/receipts/' . $row['id'])) ?>" target="_blank" rel="noopener" class="font-semibold text-brand-600 hover:underline">Print<span class="sr-only"> receipt <?= e($row['receipt_number']) ?></span></a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
