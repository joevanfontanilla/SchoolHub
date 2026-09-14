<?php
/**
 * The student's class schedule for the current term (docs/08).
 *
 * @var ?array $enrollment
 * @var array  $classes
 * @var array  $blocks
 */
use App\Services\ClassScheduleService;

$number = static fn ($value): string => rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');
?>
<div class="space-y-6">
    <div>
        <p class="eyebrow">My classes</p>
        <h2 class="mt-1 text-2xl">Schedule</h2>
        <?php if ($enrollment !== null): ?>
            <p class="mt-1 text-sm text-slate-600">
                <?= e($enrollment['term_name']) ?>, A.Y. <?= e($enrollment['academic_year_code']) ?> · Section <?= e($enrollment['section_code']) ?> · <?= e($number($enrollment['total_units'])) ?> units
            </p>
        <?php endif; ?>
    </div>

    <?php if ($enrollment === null): ?>
        <section class="card"><?= component('empty-state', ['title' => 'You are not enrolled in a term yet', 'message' => 'Your schedule appears once the registrar confirms your enrollment.']) ?></section>
    <?php elseif ($classes === []): ?>
        <section class="card"><?= component('empty-state', ['title' => 'No classes yet', 'message' => 'The registrar is still setting up your section\'s classes.']) ?></section>
    <?php else: ?>
        <section class="card p-4 sm:p-5" aria-label="Weekly timetable">
            <?= partial('schedule-week', ['blocks' => $blocks]) ?>
        </section>

        <section class="card" aria-labelledby="list-heading">
            <h3 id="list-heading" class="border-b border-slate-200 px-5 py-4 text-base">Classes</h3>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Subject</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Units</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Days and time</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Room</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Teacher</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($classes as $class): ?>
                            <tr>
                                <td class="px-4 py-3"><span class="font-display font-bold text-brand-navy"><?= e($class['code']) ?></span><span class="block text-xs text-slate-600"><?= e($class['title']) ?></span></td>
                                <td class="whitespace-nowrap px-4 py-3"><?= e($number($class['units'])) ?></td>
                                <td class="px-4 py-3">
                                    <?php foreach ($class['meetings'] as $meeting): ?>
                                        <span class="block whitespace-nowrap"><?= e(ClassScheduleService::DAYS[(int) $meeting['day_of_week']]) ?> <?= e(ClassScheduleService::timeRange($meeting['start_time'], $meeting['end_time'])) ?></span>
                                    <?php endforeach; ?>
                                </td>
                                <td class="px-4 py-3"><?= e(implode(', ', array_unique(array_filter(array_column($class['meetings'], 'room_code')))) ?: 'To be announced') ?></td>
                                <td class="whitespace-nowrap px-4 py-3"><?= e($class['teacher_name'] ?? 'To be announced') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>
