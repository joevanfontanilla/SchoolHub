<?php
/**
 * Academic Records → Attendance (docs/08).
 *
 * @var array  $enrollments
 * @var ?array $enrollment
 * @var array  $classes
 * @var array  $attendance enlistment id => counts and entries
 */
$labels = ['PRESENT' => 'Present', 'LATE' => 'Late', 'EXCUSED' => 'Excused', 'ABSENT' => 'Absent'];
$barColors = ['PRESENT' => 'bg-success-text', 'LATE' => 'bg-brand-warning', 'EXCUSED' => 'bg-brand-primary', 'ABSENT' => 'bg-brand-alert'];

$totals = array_fill_keys(array_keys($labels), 0);
foreach ($attendance as $record) {
    foreach ($record['counts'] as $status => $count) {
        $totals[$status] = ($totals[$status] ?? 0) + $count;
    }
}
$recorded = array_sum($totals);
// Attended = present or late; excused days are not counted against the student.
$rate = $recorded - $totals['EXCUSED'] > 0 ? ($totals['PRESENT'] + $totals['LATE']) / ($recorded - $totals['EXCUSED']) * 100 : null;
?>
<?= partial('student/records-header', ['tab' => 'attendance', 'enrollments' => $enrollments, 'enrollment' => $enrollment, 'school' => $school]) ?>

<?php if ($enrollment === null || $classes === []): ?>
    <section class="card"><?= component('empty-state', ['title' => 'No attendance yet', 'message' => 'Attendance appears here as your teachers record it.']) ?></section>
<?php else: ?>
    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <?= component('stat-card', ['label' => 'Attendance rate', 'value' => $rate === null ? '—' : round($rate) . '%', 'hint' => 'Present or late, excused days aside', 'icon' => 'attendance', 'tone' => $rate === null ? 'neutral' : ($rate >= 90 ? 'success' : ($rate >= 75 ? 'warning' : 'danger'))]) ?>
        <?= component('stat-card', ['label' => 'Days present', 'value' => (string) $totals['PRESENT'], 'hint' => $recorded . ' class days recorded', 'icon' => 'calendar-check']) ?>
        <?= component('stat-card', ['label' => 'Late', 'value' => (string) $totals['LATE'], 'hint' => 'Across all classes', 'icon' => 'clock']) ?>
        <?= component('stat-card', ['label' => 'Absent', 'value' => (string) $totals['ABSENT'], 'hint' => $totals['EXCUSED'] . ' excused', 'icon' => 'alert', 'tone' => $totals['ABSENT'] > 0 ? 'warning' : 'neutral']) ?>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <?php foreach ($classes as $class): ?>
            <?php
            $record = $attendance[(int) $class['enlistment_id']] ?? ['counts' => array_fill_keys(array_keys($labels), 0), 'entries' => []];
            $days = array_sum($record['counts']);
            ?>
            <section class="card p-5" aria-labelledby="class-<?= e($class['enlistment_id']) ?>">
                <div class="flex items-start justify-between gap-3">
                    <h2 id="class-<?= e($class['enlistment_id']) ?>" class="font-display text-base font-bold text-brand-navy">
                        <?= e($class['code']) ?>
                        <span class="block font-sans text-sm font-normal text-slate-600"><?= e($class['title']) ?></span>
                    </h2>
                    <?php if ($class['enlistment_status'] === 'DROPPED'): ?><?= component('status-badge', ['status' => 'DROPPED']) ?><?php endif; ?>
                </div>

                <?php if ($days === 0): ?>
                    <p class="mt-4 text-sm text-brand-gray">No attendance recorded yet.</p>
                <?php else: ?>
                    <!-- Share of recorded days by status. The counts are listed in words below. -->
                    <div class="mt-4 flex h-3 overflow-hidden rounded-full bg-brand-100" aria-hidden="true">
                        <?php foreach ($labels as $status => $label): ?>
                            <?php if ($record['counts'][$status] > 0): ?>
                                <div class="<?= $barColors[$status] ?>" style="width: <?= number_format($record['counts'][$status] / $days * 100, 2, '.', '') ?>%"></div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <ul class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:grid-cols-4">
                        <?php foreach ($labels as $status => $label): ?>
                            <li class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full <?= $barColors[$status] ?>" aria-hidden="true"></span><?= e($label) ?> <span class="font-semibold text-brand-navy"><?= $record['counts'][$status] ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                    <details class="mt-3">
                        <summary class="cursor-pointer text-sm font-semibold text-brand-600">Show <?= $days ?> dated entr<?= $days === 1 ? 'y' : 'ies' ?></summary>
                        <ul class="mt-2 divide-y divide-brand-50">
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
    </div>
<?php endif; ?>
