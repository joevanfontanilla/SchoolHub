<?php
/**
 * A student's printable class schedule (docs/08): every class with its days,
 * times, room, section, and teacher, and the week laid out by day.
 *
 * @var array $enrollment
 * @var array $student
 * @var array $classes
 * @var array $blocks
 */
use App\Services\ClassScheduleService;

$number = static fn ($value): string => rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');
$units = array_sum(array_map(static fn ($c) => (float) $c['units'], $classes));
$cell = 'border-b border-slate-200 px-3 py-2 align-top';
$byDay = [];
foreach ($blocks as $block) {
    $byDay[$block['day']][] = $block;
}
?>
<section class="mt-6 space-y-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl font-extrabold uppercase tracking-wide text-brand-navy">Class Schedule</h1>
            <p class="mt-1 text-sm text-slate-600"><?= e($enrollment['term_name']) ?>, A.Y. <?= e($enrollment['academic_year_code']) ?></p>
        </div>
        <p class="rounded-lg bg-success-bg px-3 py-1.5 text-sm font-semibold text-success-text">Officially enrolled</p>
    </div>

    <dl class="grid gap-x-8 gap-y-1 text-sm sm:grid-cols-2">
        <div class="flex justify-between border-b border-slate-100 py-1.5"><dt class="text-slate-500">Student</dt><dd class="font-semibold text-brand-navy"><?= e($student['name']) ?></dd></div>
        <div class="flex justify-between border-b border-slate-100 py-1.5"><dt class="text-slate-500">Student No.</dt><dd class="font-semibold"><?= e($student['student_number'] ?? '—') ?></dd></div>
        <div class="flex justify-between border-b border-slate-100 py-1.5"><dt class="text-slate-500">Program / Year</dt><dd class="font-semibold"><?= e($enrollment['program_code'] . ' · ' . $enrollment['year_level_name']) ?></dd></div>
        <div class="flex justify-between border-b border-slate-100 py-1.5"><dt class="text-slate-500">Section / Units</dt><dd class="font-semibold"><?= e($enrollment['section_code'] . ' · ' . $number($units) . ' units') ?></dd></div>
    </dl>

    <?php if ($classes === []): ?>
        <p class="text-sm text-slate-600">No classes yet.</p>
    <?php else: ?>
        <table class="w-full border border-slate-300 text-sm">
            <caption class="sr-only">Classes</caption>
            <thead class="bg-brand-50 text-left text-brand-navy">
                <tr>
                    <th scope="col" class="<?= $cell ?>">Code</th>
                    <th scope="col" class="<?= $cell ?>">Subject</th>
                    <th scope="col" class="<?= $cell ?> text-right">Units</th>
                    <th scope="col" class="<?= $cell ?>">Days and time</th>
                    <th scope="col" class="<?= $cell ?>">Room</th>
                    <th scope="col" class="<?= $cell ?>">Teacher</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($classes as $class): ?>
                    <tr>
                        <td class="<?= $cell ?> whitespace-nowrap font-semibold"><?= e($class['code']) ?></td>
                        <td class="<?= $cell ?>"><?= e($class['title']) ?></td>
                        <td class="<?= $cell ?> text-right"><?= e($number($class['units'])) ?></td>
                        <td class="<?= $cell ?> whitespace-nowrap">
                            <?php foreach ($class['meetings'] as $meeting): ?>
                                <span class="block"><?= e(substr(ClassScheduleService::DAYS[(int) $meeting['day_of_week']], 0, 3)) ?> <?= e(ClassScheduleService::timeRange($meeting['start_time'], $meeting['end_time'])) ?></span>
                            <?php endforeach; ?>
                        </td>
                        <td class="<?= $cell ?>"><?= e(implode(', ', array_unique(array_filter(array_column($class['meetings'], 'room_code')))) ?: 'TBA') ?></td>
                        <td class="<?= $cell ?> font-medium"><?= e($class['teacher_name'] ?? 'To be assigned') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot><tr><th scope="row" colspan="2" class="px-3 py-2 text-right">Total units</th><td class="px-3 py-2 text-right font-semibold"><?= e($number($units)) ?></td><td colspan="3"></td></tr></tfoot>
        </table>

        <div class="break-inside-avoid">
            <h2 class="mb-2 font-display text-base font-bold text-brand-navy">Week at a glance</h2>
            <div class="grid grid-cols-3 gap-2 sm:grid-cols-6 print:grid-cols-6">
                <?php foreach (array_slice(ClassScheduleService::DAYS, 0, 6, true) as $day => $name): ?>
                    <div class="rounded border border-slate-300 p-2">
                        <p class="border-b border-slate-200 pb-1 text-xs font-bold uppercase text-brand-navy"><?= e($name) ?></p>
                        <?php foreach ($byDay[$day] ?? [] as $block): ?>
                            <div class="mt-1.5 text-[11px] leading-snug">
                                <p class="font-semibold"><?= e(date('g:i', strtotime($block['start'])) . '–' . date('g:i A', strtotime($block['end']))) ?></p>
                                <p class="font-bold text-brand-navy"><?= e($block['code']) ?></p>
                                <p class="text-slate-600"><?= e($block['detail']) ?></p>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($byDay[$day])): ?><p class="mt-1.5 text-[11px] text-slate-400">—</p><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</section>
