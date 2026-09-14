<?php
/**
 * Academic Records → Grades (docs/08, docs/21, docs/27).
 *
 * @var array  $enrollments
 * @var ?array $enrollment
 * @var array  $classes
 * @var array  $periods
 * @var array  $summary       termSummary of the shown term
 * @var ?array $curriculum
 * @var ?array $progress      unitsEarned
 * @var array  $termRows      every term with its summary
 * @var ?string $cumulativeGwa
 */
use App\Services\ClassScheduleService;
use App\Services\GradeCalculator;

$number = static fn ($value): string => rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');
$ordinal = static fn (int $n): string => $n . (['th', 'st', 'nd', 'rd'][($n % 100 >= 11 && $n % 100 <= 13) ? 0 : min($n % 10, 4) % 4] ?? 'th');
$termQuery = $enrollment !== null ? '?term_id=' . $enrollment['term_id'] : '';

// One row per weekly meeting, in day and time order, for the schedule card.
$meetingRows = [];
foreach ($classes as $class) {
    if ($class['enlistment_status'] !== 'ENLISTED') {
        continue;
    }
    foreach ($class['meetings'] as $meeting) {
        $meetingRows[] = ['class' => $class, 'meeting' => $meeting];
    }
}
usort($meetingRows, static fn ($a, $b) => [$a['meeting']['day_of_week'], $a['meeting']['start_time']] <=> [$b['meeting']['day_of_week'], $b['meeting']['start_time']]);
$cardTitle = 'flex items-center gap-3 font-display text-lg font-bold text-brand-navy';
?>
<?= partial('student/records-header', ['tab' => 'grades', 'enrollments' => $enrollments, 'enrollment' => $enrollment, 'school' => $school]) ?>

<?php if ($enrollment === null): ?>
    <section class="card"><?= component('empty-state', ['title' => 'No academic records yet', 'message' => 'Your grades, schedule, and history appear here once the registrar confirms your enrollment.']) ?></section>
<?php else: ?>
<div class="grid gap-6 xl:grid-cols-3">
    <div class="space-y-6 xl:col-span-2">
        <section class="card p-5 sm:p-6" aria-labelledby="grades-heading">
            <div class="mb-4 flex flex-wrap items-center gap-3">
                <h2 id="grades-heading" class="<?= $cardTitle ?>"><span class="text-brand-600"><?= icon('chart-bar', 'h-7 w-7') ?></span> Current Grades</h2>
                <span class="rounded-full bg-success-bg px-3 py-1 text-xs font-semibold text-success-text"><?= e($enrollment['term_name']) ?>, A.Y. <?= e($enrollment['academic_year_code']) ?></span>
            </div>
            <?php if ($classes === []): ?>
                <?= component('empty-state', ['title' => 'No classes yet', 'message' => 'The registrar is still setting up your section\'s classes.']) ?>
            <?php else: ?>
                <?= partial('grade-table', ['periods' => $periods, 'classes' => $classes, 'summary' => $summary, 'caption' => 'Grades for ' . $enrollment['term_name']]) ?>
            <?php endif; ?>
        </section>

        <section class="card p-5 sm:p-6" aria-labelledby="schedule-heading">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h2 id="schedule-heading" class="<?= $cardTitle ?>"><span class="text-brand-600"><?= icon('calendar', 'h-7 w-7') ?></span> Class Schedule</h2>
                <a href="<?= e(url('/student/schedule' . $termQuery)) ?>" class="text-sm font-semibold text-brand-600 hover:underline">View full schedule</a>
            </div>
            <?php if ($meetingRows === []): ?>
                <p class="text-sm text-brand-gray">No meeting times yet.</p>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="min-w-full border-separate border-spacing-0 overflow-hidden rounded-xl border border-brand-100 text-left text-sm">
                        <caption class="sr-only">Weekly class meetings</caption>
                        <thead class="bg-brand-50 text-brand-navy">
                            <tr>
                                <?php foreach (['Day', 'Time', 'Subject Code', 'Subject Title', 'Room', 'Instructor'] as $heading): ?>
                                    <th scope="col" class="whitespace-nowrap border-b border-brand-100 px-4 py-3 font-semibold"><?= $heading ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody class="bg-white text-slate-700">
                            <?php foreach ($meetingRows as $row): ?>
                                <tr class="hover:bg-brand-50/40">
                                    <td class="whitespace-nowrap border-b border-brand-100 px-4 py-3 font-semibold text-brand-navy"><?= e(substr(ClassScheduleService::DAYS[(int) $row['meeting']['day_of_week']], 0, 3)) ?></td>
                                    <td class="whitespace-nowrap border-b border-brand-100 px-4 py-3"><?= e(ClassScheduleService::timeRange($row['meeting']['start_time'], $row['meeting']['end_time'])) ?></td>
                                    <td class="whitespace-nowrap border-b border-brand-100 px-4 py-3"><?= e($row['class']['code']) ?></td>
                                    <td class="border-b border-brand-100 px-4 py-3"><?= e($row['class']['title']) ?></td>
                                    <td class="whitespace-nowrap border-b border-brand-100 px-4 py-3"><?= e($row['meeting']['room_code'] ?? 'TBA') ?></td>
                                    <td class="whitespace-nowrap border-b border-brand-100 px-4 py-3"><?= e($row['class']['teacher_name'] ?? 'TBA') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div class="space-y-6">
        <section class="card p-5 sm:p-6" aria-labelledby="progress-heading">
            <h2 id="progress-heading" class="<?= $cardTitle ?> mb-4"><span class="text-brand-600"><?= icon('document', 'h-7 w-7') ?></span> Academic Progress</h2>
            <?php if ($progress === null): ?>
                <p class="text-sm text-brand-gray">Your progress appears once you are placed under a curriculum.</p>
            <?php else: ?>
                <div class="flex flex-wrap items-center gap-5">
                    <?= component('donut', [
                        'percent'  => $progress['percent'],
                        'title'    => $ordinal((int) $enrollment['year_sequence']),
                        'subtitle' => 'Year',
                        'label'    => 'Curriculum units earned',
                        'size'     => 150,
                    ]) ?>
                    <dl class="min-w-[11rem] flex-1 space-y-1.5 text-sm">
                        <div><dt class="inline text-slate-600">Program:</dt> <dd class="inline font-semibold text-brand-navy"><?= e($enrollment['program_code']) ?></dd></div>
                        <div><dt class="inline text-slate-600">Curriculum:</dt> <dd class="inline font-semibold text-brand-navy"><?= e($curriculum['code']) ?></dd></div>
                        <div><dt class="inline text-slate-600">Academic Year:</dt> <dd class="inline font-semibold text-brand-navy"><?= e($enrollment['academic_year_code']) ?></dd></div>
                        <div><dt class="inline text-slate-600">Semester:</dt> <dd class="inline font-semibold text-brand-navy"><?= e($enrollment['term_name']) ?></dd></div>
                        <div><dt class="inline text-slate-600">Units Earned:</dt> <dd class="inline font-semibold text-brand-navy"><?= e($number($progress['earned'])) ?> / <?= e($number($progress['total'])) ?></dd></div>
                    </dl>
                </div>
                <ul class="mt-4 space-y-1.5 text-sm">
                    <li class="flex items-center gap-2"><span class="h-3 w-3 rounded-full bg-brand-600" aria-hidden="true"></span><span class="flex-1 text-slate-700">Completed units</span><span class="font-semibold text-brand-navy"><?= e($number($progress['earned'])) ?></span></li>
                    <li class="flex items-center gap-2"><span class="h-3 w-3 rounded-full bg-brand-100" aria-hidden="true"></span><span class="flex-1 text-slate-700">Remaining units</span><span class="font-semibold text-brand-navy"><?= e($number(max(0, $progress['total'] - $progress['earned']))) ?></span></li>
                </ul>
                <div class="mt-4"><?= component('progress-bar', ['percent' => $progress['percent'], 'label' => 'Curriculum completed']) ?></div>
            <?php endif; ?>
        </section>

        <section class="card p-5 sm:p-6" aria-labelledby="history-heading">
            <div class="mb-4 flex items-center justify-between gap-3">
                <h2 id="history-heading" class="<?= $cardTitle ?>"><span class="text-brand-600"><?= icon('document', 'h-7 w-7') ?></span> Academic History</h2>
                <a href="<?= e(url('/student/history')) ?>" class="text-sm font-semibold text-brand-600 hover:underline">View all</a>
            </div>
            <div class="table-scroll">
                <table class="min-w-full border-separate border-spacing-0 overflow-hidden rounded-xl border border-brand-100 text-left text-sm">
                    <caption class="sr-only">GWA by term</caption>
                    <thead class="bg-brand-50 text-brand-navy">
                        <tr>
                            <th scope="col" class="border-b border-brand-100 px-3 py-2.5 font-semibold">Term</th>
                            <th scope="col" class="border-b border-brand-100 px-3 py-2.5 text-center font-semibold">GWA</th>
                            <th scope="col" class="border-b border-brand-100 px-3 py-2.5 text-center font-semibold">Remarks</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white text-slate-700">
                        <?php foreach (array_slice($termRows, 0, 5) as $term): ?>
                            <tr>
                                <td class="border-b border-brand-100 px-3 py-2.5">
                                    <span class="block font-medium text-brand-navy"><?= e($term['term_name']) ?></span>
                                    <span class="block text-xs text-brand-gray">A.Y. <?= e($term['academic_year_code']) ?></span>
                                </td>
                                <td class="border-b border-brand-100 px-3 py-2.5 text-center font-semibold tabular-nums text-brand-navy"><?= e($term['summary']['gwa'] ?? '—') ?></td>
                                <td class="whitespace-nowrap border-b border-brand-100 px-3 py-2.5 text-center">
                                    <?php if ($term['summary']['remark'] !== null): ?>
                                        <?= component('status-badge', ['status' => $term['summary']['remark'], 'label' => GradeCalculator::remarkLabel($term['summary']['remark'])]) ?>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-sm text-slate-600">Cumulative GWA: <span class="font-display font-bold text-brand-navy"><?= e($cumulativeGwa ?? '—') ?></span></p>
        </section>

        <section class="card p-5 sm:p-6" aria-labelledby="actions-heading">
            <h2 id="actions-heading" class="<?= $cardTitle ?> mb-4"><span class="text-brand-600"><?= icon('bolt', 'h-7 w-7') ?></span> Quick Actions</h2>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 xl:grid-cols-1">
                <span class="flex cursor-not-allowed items-center gap-3 rounded-xl bg-brand-50/70 p-3 text-sm text-slate-600" title="Coming soon">
                    <span class="text-brand-600"><?= icon('download', 'h-7 w-7') ?></span>
                    <span>Download Grades <span class="block text-[10px] font-semibold uppercase tracking-wide text-brand-600">Soon</span></span>
                </span>
                <a href="<?= e(url('/student/schedule' . $termQuery)) ?>" class="flex items-center gap-3 rounded-xl bg-success-bg p-3 text-sm font-medium text-success-text hover:ring-2 hover:ring-success-line">
                    <?= icon('calendar', 'h-7 w-7') ?>
                    <span>View Class Schedule</span>
                </a>
                <span class="flex cursor-not-allowed items-center gap-3 rounded-xl bg-brand-50/70 p-3 text-sm text-slate-600" title="Coming soon">
                    <span class="text-brand-600"><?= icon('history', 'h-7 w-7') ?></span>
                    <span>Request Official Transcript <span class="block text-[10px] font-semibold uppercase tracking-wide text-brand-600">Soon</span></span>
                </span>
            </div>
        </section>
    </div>
</div>
<?php endif; ?>
