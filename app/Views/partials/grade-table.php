<?php
/**
 * One term's classes with period grades and the computed final (docs/27).
 *
 * @var array   $periods grade periods of the term
 * @var array   $classes AcademicRecordService::classesForEnrollment()
 * @var ?string $caption
 * @var ?array  $summary AcademicRecordService::termSummary(); shows the GWA row when given
 */
use App\Services\GradeCalculator;

$number = static fn ($value): string => rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');
$show = static function (?array $entry): string {
    if ($entry === null) {
        return '—';
    }
    return (string) ($entry['remark'] ?? $entry['grade'] ?? '—');
};
$cell = 'border-b border-brand-100 px-3 py-3';
$head = $cell . ' whitespace-nowrap font-semibold';
?>
<div class="table-scroll">
    <table class="min-w-full border-separate border-spacing-0 overflow-hidden rounded-xl border border-brand-100 text-left text-sm">
        <?php if (!empty($caption)): ?><caption class="sr-only"><?= e($caption) ?></caption><?php endif; ?>
        <thead class="bg-brand-50 text-brand-navy">
            <tr>
                <th scope="col" class="<?= $head ?>">Subject Code</th>
                <th scope="col" class="<?= $head ?>">Subject Title</th>
                <th scope="col" class="<?= $head ?> text-center">Units</th>
                <?php foreach ($periods as $period): ?>
                    <th scope="col" class="<?= $head ?> text-center"><?= e($period['name']) ?></th>
                <?php endforeach; ?>
                <th scope="col" class="<?= $head ?> text-center">Grade</th>
                <th scope="col" class="<?= $head ?> text-center">Status</th>
            </tr>
        </thead>
        <tbody class="bg-white">
            <?php foreach ($classes as $class): ?>
                <?php $dropped = $class['enlistment_status'] === 'DROPPED'; ?>
                <tr class="<?= $dropped ? 'text-brand-gray' : 'text-slate-700' ?> hover:bg-brand-50/40">
                    <td class="<?= $cell ?> whitespace-nowrap font-semibold <?= $dropped ? '' : 'text-brand-navy' ?>"><?= e($class['code']) ?></td>
                    <td class="<?= $cell ?> min-w-[7rem]"><?= e($class['title']) ?></td>
                    <td class="<?= $cell ?> text-center"><?= e($number($class['units'])) ?></td>
                    <?php foreach ($periods as $period): ?>
                        <td class="<?= $cell ?> whitespace-nowrap text-center tabular-nums"><?= e($show($class['period_grades'][(int) $period['id']] ?? null)) ?></td>
                    <?php endforeach; ?>
                    <td class="<?= $cell ?> whitespace-nowrap text-center font-display font-bold tabular-nums <?= $dropped ? '' : 'text-brand-navy' ?>"><?= e($class['final']['grade'] ?? '—') ?></td>
                    <td class="<?= $cell ?> whitespace-nowrap text-center">
                        <?= component('status-badge', ['status' => $class['final']['remark'], 'label' => GradeCalculator::remarkLabel($class['final']['remark'])]) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php if (!empty($summary)): ?>
    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-slate-700">
            General Weighted Average <span class="font-semibold text-brand-navy">(GWA)</span>
            <span class="block text-xs text-brand-gray">Units-weighted; NSTP and PE are not included.</span>
        </p>
        <p class="min-w-[8rem] rounded-xl bg-brand-50 px-6 py-3 text-center font-display text-2xl font-extrabold text-brand-navy">
            <?= e($summary['gwa'] ?? '—') ?>
            <?php if ($summary['gwa'] === null): ?><span class="sr-only">Not available until a final grade is complete</span><?php endif; ?>
        </p>
    </div>
<?php endif; ?>
