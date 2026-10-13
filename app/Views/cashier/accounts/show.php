<?php
/**
 * One student account: assessment, payments, online submissions, and the
 * window payment form (docs/14).
 *
 * @var array  $assessment
 * @var array  $items
 * @var array  $payments
 * @var array  $adjustments
 * @var array  $submissions
 * @var bool   $belowPolicy
 * @var array  $adjustmentTypes
 * @var int    $required
 * @var string $policy
 * @var array  $methods
 */
use App\Services\FinanceService;

$open = FinanceService::cents($assessment['balance']) > 0;
$dueCents = FinanceService::dueCents($assessment);
$hasAdjustments = $adjustments !== [];
$paymentCount = count(array_filter($payments, static fn ($p) => $p['reversed_payment_id'] === null && $p['reversal_id'] === null));
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

    <?php if ($belowPolicy): ?>
        <div class="flex items-start gap-3 rounded-xl border border-warning-line bg-warning-bg px-4 py-3 text-sm text-warning-text" role="status">
            <?= icon('alert', 'mt-0.5 h-5 w-5 shrink-0') ?>
            <p><strong>Below payment policy.</strong> This student is enrolled, but only <?= e(money($assessment['total_paid'])) ?> of the required <?= e(money(FinanceService::money($required))) ?> is paid, usually because a payment was reversed. The enrollment stands; follow up with the student.</p>
        </div>
    <?php endif; ?>

    <div class="grid gap-4 sm:grid-cols-3">
        <?= component('stat-card', ['label' => $hasAdjustments ? 'Amount due' : 'Assessment', 'value' => money(FinanceService::money(max(0, $dueCents))), 'hint' => $hasAdjustments ? 'Assessed ' . money($assessment['total_amount']) . ', adjusted ' . signed_money(FinanceService::signedCents($assessment['total_adjustments'])) : 'Issued ' . d($assessment['assessed_at']), 'icon' => 'receipt']) ?>
        <?= component('stat-card', ['label' => 'Paid', 'value' => money($assessment['total_paid']), 'hint' => $paymentCount . ' payment' . ($paymentCount === 1 ? '' : 's'), 'icon' => 'banknotes', 'tone' => 'success']) ?>
        <?= component('stat-card', ['label' => 'Balance', 'value' => money($assessment['balance']), 'hint' => $assessment['enrollment_state'] === 'ENROLLED' ? 'Enrolled' : 'Needs ' . money(FinanceService::money(max(0, $required - FinanceService::cents($assessment['total_paid'])))) . ' more to enroll', 'icon' => 'credit-card']) ?>
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <section class="card p-5" aria-labelledby="items-heading">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <h3 id="items-heading" class="text-base">Fees</h3>
                    <?= partial('preview-link', ['path' => '/statements/' . $assessment['id'], 'kind' => 'page', 'title' => 'Statement of account · ' . $assessment['assessment_number'], 'label' => 'View statement', 'icon' => 'document', 'class' => 'inline-flex items-center gap-1.5 text-sm font-semibold text-brand-600 hover:underline']) ?>
                    <?= partial('preview-link', ['path' => '/statements/' . $assessment['id'] . '/ledger', 'kind' => 'page', 'title' => 'Student ledger · ' . $assessment['assessment_number'], 'label' => 'Ledger card', 'icon' => 'document', 'class' => 'inline-flex items-center gap-1.5 text-sm font-semibold text-brand-600 hover:underline']) ?>
                </div>
                <ul class="divide-y divide-brand-50 text-sm">
                    <?php foreach ($items as $item): ?>
                        <li class="flex justify-between gap-3 py-2"><span><?= e($item['description']) ?></span><span class="tabular-nums"><?= e(money($item['amount'])) ?></span></li>
                    <?php endforeach; ?>
                    <li class="flex justify-between gap-3 py-2 font-semibold text-brand-navy"><span>Total assessment</span><span class="tabular-nums"><?= e(money($assessment['total_amount'])) ?></span></li>
                    <?php foreach ($adjustments as $adjustment): ?>
                        <li class="flex justify-between gap-3 py-2">
                            <span><?= e($adjustmentTypes[$adjustment['type']] ?? $adjustment['type']) ?> <span class="text-brand-gray">· <?= e($adjustment['adjustment_number']) ?></span></span>
                            <span class="tabular-nums"><?= e(signed_money(FinanceService::signedCents($adjustment['amount']))) ?></span>
                        </li>
                    <?php endforeach; ?>
                    <?php if ($hasAdjustments): ?>
                        <li class="flex justify-between gap-3 py-2 font-semibold text-brand-navy"><span>Amount due</span><span class="tabular-nums"><?= e(money(FinanceService::money(max(0, $dueCents)))) ?></span></li>
                    <?php endif; ?>
                </ul>
            </section>

            <section class="card p-5" aria-labelledby="adjustments-heading">
                <h3 id="adjustments-heading" class="mb-3 text-base">Adjustments</h3>
                <?php if ($adjustments === []): ?>
                    <p class="text-sm text-brand-gray">No discounts, scholarships, or charges have been applied.</p>
                <?php else: ?>
                    <ul class="divide-y divide-brand-50 text-sm">
                        <?php foreach ($adjustments as $adjustment): ?>
                            <li class="py-2.5">
                                <div class="flex flex-wrap justify-between gap-3">
                                    <span class="font-semibold text-brand-navy"><?= e($adjustment['adjustment_number']) ?> · <?= e($adjustmentTypes[$adjustment['type']] ?? $adjustment['type']) ?></span>
                                    <span class="tabular-nums font-semibold"><?= e(signed_money(FinanceService::signedCents($adjustment['amount']))) ?></span>
                                </div>
                                <p class="mt-0.5 text-slate-600"><?= e($adjustment['reason']) ?></p>
                                <p class="text-xs text-brand-gray"><?= e(dt($adjustment['created_at'])) ?> · <?= e($adjustment['created_by_name']) ?></p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <section class="card p-5" aria-labelledby="installments-heading">
                <h3 id="installments-heading" class="mb-3 text-base">Installments</h3>
                <?= partial('installments', ['plan' => $plan]) ?>
            </section>

            <section class="card p-5" aria-labelledby="payments-heading">
                <h3 id="payments-heading" class="mb-3 text-base">Payments</h3>
                <?php if ($payments === []): ?>
                    <p class="text-sm text-brand-gray">No payments recorded.</p>
                <?php else: ?>
                    <ul class="divide-y divide-brand-50 text-sm">
                        <?php foreach ($payments as $payment): ?>
                            <?php if ($payment['reversed_payment_id'] !== null): ?>
                                <li id="payment-<?= (int) $payment['id'] ?>" class="py-2.5">
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <span><span class="font-semibold tabular-nums text-danger-text"><?= e(signed_money(FinanceService::signedCents($payment['amount']))) ?></span> · Reversal of receipt <?= e($payment['reversed_receipt_number']) ?> · <?= e(d($payment['paid_at'])) ?> · <?= e($payment['cashier_name']) ?></span>
                                        <?= component('status-badge', ['status' => 'REJECTED', 'label' => 'Reversal']) ?>
                                    </div>
                                    <p class="mt-0.5 text-slate-600"><?= e($payment['remarks']) ?></p>
                                </li>
                            <?php else: ?>
                                <li id="payment-<?= (int) $payment['id'] ?>" class="py-2.5">
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <span class="<?= $payment['reversal_id'] !== null ? 'text-brand-gray line-through' : '' ?>"><span class="font-semibold text-brand-navy"><?= e(money($payment['amount'])) ?></span> · <?= e($payment['payment_method']) ?><?= $payment['reference_number'] ? ' · Ref. ' . e($payment['reference_number']) : '' ?> · <?= e(d($payment['paid_at'])) ?> · <?= e($payment['cashier_name']) ?></span>
                                        <span class="flex items-center gap-3">
                                            <?php if ($payment['reversal_id'] !== null): ?>
                                                <?= component('status-badge', ['status' => 'CANCELLED', 'label' => 'Reversed']) ?>
                                            <?php endif; ?>
                                            <?= partial('preview-link', ['path' => '/receipts/' . $payment['id'], 'kind' => 'page', 'title' => 'Official receipt ' . $payment['receipt_number'], 'label' => 'Receipt ' . $payment['receipt_number'], 'group' => 'account-receipts']) ?>
                                        </span>
                                    </div>
                                    <?php if ($payment['reversal_id'] === null): ?>
                                        <details class="mt-2">
                                            <summary class="cursor-pointer text-xs font-semibold text-danger-text hover:underline">Reverse this payment</summary>
                                            <form method="POST" action="<?= e(url('/cashier/accounts/' . $assessment['id'] . '/payments/' . $payment['id'] . '/reverse')) ?>" class="mt-2 flex flex-wrap items-end gap-2" novalidate
                                                  data-confirm="A negative entry for <?= e(money($payment['amount'])) ?> is added and the balance goes back up. This cannot be undone." data-confirm-title="Reverse receipt <?= e($payment['receipt_number']) ?>?" data-confirm-tone="danger" data-confirm-label="Reverse payment"
                                                  data-confirm-requires="#reverse-reason-<?= (int) $payment['id'] ?>" data-confirm-requires-message="Write why the payment is reversed first. It is recorded with the reversal.">
                                                <?= csrf_field() ?>
                                                <div class="min-w-0 flex-1">
                                                    <label for="reverse-reason-<?= (int) $payment['id'] ?>" class="field-label">Reason <span class="text-danger-text" aria-hidden="true">*</span></label>
                                                    <input id="reverse-reason-<?= (int) $payment['id'] ?>" name="reason" class="field-input" required minlength="5" maxlength="500" placeholder="e.g. Recorded on the wrong account">
                                                </div>
                                                <button type="submit" class="btn-danger">Reverse</button>
                                            </form>
                                        </details>
                                    <?php else: ?>
                                        <p class="mt-0.5 text-xs text-brand-gray">Reversed <?= e(dt($payment['reversed_at'])) ?>: <?= e($payment['reversal_reason']) ?></p>
                                    <?php endif; ?>
                                </li>
                            <?php endif; ?>
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

        <div class="space-y-6 self-start">
        <section id="record" class="card p-5" aria-labelledby="record-heading">
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

        <section id="adjust" class="card p-5" aria-labelledby="adjust-heading">
            <h3 id="adjust-heading" class="flex items-center gap-2 text-base"><span class="text-brand-600"><?= icon('settings', 'h-5 w-5') ?></span> Apply an adjustment</h3>
            <p class="mt-1 text-sm text-slate-600">Takes effect immediately and is recorded in the audit log. The amount due can't go below what is already paid.</p>
            <form method="POST" action="<?= e(url('/cashier/accounts/' . $assessment['id'] . '/adjustments')) ?>" class="mt-3 space-y-4" novalidate
                  data-confirm="The amount due changes right away. Adjustments cannot be edited or deleted." data-confirm-title="Apply this adjustment?" data-confirm-tone="warning"
                  data-confirm-requires="#field-adjustment_reason" data-confirm-requires-message="Write the reason for the adjustment first, for example the scholarship or order number.">
                <?= csrf_field() ?>
                <div>
                    <label for="field-adjustment_type" class="field-label">Type <span class="text-danger-text" aria-hidden="true">*</span></label>
                    <select id="field-adjustment_type" name="adjustment_type" class="field-input">
                        <?php foreach ($adjustmentTypes as $value => $label): ?>
                            <?php $effect = match (true) { in_array($value, FinanceService::INCREASING_TYPES, true) => ' (adds to the amount due)', $value === 'CORRECTION' => ' (either way)', default => ' (lowers the amount due)' }; ?>
                            <option value="<?= e($value) ?>" <?= old('adjustment_type') === $value ? 'selected' : '' ?>><?= e($label . $effect) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (($typeError = error_for('adjustment_type')) !== null): ?><p class="field-error"><?= e($typeError) ?></p><?php endif; ?>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <?= component('field', ['name' => 'adjustment_amount', 'label' => 'Amount (₱)', 'type' => 'number', 'value' => old('adjustment_amount'), 'help' => 'Or a percentage →']) ?>
                    <?= component('field', ['name' => 'adjustment_percent', 'label' => 'Percent of tuition (%)', 'type' => 'number', 'value' => old('adjustment_percent'), 'help' => 'Discounts and scholarships only. Fill in one of the two.']) ?>
                </div>
                <fieldset>
                    <legend class="field-label">Direction (corrections only)</legend>
                    <div class="flex flex-wrap gap-4 text-sm">
                        <label class="inline-flex items-center gap-2"><input type="radio" name="adjustment_direction" value="decrease" <?= old('adjustment_direction') === 'decrease' ? 'checked' : '' ?>> Decreases what is owed</label>
                        <label class="inline-flex items-center gap-2"><input type="radio" name="adjustment_direction" value="increase" <?= old('adjustment_direction') === 'increase' ? 'checked' : '' ?>> Increases what is owed</label>
                    </div>
                    <?php if (($directionError = error_for('adjustment_direction')) !== null): ?><p class="field-error"><?= e($directionError) ?></p><?php endif; ?>
                </fieldset>
                <?= component('field', ['name' => 'adjustment_reason', 'label' => 'Reason', 'required' => true, 'value' => old('adjustment_reason'), 'help' => 'e.g. Academic scholarship, SO No. 12 s. 2026']) ?>
                <button type="submit" class="btn-secondary w-full">Apply adjustment</button>
            </form>
        </section>
        </div>
    </div>
</div>
