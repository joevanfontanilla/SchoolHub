<?php
/**
 * Statement of account: the assessment, its fee lines, the subjects it is based
 * on, and the payments made (docs/14, docs/15).
 *
 * @var array $assessment
 * @var array $items
 * @var array $payments
 * @var array $subjects
 */
use App\Services\FeeScheduleService;

$number = static fn ($value): string => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
$name = trim($assessment['last_name'] . ', ' . $assessment['first_name']);
$units = array_sum(array_map(static fn ($s) => (float) $s['units'], $subjects));
$cell = 'border-b border-slate-200 px-3 py-2';
?>
<section class="mt-6 space-y-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl font-extrabold uppercase tracking-wide text-brand-navy">Statement of Account</h1>
            <p class="mt-1 text-sm text-slate-600"><?= e($assessment['term_name']) ?>, A.Y. <?= e($assessment['academic_year_code']) ?></p>
        </div>
        <dl class="text-right text-sm">
            <div><dt class="inline text-slate-500">Assessment No.</dt> <dd class="inline font-display text-lg font-bold text-brand-navy"><?= e($assessment['assessment_number']) ?></dd></div>
            <div><dt class="inline text-slate-500">Issued</dt> <dd class="inline font-semibold"><?= e(dt($assessment['assessed_at'], 'F j, Y')) ?></dd></div>
        </dl>
    </div>

    <dl class="grid gap-x-8 gap-y-1 text-sm sm:grid-cols-2">
        <div class="flex justify-between border-b border-slate-100 py-1.5"><dt class="text-slate-500">Student</dt><dd class="font-semibold text-brand-navy"><?= e($name) ?></dd></div>
        <div class="flex justify-between border-b border-slate-100 py-1.5"><dt class="text-slate-500">Application</dt><dd class="font-semibold"><?= e($assessment['application_number']) ?></dd></div>
        <div class="flex justify-between border-b border-slate-100 py-1.5"><dt class="text-slate-500">Student No.</dt><dd class="font-semibold"><?= e($assessment['student_number'] ?? 'Issued when enrolled') ?></dd></div>
        <div class="flex justify-between border-b border-slate-100 py-1.5"><dt class="text-slate-500">Program / Year / Section</dt><dd class="font-semibold"><?= e($assessment['program_code'] . ' · ' . $assessment['year_level_name'] . ($assessment['section_code'] ? ' · ' . $assessment['section_code'] : '')) ?></dd></div>
    </dl>

    <div>
        <h2 class="mb-2 font-display text-base font-bold text-brand-navy">Subjects (<?= e($number($units)) ?> units)</h2>
        <table class="w-full border border-slate-300 text-sm">
            <thead class="bg-brand-50 text-left text-brand-navy"><tr><th scope="col" class="<?= $cell ?>">Code</th><th scope="col" class="<?= $cell ?>">Title</th><th scope="col" class="<?= $cell ?> text-right">Units</th><th scope="col" class="<?= $cell ?> text-right">Lab units</th></tr></thead>
            <tbody>
                <?php foreach ($subjects as $subject): ?>
                    <tr><td class="<?= $cell ?> font-semibold"><?= e($subject['code']) ?></td><td class="<?= $cell ?>"><?= e($subject['title']) ?></td><td class="<?= $cell ?> text-right"><?= e($number($subject['units'])) ?></td><td class="<?= $cell ?> text-right"><?= e($number($subject['lab_units'])) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div>
        <h2 class="mb-2 font-display text-base font-bold text-brand-navy">Assessment of fees</h2>
        <table class="w-full border border-slate-300 text-sm">
            <thead class="bg-brand-50 text-left text-brand-navy"><tr><th scope="col" class="<?= $cell ?>">Fee</th><th scope="col" class="<?= $cell ?>">Basis</th><th scope="col" class="<?= $cell ?> text-right">Rate</th><th scope="col" class="<?= $cell ?> text-right">Amount</th></tr></thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td class="<?= $cell ?>"><?= e($item['description']) ?></td>
                        <td class="<?= $cell ?> text-slate-600"><?= e($item['basis'] === 'FIXED' ? 'Fixed' : $number($item['quantity']) . ' × ' . (FeeScheduleService::BASES[$item['basis']] ?? '')) ?></td>
                        <td class="<?= $cell ?> text-right tabular-nums"><?= e(money($item['unit_amount'])) ?></td>
                        <td class="<?= $cell ?> text-right tabular-nums"><?= e(money($item['amount'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="font-semibold">
                <tr><th scope="row" colspan="3" class="px-3 py-2 text-right">Total assessment</th><td class="px-3 py-2 text-right font-display text-brand-navy tabular-nums"><?= e(money($assessment['total_amount'])) ?></td></tr>
                <tr><th scope="row" colspan="3" class="px-3 py-1 text-right font-normal text-slate-600">Less payments</th><td class="px-3 py-1 text-right font-normal tabular-nums"><?= e(money($assessment['total_paid'])) ?></td></tr>
                <tr><th scope="row" colspan="3" class="px-3 py-2 text-right">Balance</th><td class="px-3 py-2 text-right font-display text-lg text-brand-navy tabular-nums"><?= e(money($assessment['balance'])) ?></td></tr>
            </tfoot>
        </table>
    </div>

    <?php if ($payments !== []): ?>
        <div>
            <h2 class="mb-2 font-display text-base font-bold text-brand-navy">Payments</h2>
            <table class="w-full border border-slate-300 text-sm">
                <thead class="bg-brand-50 text-left text-brand-navy"><tr><th scope="col" class="<?= $cell ?>">Date</th><th scope="col" class="<?= $cell ?>">Receipt No.</th><th scope="col" class="<?= $cell ?>">Method</th><th scope="col" class="<?= $cell ?> text-right">Amount</th></tr></thead>
                <tbody>
                    <?php foreach ($payments as $payment): ?>
                        <tr><td class="<?= $cell ?>"><?= e(d($payment['paid_at'])) ?></td><td class="<?= $cell ?> font-semibold"><?= e($payment['receipt_number']) ?></td><td class="<?= $cell ?>"><?= e($payment['payment_method']) ?></td><td class="<?= $cell ?> text-right tabular-nums"><?= e(money($payment['amount'])) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
