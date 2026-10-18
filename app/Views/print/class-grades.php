<?php
/**
 * Printable grade sheet of a class (docs/15, docs/27): each period's grade, the computed
 * final grade, and the remark. A period still open may change.
 *
 * @var array $class
 * @var array $periods
 * @var array $sheet  ClassSchedule::gradeSheet()
 */
use App\Services\GradeCalculator;

$cell = 'border-b border-slate-200 px-2 py-1.5';
$open = array_values(array_filter($periods, static fn (array $p) => (int) $p['is_open'] === 1));
$remarks = array_count_values(array_map(static fn (array $row) => $row['final']['remark'], $sheet));
?>
<section class="mt-6 space-y-6">
    <?= partial('teacher/report-heading', ['heading' => 'Grade Sheet', 'class' => $class, 'user' => $user]) ?>

    <?php if ($open !== []): ?>
        <p class="rounded border border-slate-300 bg-slate-50 px-3 py-2 text-xs text-slate-700">Not final: <?= e(implode(', ', array_map(static fn ($p) => $p['name'], $open))) ?> <?= count($open) === 1 ? 'is' : 'are' ?> still open for grading.</p>
    <?php endif; ?>

    <div class="overflow-x-auto print:overflow-visible">
        <table class="w-full border border-slate-300 text-xs">
            <thead class="bg-brand-50 text-brand-navy">
                <tr>
                    <th scope="col" class="<?= $cell ?> text-left">#</th>
                    <th scope="col" class="<?= $cell ?> text-left">Student</th>
                    <?php foreach ($periods as $period): ?><th scope="col" class="<?= $cell ?> text-center"><?= e($period['name']) ?><span class="block font-normal text-slate-500"><?= e(rtrim(rtrim((string) $period['weight'], '0'), '.')) ?>%</span></th><?php endforeach; ?>
                    <th scope="col" class="<?= $cell ?> text-center">Final</th>
                    <th scope="col" class="<?= $cell ?> text-left">Remark</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sheet as $i => $row): ?>
                    <tr class="break-inside-avoid">
                        <td class="<?= $cell ?> tabular-nums text-slate-500"><?= e($i + 1) ?></td>
                        <th scope="row" class="<?= $cell ?> text-left font-normal"><?= e($row['last_name'] . ', ' . $row['first_name']) ?><span class="block text-slate-500"><?= e($row['student_number']) ?></span></th>
                        <?php foreach ($periods as $period): ?>
                            <?php $entry = $row['period_grades'][(int) $period['id']] ?? null; ?>
                            <td class="<?= $cell ?> text-center tabular-nums"><?= e(GradeCalculator::display($entry)) ?></td>
                        <?php endforeach; ?>
                        <td class="<?= $cell ?> text-center font-semibold tabular-nums"><?= e($row['final']['grade'] ?? '—') ?><?= ($row['final']['percentage'] ?? null) !== null ? e(' (' . $row['final']['percentage'] . '%)') : '' ?></td>
                        <td class="<?= $cell ?>"><?= e(GradeCalculator::remarkLabel($row['final']['remark'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($sheet === []): ?><tr><td colspan="<?= count($periods) + 4 ?>" class="<?= $cell ?> text-slate-500">No students are enlisted in this class.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($sheet !== []): ?>
        <p class="text-xs text-slate-600">
            <?= e(implode(' · ', array_map(static fn ($remark, $count) => $count . ' ' . strtolower(GradeCalculator::remarkLabel($remark)), array_keys($remarks), $remarks))) ?>
        </p>
        <div class="grid grid-cols-2 gap-10 pt-10 text-xs text-slate-600">
            <div class="text-center">
                <?= partial('signature-image', ['userId' => $user->id, 'name' => $user->fullName()]) ?>
                <p class="border-t border-slate-400 pt-1">Instructor's signature over printed name<?= $user->isTeacher() ? ' · ' . e($user->fullName()) : '' ?></p>
            </div>
            <?= partial('registrar-signature', ['label' => 'Received by the Registrar']) ?>
        </div>
    <?php endif; ?>
</section>
