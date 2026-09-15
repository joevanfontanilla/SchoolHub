<?php
/**
 * Printable attendance summary of a class (docs/15): totals and rate per student, then
 * the dated record in blocks of dates that fit the page width.
 *
 * @var array $class
 * @var array $summary  dates, students (with marks, counts, rate)
 */
$cell = 'border-b border-slate-200 px-2 py-1.5';
$letters = ['PRESENT' => 'P', 'ABSENT' => 'A', 'LATE' => 'L', 'EXCUSED' => 'E'];
$blocks = array_chunk($summary['dates'], 12);
?>
<section class="mt-6 space-y-6">
    <?= partial('teacher/report-heading', ['heading' => 'Attendance Summary', 'class' => $class, 'user' => $user]) ?>

    <div class="overflow-x-auto print:overflow-visible">
        <table class="w-full border border-slate-300 text-xs">
            <caption class="pb-2 text-left text-sm text-slate-600"><?= e(count($summary['dates'])) ?> class date<?= count($summary['dates']) === 1 ? '' : 's' ?> recorded. The rate counts late as attended and leaves excused absences out.</caption>
            <thead class="bg-brand-50 text-left text-brand-navy">
                <tr><th scope="col" class="<?= $cell ?>">Student</th><th scope="col" class="<?= $cell ?> text-right">Present</th><th scope="col" class="<?= $cell ?> text-right">Late</th><th scope="col" class="<?= $cell ?> text-right">Absent</th><th scope="col" class="<?= $cell ?> text-right">Excused</th><th scope="col" class="<?= $cell ?> text-right">Rate</th></tr>
            </thead>
            <tbody>
                <?php foreach ($summary['students'] as $row): ?>
                    <tr class="break-inside-avoid">
                        <th scope="row" class="<?= $cell ?> text-left font-normal"><?= e($row['last_name'] . ', ' . $row['first_name']) ?><span class="block text-slate-500"><?= e($row['student_number']) ?></span></th>
                        <td class="<?= $cell ?> text-right tabular-nums"><?= e($row['counts']['PRESENT']) ?></td>
                        <td class="<?= $cell ?> text-right tabular-nums"><?= e($row['counts']['LATE']) ?></td>
                        <td class="<?= $cell ?> text-right tabular-nums"><?= e($row['counts']['ABSENT']) ?></td>
                        <td class="<?= $cell ?> text-right tabular-nums"><?= e($row['counts']['EXCUSED']) ?></td>
                        <td class="<?= $cell ?> text-right font-semibold tabular-nums"><?= e($row['rate'] === null ? '—' : $row['rate'] . '%') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($summary['students'] === []): ?><tr><td colspan="6" class="<?= $cell ?> text-slate-500">No students are enlisted in this class.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php foreach ($blocks as $b => $dates): ?>
        <div class="overflow-x-auto print:overflow-visible">
            <table class="w-full border border-slate-300 text-xs">
                <caption class="pb-2 text-left text-sm font-semibold text-brand-navy">Record<?= count($blocks) > 1 ? ', part ' . ($b + 1) . ' of ' . count($blocks) : '' ?> <span class="font-normal text-slate-500">(P present, L late, A absent, E excused)</span></caption>
                <thead class="bg-brand-50 text-brand-navy">
                    <tr>
                        <th scope="col" class="<?= $cell ?> text-left">Student</th>
                        <?php foreach ($dates as $date): ?><th scope="col" class="<?= $cell ?> whitespace-nowrap text-center"><?= e(date('M j', (int) strtotime($date))) ?></th><?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($summary['students'] as $row): ?>
                        <tr class="break-inside-avoid">
                            <th scope="row" class="<?= $cell ?> whitespace-nowrap text-left font-normal"><?= e($row['last_name'] . ', ' . $row['first_name']) ?></th>
                            <?php foreach ($dates as $date): ?><td class="<?= $cell ?> text-center"><?= e($letters[$row['marks'][$date] ?? ''] ?? '·') ?></td><?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
</section>
