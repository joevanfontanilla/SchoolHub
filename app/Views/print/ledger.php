<?php
/**
 * The student ledger card, in the school's own layout (docs/14): the assessment block, then
 * Date / Ref / Particulars / Debit / Credit / Balance.
 *
 * @var array $assessment
 * @var array $ledger  FinanceService::ledger()
 */
use App\Services\FinanceService;

$number = static fn ($value): string => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
$name = trim($assessment['last_name'] . ', ' . $assessment['first_name'] . ($assessment['middle_name'] ? ' ' . $assessment['middle_name'] : ''));
$cents = static fn (int $c): string => $c === 0 ? '' : money(FinanceService::money($c));
$cell = 'border border-slate-300 px-2 py-1.5';
?>
<section class="mt-6 space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl font-extrabold uppercase tracking-wide text-brand-navy">Student Ledger</h1>
            <p class="mt-1 text-sm text-slate-600"><?= e($assessment['term_name']) ?>, A.Y. <?= e($assessment['academic_year_code']) ?></p>
        </div>
        <p class="text-right text-sm"><span class="text-slate-500">Assessment No.</span> <span class="font-display text-lg font-bold text-brand-navy"><?= e($assessment['assessment_number']) ?></span></p>
    </div>

    <dl class="grid gap-x-8 gap-y-1 text-sm sm:grid-cols-2">
        <div class="flex justify-between border-b border-slate-100 py-1.5"><dt class="text-slate-500">Account name</dt><dd class="font-semibold text-brand-navy"><?= e($name) ?></dd></div>
        <div class="flex justify-between border-b border-slate-100 py-1.5"><dt class="text-slate-500">ID No.</dt><dd class="font-semibold"><?= e($assessment['student_number'] ?? 'Issued when enrolled') ?></dd></div>
        <div class="flex justify-between border-b border-slate-100 py-1.5"><dt class="text-slate-500">Course</dt><dd class="font-semibold"><?= e($assessment['program_code']) ?></dd></div>
        <div class="flex justify-between border-b border-slate-100 py-1.5"><dt class="text-slate-500">Year</dt><dd class="font-semibold"><?= e($assessment['year_level_name']) ?></dd></div>
        <div class="flex justify-between border-b border-slate-100 py-1.5"><dt class="text-slate-500">School year</dt><dd class="font-semibold"><?= e($assessment['academic_year_code']) ?></dd></div>
        <div class="flex justify-between border-b border-slate-100 py-1.5"><dt class="text-slate-500">Section</dt><dd class="font-semibold"><?= e($assessment['section_code'] ?? '—') ?></dd></div>
    </dl>

    <div class="grid gap-6 sm:grid-cols-2">
        <table class="w-full self-start border border-slate-300 text-sm">
            <caption class="sr-only">Assessment</caption>
            <tbody>
                <tr><th scope="row" class="<?= $cell ?> bg-brand-50 text-left text-brand-navy">Units</th><td class="<?= $cell ?> text-right tabular-nums"><?= e($number($ledger['units'])) ?></td></tr>
                <tr><th scope="row" class="<?= $cell ?> bg-brand-50 text-left text-brand-navy">Tuition fee / unit</th><td class="<?= $cell ?> text-right tabular-nums"><?= e($ledger['rate'] !== null ? money($ledger['rate']) : '—') ?></td></tr>
                <tr><th scope="row" class="<?= $cell ?> bg-brand-50 text-left text-brand-navy">Tuition fee</th><td class="<?= $cell ?> text-right tabular-nums"><?= e(money(FinanceService::money($ledger['tuition']))) ?></td></tr>
                <tr><th scope="row" class="<?= $cell ?> bg-brand-50 text-left text-brand-navy">Scholarship / discount</th><td class="<?= $cell ?> text-right tabular-nums"><?= $ledger['discount'] > 0 ? e(($ledger['discountPercent'] !== null ? $number($ledger['discountPercent']) . '% · ' : '') . money(FinanceService::money($ledger['discount']))) : '—' ?></td></tr>
                <tr><th scope="row" class="<?= $cell ?> bg-brand-50 text-left text-brand-navy">Miscellaneous</th><td class="<?= $cell ?> text-right font-semibold tabular-nums"><?= e(money(FinanceService::money($ledger['misc']))) ?></td></tr>
            </tbody>
        </table>

        <table class="w-full border border-slate-300 text-sm">
            <caption class="border border-b-0 border-slate-300 bg-brand-50 px-2 py-1.5 text-left font-semibold text-brand-navy">Breakdown</caption>
            <tbody>
                <?php foreach ($ledger['miscItems'] as $item): ?>
                    <tr><td class="<?= $cell ?>"><?= e($item['description']) ?></td><td class="<?= $cell ?> text-right tabular-nums"><?= e(money($item['amount'])) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="overflow-x-auto print:overflow-visible">
        <table class="w-full border border-slate-300 text-sm">
            <thead class="bg-brand-50 text-left text-brand-navy">
                <tr>
                    <th scope="col" class="<?= $cell ?>">Date</th>
                    <th scope="col" class="<?= $cell ?>">Ref</th>
                    <th scope="col" class="<?= $cell ?>">Particulars</th>
                    <th scope="col" class="<?= $cell ?> text-right">Debit</th>
                    <th scope="col" class="<?= $cell ?> text-right">Credit</th>
                    <th scope="col" class="<?= $cell ?> text-right">Balance</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ledger['rows'] as $row): ?>
                    <tr>
                        <td class="<?= $cell ?> whitespace-nowrap"><?= e(d($row['date'])) ?></td>
                        <td class="<?= $cell ?> whitespace-nowrap font-semibold"><?= e($row['ref']) ?></td>
                        <td class="<?= $cell ?>"><?= e($row['particulars']) ?></td>
                        <td class="<?= $cell ?> text-right tabular-nums"><?= e($cents($row['debit'])) ?></td>
                        <td class="<?= $cell ?> text-right tabular-nums"><?= e($cents($row['credit'])) ?></td>
                        <td class="<?= $cell ?> text-right font-semibold tabular-nums"><?= e(money(FinanceService::money($row['balance']))) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="font-semibold"><th scope="row" colspan="5" class="<?= $cell ?> text-right text-brand-navy">Balance</th><td class="<?= $cell ?> text-right font-display text-lg text-brand-navy tabular-nums"><?= e(money(FinanceService::money($ledger['balance']))) ?></td></tr>
            </tfoot>
        </table>
    </div>

    <div>
        <h2 class="mb-2 font-display text-base font-bold text-brand-navy">Installments</h2>
        <?= partial('installments', ['plan' => $plan]) ?>
    </div>
</section>
