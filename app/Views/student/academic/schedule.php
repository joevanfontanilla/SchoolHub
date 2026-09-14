<?php
/**
 * Academic Records → Class Schedule (docs/08).
 *
 * @var array  $enrollments
 * @var ?array $enrollment
 * @var array  $active   enlisted classes
 * @var array  $blocks
 * @var array  $summary
 */
use App\Services\ClassScheduleService;

$number = static fn ($value): string => rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');
$rooms = array_unique(array_filter(array_merge(...array_map(static fn ($c) => array_column($c['meetings'], 'room_code'), $active ?: [['meetings' => []]]))));
$hours = 0.0;
foreach ($active as $class) {
    foreach ($class['meetings'] as $meeting) {
        $hours += (strtotime($meeting['end_time']) - strtotime($meeting['start_time'])) / 3600;
    }
}
?>
<?= partial('student/records-header', ['tab' => 'schedule', 'enrollments' => $enrollments, 'enrollment' => $enrollment, 'school' => $school]) ?>

<?php if ($enrollment === null): ?>
    <section class="card"><?= component('empty-state', ['title' => 'You are not enrolled in a term yet', 'message' => 'Your schedule appears once the registrar confirms your enrollment.']) ?></section>
<?php elseif ($active === []): ?>
    <section class="card"><?= component('empty-state', ['title' => 'No classes yet', 'message' => 'The registrar is still setting up your section\'s classes.']) ?></section>
<?php else: ?>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-slate-600">Every class of <?= e($enrollment['term_name']) ?>, with its room and teacher.</p>
        <a href="<?= e(url('/student/schedule/print?term_id=' . $enrollment['term_id'])) ?>" class="btn-primary"><?= icon('download', 'h-5 w-5') ?> Print schedule</a>
    </div>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <?= component('stat-card', ['label' => 'Classes', 'value' => (string) count($active), 'hint' => 'Section ' . $enrollment['section_code'], 'icon' => 'book-open']) ?>
        <?= component('stat-card', ['label' => 'Units', 'value' => $number($summary['units']), 'hint' => $enrollment['term_name'], 'icon' => 'layers']) ?>
        <?= component('stat-card', ['label' => 'Hours per week', 'value' => $number($hours), 'hint' => 'In class', 'icon' => 'clock']) ?>
        <?= component('stat-card', ['label' => 'Rooms', 'value' => (string) count($rooms), 'hint' => $rooms === [] ? 'To be announced' : implode(', ', $rooms), 'icon' => 'map-pin']) ?>
    </div>

    <section class="card mb-6 p-5 sm:p-6" aria-labelledby="week-heading">
        <h2 id="week-heading" class="mb-4 flex items-center gap-3 font-display text-lg font-bold text-brand-navy"><span class="text-brand-600"><?= icon('calendar', 'h-7 w-7') ?></span> Weekly Timetable</h2>
        <?= partial('schedule-week', ['blocks' => $blocks]) ?>
    </section>

    <section class="card p-5 sm:p-6" aria-labelledby="list-heading">
        <h2 id="list-heading" class="mb-4 flex items-center gap-3 font-display text-lg font-bold text-brand-navy"><span class="text-brand-600"><?= icon('book-open', 'h-7 w-7') ?></span> Classes</h2>
        <div class="table-scroll">
            <table class="min-w-full border-separate border-spacing-0 overflow-hidden rounded-xl border border-brand-100 text-left text-sm">
                <caption class="sr-only">Classes this term</caption>
                <thead class="bg-brand-50 text-brand-navy">
                    <tr>
                        <?php foreach (['Subject Code', 'Subject Title', 'Units', 'Days and Time', 'Room', 'Instructor'] as $heading): ?>
                            <th scope="col" class="whitespace-nowrap border-b border-brand-100 px-4 py-3 font-semibold"><?= $heading ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody class="bg-white text-slate-700">
                    <?php foreach ($active as $class): ?>
                        <tr class="hover:bg-brand-50/40">
                            <td class="whitespace-nowrap border-b border-brand-100 px-4 py-3 font-semibold text-brand-navy"><?= e($class['code']) ?></td>
                            <td class="border-b border-brand-100 px-4 py-3"><?= e($class['title']) ?></td>
                            <td class="border-b border-brand-100 px-4 py-3 text-center"><?= e($number($class['units'])) ?></td>
                            <td class="border-b border-brand-100 px-4 py-3">
                                <?php foreach ($class['meetings'] as $meeting): ?>
                                    <span class="block whitespace-nowrap"><?= e(substr(ClassScheduleService::DAYS[(int) $meeting['day_of_week']], 0, 3)) ?> · <?= e(ClassScheduleService::timeRange($meeting['start_time'], $meeting['end_time'])) ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td class="border-b border-brand-100 px-4 py-3"><?= e(implode(', ', array_unique(array_filter(array_column($class['meetings'], 'room_code')))) ?: 'TBA') ?></td>
                            <td class="whitespace-nowrap border-b border-brand-100 px-4 py-3"><?= e($class['teacher_name'] ?? 'TBA') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
