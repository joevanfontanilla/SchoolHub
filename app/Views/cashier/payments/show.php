<?php
/**
 * Verify one online payment against its proof (docs/14).
 *
 * @var array  $submission
 * @var array  $assessment
 * @var array  $payments
 * @var int    $required   centavos needed before enrollment
 * @var string $policy
 */
use App\Services\FinanceService;

$pending = $submission['status'] === 'PENDING';
$isImage = str_starts_with((string) $submission['mime_type'], 'image/');
$afterCents = FinanceService::cents($assessment['total_paid']) + FinanceService::cents($submission['amount']);
$enrollsStudent = $pending && $assessment['enrollment_state'] !== 'ENROLLED' && $afterCents >= $required && $required > 0;
$remarksError = error_for('review_remarks');
?>
<div class="space-y-6">
    <a href="<?= e(url('/cashier/payments')) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; Online payments</a>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="eyebrow"><?= e($assessment['assessment_number']) ?> · <?= e($assessment['term_name']) ?> <?= e($assessment['academic_year_code']) ?></p>
            <h2 class="mt-1 text-2xl"><?= e(money($submission['amount'])) ?> from <?= e($assessment['first_name'] . ' ' . $assessment['last_name']) ?></h2>
            <p class="mt-1 text-sm text-slate-600"><?= e($submission['method']) ?> · Ref. <span class="font-semibold"><?= e($submission['reference_number']) ?></span> · paid <?= e(d($submission['paid_on'])) ?> · sent <?= e(dt($submission['submitted_at'])) ?></p>
        </div>
        <?= component('status-badge', match ($submission['status']) {
            'APPROVED' => ['status' => 'PAID', 'label' => 'Verified', 'size' => 'md'],
            'REJECTED' => ['status' => 'REJECTED', 'label' => 'Rejected', 'size' => 'md'],
            default    => ['status' => 'UNDER_REVIEW', 'label' => 'Waiting for verification', 'size' => 'md'],
        }) ?>
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <section class="card p-5 xl:col-span-2" aria-labelledby="proof-heading">
            <div class="mb-3 flex items-center justify-between gap-3">
                <h3 id="proof-heading" class="text-base">Proof of payment</h3>
                <a href="<?= e(url('/payment-proofs/' . $submission['id'])) ?>" class="text-sm font-semibold text-brand-600 hover:underline">Download original</a>
            </div>
            <?php if ($isImage): ?>
                <img src="<?= e(url('/payment-proofs/' . $submission['id'])) ?>" alt="Proof of payment uploaded by the student, reference <?= e($submission['reference_number']) ?>"
                     class="mx-auto max-h-[70vh] rounded-xl border border-brand-100 object-contain">
            <?php else: ?>
                <p class="rounded-xl bg-brand-50 px-4 py-6 text-center text-sm text-slate-700"><?= icon('document', 'mx-auto mb-2 h-8 w-8 text-brand-600') ?> The proof is a PDF (<?= e($submission['original_filename']) ?>). Download it to view.</p>
            <?php endif; ?>
        </section>

        <div class="space-y-6">
            <section class="card p-5" aria-labelledby="account-heading">
                <h3 id="account-heading" class="text-base">Account</h3>
                <dl class="mt-3 space-y-1.5 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-600">Student</dt><dd class="text-right font-semibold"><?= e($assessment['last_name'] . ', ' . $assessment['first_name']) ?></dd></div>
                    <div class="flex justify-between"><dt class="text-slate-600">Program</dt><dd class="font-semibold"><?= e($assessment['program_code'] . ' · ' . $assessment['year_level_name']) ?></dd></div>
                    <div class="flex justify-between"><dt class="text-slate-600">Assessment</dt><dd class="font-semibold"><?= e(money($assessment['total_amount'])) ?></dd></div>
                    <div class="flex justify-between"><dt class="text-slate-600">Paid so far</dt><dd class="font-semibold"><?= e(money($assessment['total_paid'])) ?></dd></div>
                    <div class="flex justify-between"><dt class="text-slate-600">Balance</dt><dd class="font-display text-lg font-bold text-brand-navy"><?= e(money($assessment['balance'])) ?></dd></div>
                </dl>
                <a href="<?= e(url('/cashier/accounts/' . $assessment['id'])) ?>" class="mt-3 inline-block text-sm font-semibold text-brand-600 hover:underline">Open the account</a>
            </section>

            <?php if ($pending): ?>
                <section class="card p-5" aria-labelledby="verify-heading">
                    <h3 id="verify-heading" class="text-base">Decision</h3>
                    <?php if ($enrollsStudent): ?>
                        <p class="mt-3 rounded-lg border border-success-line bg-success-bg px-3 py-2 text-sm text-success-text">Verifying this payment meets the payment policy: the student will be <strong>officially enrolled</strong>.</p>
                    <?php endif; ?>
                    <form method="POST" action="<?= e(url('/cashier/payments/' . $submission['id'] . '/approve')) ?>" class="mt-3 space-y-3" data-allow-resubmit>
                        <?= csrf_field() ?>
                        <label for="review-remarks" class="field-label">Note <span class="font-normal text-brand-gray">(required to reject; shown to the student)</span></label>
                        <textarea id="review-remarks" name="review_remarks" rows="3" maxlength="500" class="field-input <?= $remarksError ? 'field-input-error' : '' ?>"><?= e(old('review_remarks')) ?></textarea>
                        <?php if ($remarksError): ?><p class="field-error"><span><?= e($remarksError) ?></span></p><?php endif; ?>
                        <button type="submit" class="btn w-full bg-success-text text-white hover:brightness-110"
                                data-confirm-click="Verify <?= e(money($submission['amount'])) ?> and issue an official receipt? Payments cannot be edited or deleted afterwards.">
                            <?= icon('attendance', 'h-5 w-5') ?> Verify and issue receipt
                        </button>
                        <button type="submit" formaction="<?= e(url('/cashier/payments/' . $submission['id'] . '/reject')) ?>"
                                class="btn w-full border border-danger-line bg-white text-danger-text hover:bg-danger-bg"
                                data-confirm-click="Reject this payment? The student will see your note.">
                            Reject
                        </button>
                    </form>
                </section>
            <?php else: ?>
                <section class="card p-5 text-sm" aria-label="Decision">
                    <p><?= $submission['status'] === 'APPROVED' ? 'Verified' : 'Rejected' ?> by <?= e($submission['reviewer_name'] ?? '—') ?> on <?= e(dt($submission['reviewed_at'])) ?>.</p>
                    <?php if ($submission['review_remarks']): ?><p class="mt-1 text-slate-600">Note: <?= e($submission['review_remarks']) ?></p><?php endif; ?>
                    <?php if ($submission['payment_id']): ?>
                        <a href="<?= e(url('/receipts/' . $submission['payment_id'])) ?>" class="btn-secondary mt-3"><?= icon('receipt', 'h-5 w-5') ?> Receipt <?= e($submission['receipt_number']) ?></a>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </div>
    </div>
</div>
