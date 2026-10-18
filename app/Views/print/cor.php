<?php
/**
 * Certificate of Registration (COR): the official one-page proof of enrollment for a term —
 * the enlisted subjects with their schedule, the total units, and the fee assessment (docs/08,
 * docs/14). Rendered through Controller::printView on the shared print layout, which supplies
 * the school header, the print button, and the ?embed=1 preview framing.
 *
 * @var array $enrollment
 * @var array $student      the student row plus 'name'
 * @var array $classes      enlisted classes with meetings
 * @var float $units
 * @var ?array $assessment
 * @var array $items
 * @var array $adjustments
 * @var array $payments
 */
use App\Services\FinanceService;

$number = static fn ($value): string => rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');
$cell = 'border border-slate-300 px-3 py-2 align-top';
$dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
?>
<section class="mt-6 space-y-6">
    <div class="text-center">
        <h1 class="font-display text-2xl font-extrabold uppercase tracking-wide text-brand-navy">Certificate of Registration</h1>
        <p class="mt-1 text-sm text-slate-600"><?= e($enrollment['term_name']) ?>, A.Y. <?= e($enrollment['academic_year_code']) ?></p>
    </div>

    <dl class="grid gap-x-8 gap-y-1 text-sm sm:grid-cols-2">
        <div class="flex justify-between border-b border-slate-100 py-1.5"><dt class="text-slate-500">Student</dt><dd class="font-semibold text-brand-navy"><?= e($student['name']) ?></dd></div>
        <div class="flex justify-between border-b border-slate-100 py-1.5"><dt class="text-slate-500">Student No.</dt><dd class="font-semibold"><?= e($student['student_number'] ?? '—') ?></dd></div>
        <div class="flex justify-between border-b border-slate-100 py-1.5"><dt class="text-slate-500">Program</dt><dd class="font-semibold text-right"><?= e($enrollment['program_code'] . ' — ' . $enrollment['program_name']) ?></dd></div>
        <div class="flex justify-between border-b border-slate-100 py-1.5"><dt class="text-slate-500">Year &amp; Section</dt><dd class="font-semibold"><?= e($enrollment['year_level_name'] . ' · ' . $enrollment['section_code']) ?></dd></div>
    </dl>

    <div>
        <h2 class="mb-2 font-display text-sm font-bold uppercase tracking-wide text-brand-navy">Enrolled subjects</h2>
        <?php if ($classes === []): ?>
            <p class="text-sm text-slate-600">No subjects are enlisted for this term.</p>
        <?php else: ?>
            <div class="overflow-x-auto print:overflow-visible">
                <table class="w-full border-collapse border border-slate-300 text-sm">
                    <thead class="bg-brand-50 text-left text-brand-navy">
                        <tr>
                            <th scope="col" class="<?= $cell ?>">Code</th>
                            <th scope="col" class="<?= $cell ?>">Subject</th>
                            <th scope="col" class="<?= $cell ?> text-right">Units</th>
                            <th scope="col" class="<?= $cell ?>">Schedule</th>
                            <th scope="col" class="<?= $cell ?>">Room</th>
                            <th scope="col" class="<?= $cell ?>">Section</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($classes as $class): ?>
                            <tr>
                                <td class="<?= $cell ?> whitespace-nowrap font-semibold"><?= e($class['code']) ?></td>
                                <td class="<?= $cell ?>"><?= e($class['title']) ?></td>
                                <td class="<?= $cell ?> text-right tabular-nums"><?= e($number($class['units'])) ?></td>
                                <td class="<?= $cell ?> whitespace-nowrap">
                                    <?php if ($class['meetings'] === []): ?>
                                        <span class="text-slate-500">TBA</span>
                                    <?php else: foreach ($class['meetings'] as $meeting): ?>
                                        <div><?= e($dayNames[(int) $meeting['day_of_week']] ?? '') ?> <?= e(date('g:i A', strtotime($meeting['start_time']))) ?>–<?= e(date('g:i A', strtotime($meeting['end_time']))) ?></div>
                                    <?php endforeach; endif; ?>
                                </td>
                                <td class="<?= $cell ?>">
                                    <?php foreach ($class['meetings'] as $meeting): ?>
                                        <div><?= e($meeting['room_code'] ?? '—') ?></div>
                                    <?php endforeach; ?>
                                    <?= $class['meetings'] === [] ? '—' : '' ?>
                                </td>
                                <td class="<?= $cell ?> whitespace-nowrap"><?= e($class['section_code']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="bg-brand-50 font-semibold text-brand-navy">
                            <th scope="row" colspan="2" class="<?= $cell ?> text-right">Total units</th>
                            <td class="<?= $cell ?> text-right tabular-nums"><?= e($number($units)) ?></td>
                            <td class="<?= $cell ?>" colspan="3"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div>
        <h2 class="mb-2 font-display text-sm font-bold uppercase tracking-wide text-brand-navy">Assessment</h2>
        <?php if ($assessment === null): ?>
            <p class="text-sm text-slate-600">No assessment has been issued for this term yet.</p>
        <?php else: ?>
            <div class="overflow-x-auto print:overflow-visible">
                <table class="w-full border-collapse border border-slate-300 text-sm">
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <th scope="row" class="<?= $cell ?> font-normal text-left"><?= e($item['description'] ?? $item['fee_type_name'] ?? 'Fee') ?></th>
                                <td class="<?= $cell ?> text-right tabular-nums"><?= e(money($item['amount'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="font-semibold text-brand-navy">
                            <th scope="row" class="<?= $cell ?> text-right">Total assessment</th>
                            <td class="<?= $cell ?> text-right tabular-nums"><?= e(money($assessment['total_amount'])) ?></td>
                        </tr>
                        <?php foreach ($adjustments as $adjustment): ?>
                            <tr class="text-slate-600">
                                <th scope="row" class="<?= $cell ?> font-normal text-right"><?= e(FinanceService::ADJUSTMENT_TYPES[$adjustment['type']] ?? $adjustment['type']) ?> (<?= e($adjustment['adjustment_number']) ?>)</th>
                                <td class="<?= $cell ?> text-right tabular-nums"><?= e(signed_money(FinanceService::signedCents($adjustment['amount']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($adjustments !== []): ?>
                            <tr class="font-semibold text-brand-navy">
                                <th scope="row" class="<?= $cell ?> text-right">Amount due</th>
                                <td class="<?= $cell ?> text-right tabular-nums"><?= e(money(FinanceService::money(max(0, FinanceService::dueCents($assessment))))) ?></td>
                            </tr>
                        <?php endif; ?>
                        <tr class="text-slate-600">
                            <th scope="row" class="<?= $cell ?> font-normal text-right">Less payments</th>
                            <td class="<?= $cell ?> text-right tabular-nums"><?= e(money($assessment['total_paid'])) ?></td>
                        </tr>
                        <tr class="font-display text-base font-bold text-brand-navy">
                            <th scope="row" class="<?= $cell ?> text-right">Balance</th>
                            <td class="<?= $cell ?> text-right tabular-nums"><?= e(money($assessment['balance'])) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="flex items-end justify-between gap-6 pt-8">
        <p class="text-xs text-slate-500">This certifies that the student named above is officially enrolled for the term shown.</p>
        <div class="w-56"><?= partial('registrar-signature', ['label' => 'Registrar', 'enrollmentId' => (int) $enrollment['id']]) ?></div>
    </div>
    <?= partial('dry-seal-note') ?>
</section>
