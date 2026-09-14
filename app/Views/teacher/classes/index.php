<?php
/**
 * The teacher's classes. The same list serves "My classes" and "Attendance".
 *
 * @var array  $classes
 * @var int    $today   ISO weekday
 * @var string $mode    'classes' or 'attendance'
 */
use App\Services\ClassScheduleService;

$attendance = $mode === 'attendance';
?>
<div class="space-y-6">
    <div>
        <p class="eyebrow">Teaching</p>
        <h2 class="mt-1 text-2xl"><?= $attendance ? 'Attendance' : 'My classes' ?></h2>
        <p class="mt-1 text-sm text-slate-600">
            <?= $attendance ? 'Choose a class to take or review attendance. Classes meeting today are marked.' : 'Only the classes the registrar assigned to you.' ?>
        </p>
    </div>

    <?php if ($classes === []): ?>
        <section class="card"><?= component('empty-state', ['title' => 'No classes assigned', 'message' => 'The registrar assigns classes when the term is set up.']) ?></section>
    <?php else: ?>
        <ul class="grid gap-4 md:grid-cols-2">
            <?php foreach ($classes as $class): ?>
                <?php $meetsToday = in_array($today, array_map(static fn ($m) => (int) $m['day_of_week'], $class['meetings']), true) && $class['term_start'] <= date('Y-m-d') && date('Y-m-d') <= $class['term_end']; ?>
                <li class="card flex flex-col p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wider text-brand-600"><?= e($class['section_code']) ?> · <?= e($class['term_name']) ?> <?= e($class['academic_year_code']) ?></p>
                            <h3 class="mt-1 text-lg"><?= e($class['code']) ?></h3>
                            <p class="text-sm text-slate-700"><?= e($class['title']) ?></p>
                        </div>
                        <?php if ($meetsToday): ?><span class="badge bg-brand-600 text-white">Meets today</span><?php endif; ?>
                    </div>
                    <ul class="mt-3 space-y-0.5 text-sm text-slate-600">
                        <?php foreach ($class['meetings'] as $meeting): ?>
                            <li><?= e(ClassScheduleService::DAYS[(int) $meeting['day_of_week']]) ?>, <?= e(ClassScheduleService::timeRange($meeting['start_time'], $meeting['end_time'])) ?><?= $meeting['room_code'] ? ' · ' . e($meeting['room_code']) : '' ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="mt-3 text-sm text-brand-gray"><?= e($class['enlisted_count']) ?> enlisted student<?= (int) $class['enlisted_count'] === 1 ? '' : 's' ?></p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <?php if ($attendance): ?>
                            <a href="<?= e(url('/teacher/classes/' . $class['id'] . '/attendance')) ?>" class="btn-primary">Take attendance<span class="sr-only"> for <?= e($class['code'] . ' ' . $class['section_code']) ?></span></a>
                        <?php else: ?>
                            <a href="<?= e(url('/teacher/classes/' . $class['id'])) ?>" class="btn-primary">Roster<span class="sr-only"> of <?= e($class['code'] . ' ' . $class['section_code']) ?></span></a>
                            <a href="<?= e(url('/teacher/classes/' . $class['id'] . '/attendance')) ?>" class="btn-secondary">Attendance</a>
                            <a href="<?= e(url('/teacher/classes/' . $class['id'] . '/grades')) ?>" class="btn-secondary">Grades</a>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
