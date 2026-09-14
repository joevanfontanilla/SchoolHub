<?php
/**
 * The student's attendance per class for the current term (docs/08).
 *
 * @var ?array $enrollment
 * @var array  $classes
 * @var array  $attendance enlistment id => counts and entries
 */
$labels = ['PRESENT' => 'Present', 'ABSENT' => 'Absent', 'LATE' => 'Late', 'EXCUSED' => 'Excused'];
?>
<div class="space-y-6">
    <div>
        <p class="eyebrow">My classes</p>
        <h2 class="mt-1 text-2xl">Attendance</h2>
        <?php if ($enrollment !== null): ?>
            <p class="mt-1 text-sm text-slate-600"><?= e($enrollment['term_name']) ?>, A.Y. <?= e($enrollment['academic_year_code']) ?></p>
        <?php endif; ?>
    </div>

    <?php if ($enrollment === null || $classes === []): ?>
        <section class="card"><?= component('empty-state', ['title' => 'No attendance yet', 'message' => 'Attendance appears here as your teachers record it.']) ?></section>
    <?php else: ?>
        <?php foreach ($classes as $class): ?>
            <?php $record = $attendance[(int) $class['enlistment_id']] ?? ['counts' => array_fill_keys(array_keys($labels), 0), 'entries' => []]; ?>
            <section class="card" aria-labelledby="class-<?= e($class['enlistment_id']) ?>">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                    <h3 id="class-<?= e($class['enlistment_id']) ?>" class="text-base"><?= e($class['code']) ?> <span class="font-sans text-sm font-normal text-slate-600"><?= e($class['title']) ?></span></h3>
                    <p class="flex flex-wrap gap-2">
                        <?php foreach ($labels as $status => $label): ?>
                            <?= component('status-badge', ['status' => $status, 'label' => $label . ': ' . $record['counts'][$status]]) ?>
                        <?php endforeach; ?>
                    </p>
                </div>
                <?php if ($class['enlistment_status'] === 'DROPPED'): ?>
                    <p class="px-5 py-3 text-sm text-brand-gray">Dropped.</p>
                <?php endif; ?>
                <?php if ($record['entries'] === []): ?>
                    <p class="px-5 py-4 text-sm text-brand-gray">No attendance recorded yet.</p>
                <?php else: ?>
                    <details class="px-5 py-3">
                        <summary class="cursor-pointer text-sm font-semibold text-brand-600">Show <?= count($record['entries']) ?> dated entr<?= count($record['entries']) === 1 ? 'y' : 'ies' ?></summary>
                        <ul class="mt-3 divide-y divide-slate-100">
                            <?php foreach ($record['entries'] as $entry): ?>
                                <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                    <span><?= e(date('D, M j, Y', strtotime($entry['attendance_date']))) ?></span>
                                    <span class="flex items-center gap-2">
                                        <?php if ($entry['remarks']): ?><span class="text-xs text-brand-gray"><?= e($entry['remarks']) ?></span><?php endif; ?>
                                        <?= component('status-badge', ['status' => $entry['status'], 'label' => $labels[$entry['status']] ?? $entry['status']]) ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
