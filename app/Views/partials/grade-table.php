<?php
/**
 * One term's classes with period grades and the computed final (docs/27).
 *
 * @var array $periods grade periods of the term
 * @var array $classes AcademicRecordService::classesForEnrollment()
 * @var ?string $caption
 */
use App\Services\GradeCalculator;

$number = static fn ($value): string => rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');
$show = static function (?array $entry): string {
    if ($entry === null) {
        return '—';
    }
    return (string) ($entry['remark'] ?? $entry['grade'] ?? '—');
};
?>
<div class="table-scroll">
    <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
        <?php if (!empty($caption)): ?><caption class="sr-only"><?= e($caption) ?></caption><?php endif; ?>
        <thead class="bg-slate-50">
            <tr>
                <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Subject</th>
                <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Units</th>
                <?php foreach ($periods as $period): ?>
                    <th scope="col" class="px-4 py-3 text-center font-display font-semibold text-brand-navy"><?= e($period['name']) ?></th>
                <?php endforeach; ?>
                <th scope="col" class="px-4 py-3 text-center font-display font-semibold text-brand-navy">Final</th>
                <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Remark</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            <?php foreach ($classes as $class): ?>
                <?php $dropped = $class['enlistment_status'] === 'DROPPED'; ?>
                <tr class="<?= $dropped ? 'text-brand-gray' : '' ?>">
                    <td class="px-4 py-3">
                        <span class="font-display font-bold <?= $dropped ? '' : 'text-brand-navy' ?>"><?= e($class['code']) ?></span>
                        <span class="block text-xs <?= $dropped ? '' : 'text-slate-600' ?>"><?= e($class['title']) ?></span>
                    </td>
                    <td class="whitespace-nowrap px-4 py-3"><?= e($number($class['units'])) ?></td>
                    <?php foreach ($periods as $period): ?>
                        <td class="whitespace-nowrap px-4 py-3 text-center tabular-nums"><?= e($show($class['period_grades'][(int) $period['id']] ?? null)) ?></td>
                    <?php endforeach; ?>
                    <td class="whitespace-nowrap px-4 py-3 text-center font-display font-bold tabular-nums <?= $dropped ? '' : 'text-brand-navy' ?>"><?= e($class['final']['grade'] ?? '—') ?></td>
                    <td class="whitespace-nowrap px-4 py-3">
                        <?= component('status-badge', ['status' => $class['final']['remark'], 'label' => GradeCalculator::remarkLabel($class['final']['remark'])]) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
