<?php
/**
 * Payments & Accounts (docs/08, docs/14): the student's assessment, online
 * payment, and receipts.
 *
 * @var array  $assessments
 * @var ?array $assessment
 * @var array  $items
 * @var array  $payments
 * @var array  $adjustments
 * @var array  $submissions
 * @var int    $required     centavos needed before enrollment
 * @var string $policy
 * @var string $instructions
 * @var array  $methods
 */
use App\Services\FeeScheduleService;
use App\Services\FinanceService;
use App\Services\PaymentPolicy;

$number = static fn ($value): string => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
$cardTitle = 'flex items-center gap-3 font-display text-lg font-bold text-brand-navy';
$failed = errors() !== [];
?>
<?= partial('page-hero', [
    'heading'     => 'Payments & Accounts',
    'subtitle'    => 'Review your fee assessment, pay online, and keep your official receipts.',
    'tagline'     => $school['school_tagline'] ?? null,
    'breadcrumbs' => [['Home', '/student/dashboard'], ['Payments & Accounts', null]],
]) ?>

<?php if ($assessment === null): ?>
    <section class="card"><?= component('empty-state', [
        'title'   => 'No assessment yet',
        'message' => 'Your fee assessment appears here once the registrar approves your enrollment application.',
    ]) ?></section>
<?php else: ?>
    <?php
    $paidCents = FinanceService::cents($assessment['total_paid']);
    $totalCents = max(0, FinanceService::dueCents($assessment));
    $verifiedCount = count(array_filter($payments, static fn ($p) => $p['reversed_payment_id'] === null && $p['reversal_id'] === null));
    $pendingCents = array_sum(array_map(static fn ($s) => $s['status'] === 'PENDING' ? FinanceService::cents($s['amount']) : 0, $submissions));
    $enrolled = $assessment['enrollment_state'] === 'ENROLLED';
    $toEnrollCents = max(0, $required - $paidCents);
    $paidPercent = $totalCents > 0 ? $paidCents / $totalCents * 100 : 100;
    $suggested = $failed ? old('amount') : FinanceService::money(max(0, ($toEnrollCents > 0 ? $toEnrollCents : FinanceService::cents($assessment['balance'])) - $pendingCents));
    ?>

    <?php if (count($assessments) > 1): ?>
        <form method="GET" action="<?= e(url('/student/account')) ?>" class="card mb-6 flex flex-wrap items-end gap-4 p-4" data-auto-submit>
            <div class="min-w-[16rem]">
                <label for="account-assessment" class="field-label">Term</label>
                <select id="account-assessment" name="assessment" class="field-input">
                    <?php foreach ($assessments as $option): ?>
                        <option value="<?= e($option['id']) ?>" <?= (int) $option['id'] === (int) $assessment['id'] ? 'selected' : '' ?>><?= e($option['term_name'] . ', A.Y. ' . $option['academic_year_code'] . ' · ' . $option['assessment_number']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <noscript><button type="submit" class="btn-secondary">Show</button></noscript>
        </form>
    <?php endif; ?>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <?= component('stat-card', ['label' => $adjustments === [] ? 'Total assessment' : 'Amount due', 'value' => money(FinanceService::money($totalCents)), 'hint' => $adjustments === [] ? $assessment['term_name'] . ' · ' . $assessment['assessment_number'] : 'Assessed ' . money($assessment['total_amount']) . ', adjusted ' . signed_money(FinanceService::signedCents($assessment['total_adjustments'])), 'icon' => 'receipt']) ?>
        <?= component('stat-card', ['label' => 'Paid', 'value' => money($assessment['total_paid']), 'hint' => $verifiedCount . ' verified payment' . ($verifiedCount === 1 ? '' : 's') . ($pendingCents > 0 ? ' · ' . money(FinanceService::money($pendingCents)) . ' being verified' : ''), 'icon' => 'banknotes', 'tone' => 'success']) ?>
        <?= component('stat-card', ['label' => 'Balance', 'value' => money($assessment['balance']), 'hint' => FinanceService::cents($assessment['balance']) === 0 ? 'Fully paid' : 'Remaining this term', 'icon' => 'credit-card', 'tone' => FinanceService::cents($assessment['balance']) === 0 ? 'success' : 'neutral']) ?>
        <?= component('stat-card', [
            'label' => 'Enrollment',
            'value' => $enrolled ? 'Enrolled' : 'Not yet enrolled',
            'hint'  => $enrolled ? 'Section ' . ($assessment['section_code'] ?? '') : ($toEnrollCents > 0 ? 'Pay ' . money(FinanceService::money($toEnrollCents)) . ' to enroll' : 'Being processed'),
            'icon'  => $enrolled ? 'calendar-check' : 'clock',
            'tone'  => $enrolled ? 'success' : 'warning',
        ]) ?>
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <?php if ($enrolled): ?>
                <section class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-success-line bg-success-bg px-5 py-4 text-success-text" aria-label="Enrollment">
                    <p class="flex items-center gap-3 text-sm"><?= icon('attendance', 'h-6 w-6') ?> <span><strong>You are officially enrolled</strong> for <?= e($assessment['term_name'] . ', A.Y. ' . $assessment['academic_year_code']) ?>. Your subjects and schedule are ready.</span></p>
                    <div class="flex flex-wrap gap-2">
                        <a href="<?= e(url('/student/schedule?term_id=' . $assessment['term_id'])) ?>" class="btn-secondary"><?= icon('calendar', 'h-5 w-5') ?> Weekly schedule</a>
                        <?= partial('preview-link', ['path' => '/student/cor?term_id=' . $assessment['term_id'], 'kind' => 'page', 'title' => 'Certificate of Registration', 'label' => 'Certificate of Registration', 'icon' => 'document', 'class' => 'btn-primary']) ?>
                        <?= partial('preview-link', ['path' => '/student/schedule/print?term_id=' . $assessment['term_id'], 'kind' => 'page', 'title' => 'Class schedule', 'label' => 'Print schedule', 'icon' => 'document', 'class' => 'btn-secondary']) ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="card p-5 sm:p-6" aria-labelledby="statement-heading">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <h2 id="statement-heading" class="<?= $cardTitle ?>"><span class="text-brand-600"><?= icon('receipt', 'h-7 w-7') ?></span> Statement of Account</h2>
                    <?= partial('preview-link', ['path' => '/statements/' . $assessment['id'], 'kind' => 'page', 'title' => 'Statement of account · ' . $assessment['assessment_number'], 'label' => 'View statement', 'icon' => 'document', 'class' => 'btn-secondary']) ?>
                </div>
                <div class="table-scroll">
                    <table class="min-w-full border-separate border-spacing-0 overflow-hidden rounded-xl border border-brand-100 text-left text-sm">
                        <caption class="sr-only">Assessment of fees</caption>
                        <thead class="bg-brand-50 text-brand-navy">
                            <tr>
                                <th scope="col" class="border-b border-brand-100 px-4 py-3 font-semibold">Fee</th>
                                <th scope="col" class="border-b border-brand-100 px-4 py-3 font-semibold">Basis</th>
                                <th scope="col" class="whitespace-nowrap border-b border-brand-100 px-4 py-3 text-right font-semibold">Rate</th>
                                <th scope="col" class="border-b border-brand-100 px-4 py-3 text-right font-semibold">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white text-slate-700">
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td class="border-b border-brand-100 px-4 py-3 font-medium text-brand-navy"><?= e($item['description']) ?></td>
                                    <td class="border-b border-brand-100 px-4 py-3 text-slate-600"><?= e($item['basis'] === 'FIXED' ? 'Fixed' : $number($item['quantity']) . ' × ' . (FeeScheduleService::BASES[$item['basis']] ?? '')) ?></td>
                                    <td class="whitespace-nowrap border-b border-brand-100 px-4 py-3 text-right tabular-nums"><?= e(money($item['unit_amount'])) ?></td>
                                    <td class="whitespace-nowrap border-b border-brand-100 px-4 py-3 text-right font-semibold tabular-nums"><?= e(money($item['amount'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="bg-brand-50/60">
                            <tr><th scope="row" colspan="3" class="px-4 py-3 text-right font-semibold text-brand-navy"><?= $adjustments === [] ? 'Total' : 'Total assessment' ?></th><td class="whitespace-nowrap px-4 py-3 text-right font-display text-lg font-bold text-brand-navy tabular-nums"><?= e(money($assessment['total_amount'])) ?></td></tr>
                            <?php foreach ($adjustments as $adjustment): ?>
                                <tr><th scope="row" colspan="3" class="px-4 py-2 text-right font-normal text-slate-700"><?= e(FinanceService::ADJUSTMENT_TYPES[$adjustment['type']] ?? $adjustment['type']) ?> <span class="text-brand-gray">· <?= e($adjustment['reason']) ?></span></th><td class="whitespace-nowrap px-4 py-2 text-right tabular-nums"><?= e(signed_money(FinanceService::signedCents($adjustment['amount']))) ?></td></tr>
                            <?php endforeach; ?>
                            <?php if ($adjustments !== []): ?>
                                <tr><th scope="row" colspan="3" class="px-4 py-3 text-right font-semibold text-brand-navy">Amount due</th><td class="whitespace-nowrap px-4 py-3 text-right font-display text-lg font-bold text-brand-navy tabular-nums"><?= e(money(FinanceService::money($totalCents))) ?></td></tr>
                            <?php endif; ?>
                        </tfoot>
                    </table>
                </div>
                <div class="mt-5">
                    <?= component('progress-bar', ['percent' => $paidPercent, 'label' => 'Paid of amount due', 'showLabel' => true, 'tone' => $enrolled ? 'success' : 'brand']) ?>
                    <?php if ($policy !== PaymentPolicy::NONE && !$enrolled): ?>
                        <p class="mt-2 text-xs text-slate-600">
                            To be enrolled: <?= $policy === PaymentPolicy::FULL ? 'pay the full assessment' : 'pay at least ' . e(money(FinanceService::money($required))) . ' (' . PaymentPolicy::downpaymentPercent() . '% down payment)' ?>. The rest of the balance can be paid within the term.
                        </p>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card p-5 sm:p-6" aria-labelledby="history-heading">
                <h2 id="history-heading" class="<?= $cardTitle ?> mb-4"><span class="text-brand-600"><?= icon('history', 'h-7 w-7') ?></span> Payments and Receipts</h2>
                <?php if ($submissions === [] && $payments === []): ?>
                    <p class="text-sm text-brand-gray">No payments yet.</p>
                <?php else: ?>
                    <ul class="divide-y divide-brand-50">
                        <?php foreach ($payments as $payment): ?>
                            <?php if ($payment['payment_submission_id'] !== null) { continue; } ?>
                            <?php if ($payment['reversed_payment_id'] !== null): ?>
                                <li class="py-3 text-sm">
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <span><span class="font-semibold tabular-nums text-danger-text"><?= e(signed_money(FinanceService::signedCents($payment['amount']))) ?></span> · Receipt <?= e($payment['reversed_receipt_number']) ?> reversed · <?= e(d($payment['paid_at'])) ?></span>
                                        <?= component('status-badge', ['status' => 'REJECTED', 'label' => 'Reversal']) ?>
                                    </div>
                                    <p class="mt-1 text-xs text-slate-600">Reason: <?= e($payment['remarks']) ?></p>
                                </li>
                                <?php continue; ?>
                            <?php endif; ?>
                            <li class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                                <span><span class="font-semibold text-brand-navy"><?= e(money($payment['amount'])) ?></span> · <?= e($payment['payment_method']) ?> at the cashier · <?= e(d($payment['paid_at'])) ?></span>
                                <span class="flex items-center gap-2"><?= component('status-badge', $payment['reversal_id'] !== null ? ['status' => 'CANCELLED', 'label' => 'Reversed'] : ['status' => 'PAID', 'label' => 'Received']) ?> <?= partial('preview-link', ['path' => '/receipts/' . $payment['id'], 'kind' => 'page', 'title' => 'Official receipt ' . $payment['receipt_number'], 'label' => 'Receipt ' . $payment['receipt_number'], 'group' => 'account-receipts']) ?></span>
                            </li>
                        <?php endforeach; ?>
                        <?php foreach ($submissions as $submission): ?>
                            <li class="py-3 text-sm">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <span><span class="font-semibold text-brand-navy"><?= e(money($submission['amount'])) ?></span> · <?= e($submission['method']) ?> · Ref. <?= e($submission['reference_number']) ?> · paid <?= e(d($submission['paid_on'])) ?></span>
                                    <span class="flex items-center gap-2">
                                        <?= component('status-badge', match ($submission['status']) {
                                            'APPROVED' => $submission['reversal_id'] !== null ? ['status' => 'CANCELLED', 'label' => 'Reversed'] : ['status' => 'PAID', 'label' => 'Verified'],
                                            'REJECTED' => ['status' => 'REJECTED', 'label' => 'Not verified'],
                                            default    => ['status' => 'UNDER_REVIEW', 'label' => 'Being verified'],
                                        }) ?>
                                        <?php if ($submission['payment_id']): ?>
                                            <?= partial('preview-link', ['path' => '/receipts/' . $submission['payment_id'], 'kind' => 'page', 'title' => 'Official receipt ' . $submission['receipt_number'], 'label' => 'Receipt ' . $submission['receipt_number'], 'group' => 'account-receipts']) ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php if ($submission['status'] === 'REJECTED' && $submission['review_remarks']): ?>
                                    <p class="mt-1 text-xs text-danger-text">Cashier's note: <?= e($submission['review_remarks']) ?></p>
                                <?php endif; ?>
                                <p class="mt-1 flex flex-wrap gap-3 text-xs">
                                    <?= partial('preview-link', ['path' => '/payment-proofs/' . $submission['id'], 'mime' => $submission['mime_type'], 'title' => 'Your proof of payment · Ref. ' . $submission['reference_number'], 'label' => 'View the proof you sent', 'class' => 'font-semibold text-brand-600 hover:underline']) ?>
                                    <a href="<?= e(url('/payment-proofs/' . $submission['id'])) ?>" class="text-brand-600 hover:underline">Download</a>
                                </p>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </div>

        <div class="space-y-6">
            <section id="pay" class="card p-5 sm:p-6" aria-labelledby="pay-heading">
                <h2 id="pay-heading" class="<?= $cardTitle ?> mb-3"><span class="text-brand-600"><?= icon('send', 'h-7 w-7') ?></span> Pay Online</h2>
                <?php if (FinanceService::cents($assessment['balance']) - $pendingCents <= 0): ?>
                    <p class="rounded-xl bg-success-bg px-4 py-3 text-sm text-success-text">
                        <?= FinanceService::cents($assessment['balance']) === 0 ? 'Your assessment is fully paid. Thank you!' : 'Your remaining balance is covered by payments being verified.' ?>
                    </p>
                <?php else: ?>
                    <?php if (trim($instructions) !== ''): ?>
                        <div class="mb-4 rounded-xl border border-brand-100 bg-brand-50/60 px-4 py-3 text-sm text-slate-700">
                            <p class="mb-1 font-semibold text-brand-navy">1. Send your payment to:</p>
                            <p class="whitespace-pre-line"><?= e($instructions) ?></p>
                        </div>
                    <?php endif; ?>
                    <p class="mb-3 text-sm font-semibold text-brand-navy">2. Tell us about it:</p>
                    <form method="POST" action="<?= e(url('/student/account/' . $assessment['id'] . '/payments')) ?>" enctype="multipart/form-data" class="space-y-4" novalidate
                          data-confirm="Send this payment to the cashier for verification?">
                        <?= csrf_field() ?>
                        <?= component('field', ['name' => 'amount', 'label' => 'Amount paid (₱)', 'type' => 'number', 'required' => true, 'value' => $suggested, 'help' => $toEnrollCents > 0 && !$enrolled ? 'At least ' . money(FinanceService::money($toEnrollCents)) . ' to be enrolled.' : 'Up to your balance of ' . money($assessment['balance']) . '.']) ?>
                        <div>
                            <label for="field-method" class="field-label">Paid through <span class="text-brand-alert" aria-hidden="true">*</span></label>
                            <select id="field-method" name="method" class="field-input <?= error_for('method') ? 'field-input-error' : '' ?>" required>
                                <?php foreach ($methods as $method): ?>
                                    <option value="<?= e($method) ?>" <?= old('method') === $method ? 'selected' : '' ?>><?= e($method) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($error = error_for('method')): ?><p class="field-error"><span><?= e($error) ?></span></p><?php endif; ?>
                        </div>
                        <?= component('field', ['name' => 'reference_number', 'label' => 'Reference number', 'required' => true, 'value' => old('reference_number'), 'help' => 'From the GCash or Maya confirmation, or the deposit slip.']) ?>
                        <?= component('field', ['name' => 'paid_on', 'label' => 'Date paid', 'type' => 'date', 'required' => true, 'value' => old('paid_on', date('Y-m-d'))]) ?>
                        <div data-image-field>
                            <label for="field-proof" class="field-label">Proof of payment <span class="text-brand-alert" aria-hidden="true">*</span></label>
                            <input id="field-proof" type="file" name="proof" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required
                                   class="block w-full text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:font-semibold file:text-brand-700 hover:file:bg-brand-100"
                                   data-max-bytes="<?= 5 * 1024 * 1024 ?>">
                            <p class="mt-1 text-xs text-brand-gray">A screenshot or photo (JPG, PNG) or PDF, up to 5 MB. An image preview shows before you send.</p>
                            <img data-image-preview-target src="" alt="Selected proof preview" class="mt-3 max-h-56 rounded-lg object-contain ring-1 ring-brand-100" hidden>
                        </div>
                        <button type="submit" class="btn-primary w-full"><?= icon('send', 'h-5 w-5') ?> Send for verification</button>
                    </form>
                <?php endif; ?>
            </section>

            <section class="card p-5" aria-labelledby="how-heading">
                <h2 id="how-heading" class="<?= $cardTitle ?> mb-3"><span class="text-brand-600"><?= icon('info', 'h-7 w-7') ?></span> How it works</h2>
                <ol class="list-decimal space-y-1.5 pl-5 text-sm text-slate-700">
                    <li>Pay through GCash, Maya, or a bank deposit.</li>
                    <li>Send the amount, reference number, and proof here.</li>
                    <li>The cashier verifies it and issues your official receipt.</li>
                    <li>Once your down payment is verified, you are officially enrolled and your schedule is ready to print.</li>
                </ol>
            </section>
        </div>
    </div>
<?php endif; ?>
