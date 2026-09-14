<?php
/**
 * One student account: assessment, payments, online submissions, and the
 * window payment form (docs/14).
 *
 * @var array  $assessment
 * @var array  $items
 * @var array  $payments
 * @var array  $submissions
 * @var int    $required
 * @var string $policy
 * @var array  $methods
 */
use App\Services\FinanceService;

$open = FinanceService::cents($assessment['balance']) > 0;
?>
<div class="space-y-6">
    <a href="<?= e(url('/cashier/accounts')) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; Student accounts</a>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="eyebrow"><?= e($assessment['assessment_number']) ?> · <?= e($assessment['application_number']) ?></p>
            <h2 class="mt-1 text-2xl"><?= e($assessment['last_name'] . ', ' . $assessment['first_name']) ?></h2>
            <p class="mt-1 text-sm text-slate-600"><?= e($assessment['student_number'] ?? 'No student number yet') ?> · <?= e($assessment['program_code'] . ' · ' . $assessment['year_level_name']) ?> · <?= e($assessment['term_name'] . ' ' . $assessment['academic_year_code']) ?></p>
        </div>
        <div class="flex flex-wrap gap-2">
            <?= component('status-badge', ['status' => $assessment['payment_status'], 'size' => 'md']) ?>
            <?= component('status-badge', ['status' => $assessment['enrollment_state'], 'size' => 'md']) ?>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <?= component('stat-card', ['label' => 'Assessment', 'value' => money($assessment['total_amount']), 'hint' => 'Issued ' . d($assessment['assessed_at']), 'icon' => 'receipt']) ?>
        <?= component('stat-card', ['label' => 'Paid', 'value' => money($assessment['total_paid']), 'hint' => count($payments) . ' payment' . (count($payments) === 1 ? '' : 's'), 'icon' => 'banknotes', 'tone' => 'success']) ?>
        <?= component('stat-card', ['label' => 'Balance', 'value' => money($assessment['balance']), 'hint' => $assessment['enrollment_state'] === 'ENROLLED' ? 'Enrolled' : 'Needs ' . money(FinanceService::money(max(0, $required - FinanceService::cents($assessment['total_paid'])))) . ' more to enroll', 'icon' => 'credit-card']) ?>
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <section class="card p-5" aria-labelledby="items-heading">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <h3 id="items-heading" class="text-base">Fees</h3>
                    <a href="<?= e(url('/statements/' . $assessment['id'])) ?>" class="text-sm font-semibold text-brand-600 hover:underline">Print statement</a>
                </div>
                <ul class="divide-y divide-brand-50 text-sm">
                    <?php foreach ($items as $item): ?>
                        <li class="flex justify-between gap-3 py-2"><span><?= e($item['description']) ?></span><span class="tabular-nums"><?= e(money($item['amount'])) ?></span></li>
                    <?php endforeach; ?>
                    <li class="flex justify-between gap-3 py-2 font-semibold text-brand-navy"><span>Total</span><span class="tabular-nums"><?= e(money($assessment['total_amount'])) ?></span></li>
                </ul>
            </section>

            <section class="card p-5" aria-labelledby="payments-heading">
                <h3 id="payments-heading" class="mb-3 text-base">Payments</h3>
                <?php if ($payments === []): ?>
                    <p class="text-sm text-brand-gray">No payments recorded.</p>
                <?php else: ?>
                    <ul class="divide-y divide-brand-50 text-sm">
                        <?php foreach ($payments as $payment): ?>
                            <li class="flex flex-wrap items-center justify-between gap-3 py-2.5">
                                <span><span class="font-semibold text-brand-navy"><?= e(money($payment['amount'])) ?></span> · <?= e($payment['payment_method']) ?><?= $payment['reference_number'] ? ' · Ref. ' . e($payment['reference_number']) : '' ?> · <?= e(d($payment['paid_at'])) ?> · <?= e($payment['cashier_name']) ?></span>
                                <a href="<?= e(url('/receipts/' . $payment['id'])) ?>" class="font-semibold text-brand-600 hover:underline">Receipt <?= e($payment['receipt_number']) ?></a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <?php if ($submissions !== []): ?>
                <section class="card p-5" aria-labelledby="online-heading">
                    <h3 id="online-heading" class="mb-3 text-base">Online submissions</h3>
                    <ul class="divide-y divide-brand-50 text-sm">
                        <?php foreach ($submissions as $submission): ?>
                            <li class="flex flex-wrap items-center justify-between gap-3 py-2.5">
                                <span><?= e(money($submission['amount'])) ?> · <?= e($submission['method']) ?> · Ref. <?= e($submission['reference_number']) ?></span>
                                <span class="flex items-center gap-2">
                                    <?= component('status-badge', match ($submission['status']) { 'APPROVED' => ['status' => 'PAID', 'label' => 'Verified'], 'REJECTED' => ['status' => 'REJECTED'], default => ['status' => 'UNDER_REVIEW', 'label' => 'To verify'] }) ?>
                                    <a href="<?= e(url('/cashier/payments/' . $submission['id'])) ?>" class="font-semibold text-brand-600 hover:underline">Open</a>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>
        </div>

        <section id="record" class="card self-start p-5" aria-labelledby="record-heading">
            <h3 id="record-heading" class="flex items-center gap-2 text-base"><span class="text-brand-600"><?= icon('banknotes', 'h-5 w-5') ?></span> Record a payment at the window</h3>
            <?php if (!$open): ?>
                <p class="mt-3 rounded-lg bg-success-bg px-3 py-2 text-sm text-success-text">This account is fully paid.</p>
            <?php else: ?>
                <form method="POST" action="<?= e(url('/cashier/accounts/' . $assessment['id'] . '/payments')) ?>" class="mt-3 space-y-4" novalidate
                      data-confirm="Record this payment and issue an official receipt? Payments cannot be edited or deleted afterwards.">
                    <?= csrf_field() ?>
                    <?= component('field', ['name' => 'amount', 'label' => 'Amount received (₱)', 'type' => 'number', 'required' => true, 'value' => old('amount'), 'help' => 'Up to the balance of ' . money($assessment['balance']) . '.']) ?>
                    <div>
                        <label for="field-payment_method" class="field-label">Method <span class="text-brand-alert" aria-hidden="true">*</span></label>
                        <select id="field-payment_method" name="payment_method" class="field-input">
                            <?php foreach ($methods as $method): ?>
                                <option value="<?= e($method) ?>" <?= old('payment_method', 'Cash') === $method ? 'selected' : '' ?>><?= e($method) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?= component('field', ['name' => 'reference_number', 'label' => 'Reference number', 'value' => old('reference_number'), 'help' => 'For non-cash payments.']) ?>
                    <?= component('field', ['name' => 'remarks', 'label' => 'Remarks', 'value' => old('remarks')]) ?>
                    <button type="submit" class="btn-primary w-full"><?= icon('receipt', 'h-5 w-5') ?> Record and issue receipt</button>
                </form>
            <?php endif; ?>
        </section>
    </div>
</div>
