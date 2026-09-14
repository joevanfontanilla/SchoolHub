<?php
/**
 * Official receipt (docs/15).
 *
 * @var array  $payment
 * @var string $balanceAfter
 */
$name = trim($payment['last_name'] . ', ' . $payment['first_name'] . ($payment['middle_name'] ? ' ' . mb_substr($payment['middle_name'], 0, 1) . '.' : ''));
$row = 'flex justify-between gap-4 border-b border-slate-100 py-2';
?>
<section class="mt-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl font-extrabold uppercase tracking-wide text-brand-navy">Official Receipt</h1>
            <p class="mt-1 text-sm text-slate-600">Payment for <?= e($payment['term_name']) ?>, A.Y. <?= e($payment['academic_year_code']) ?></p>
        </div>
        <dl class="text-right text-sm">
            <div><dt class="inline text-slate-500">Receipt No.</dt> <dd class="inline font-display text-lg font-bold text-brand-navy"><?= e($payment['receipt_number']) ?></dd></div>
            <div><dt class="inline text-slate-500">Transaction No.</dt> <dd class="inline font-semibold"><?= e($payment['transaction_number']) ?></dd></div>
            <div><dt class="inline text-slate-500">Date</dt> <dd class="inline font-semibold"><?= e(dt($payment['paid_at'], 'F j, Y')) ?></dd></div>
        </dl>
    </div>

    <div class="mt-6 grid gap-6 sm:grid-cols-2">
        <dl class="text-sm">
            <div class="<?= $row ?>"><dt class="text-slate-500">Received from</dt><dd class="font-semibold text-brand-navy"><?= e($name) ?></dd></div>
            <div class="<?= $row ?>"><dt class="text-slate-500">Student No.</dt><dd class="font-semibold"><?= e($payment['student_number'] ?? 'Not yet issued') ?></dd></div>
            <div class="<?= $row ?>"><dt class="text-slate-500">Program / Year</dt><dd class="font-semibold"><?= e($payment['program_code'] . ' · ' . $payment['year_level_name']) ?></dd></div>
        </dl>
        <dl class="text-sm">
            <div class="<?= $row ?>"><dt class="text-slate-500">Payment method</dt><dd class="font-semibold"><?= e($payment['payment_method']) ?></dd></div>
            <div class="<?= $row ?>"><dt class="text-slate-500">Reference No.</dt><dd class="font-semibold"><?= e($payment['reference_number'] ?? '—') ?></dd></div>
            <div class="<?= $row ?>"><dt class="text-slate-500">Assessment</dt><dd class="font-semibold"><?= e($payment['assessment_number']) ?></dd></div>
        </dl>
    </div>

    <table class="mt-8 w-full border border-slate-300 text-sm">
        <thead class="bg-brand-50 text-brand-navy">
            <tr><th scope="col" class="border-b border-slate-300 px-4 py-2 text-left">Description</th><th scope="col" class="border-b border-slate-300 px-4 py-2 text-right">Amount</th></tr>
        </thead>
        <tbody>
            <tr><td class="px-4 py-3">Tuition and school fees, <?= e($payment['term_name']) ?> <?= e($payment['academic_year_code']) ?> (<?= e($payment['assessment_number']) ?>)</td><td class="px-4 py-3 text-right tabular-nums"><?= e(money($payment['amount'])) ?></td></tr>
        </tbody>
        <tfoot class="font-semibold">
            <tr class="border-t border-slate-300"><th scope="row" class="px-4 py-2 text-right">Amount paid</th><td class="px-4 py-2 text-right font-display text-lg text-brand-navy tabular-nums"><?= e(money($payment['amount'])) ?></td></tr>
            <tr><th scope="row" class="px-4 py-1 text-right font-normal text-slate-600">Total assessment</th><td class="px-4 py-1 text-right font-normal tabular-nums"><?= e(money($payment['total_amount'])) ?></td></tr>
            <tr><th scope="row" class="px-4 py-1 pb-3 text-right font-normal text-slate-600">Balance after this payment</th><td class="px-4 py-1 pb-3 text-right font-normal tabular-nums"><?= e(money($balanceAfter)) ?></td></tr>
        </tfoot>
    </table>

    <?php if ($payment['remarks']): ?>
        <p class="mt-4 text-sm"><span class="text-slate-500">Remarks:</span> <?= e($payment['remarks']) ?></p>
    <?php endif; ?>

    <div class="mt-12 flex justify-end">
        <div class="w-64 text-center text-sm">
            <p class="border-b border-slate-400 pb-1 font-semibold"><?= e($payment['cashier_name']) ?></p>
            <p class="mt-1 text-slate-500">Cashier</p>
        </div>
    </div>
</section>
