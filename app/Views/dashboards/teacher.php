<?php
/**
 * Teacher dashboard (docs/12).
 *
 * @var array $cards
 * @var array $classes
 * @var array $todayClasses classes meeting today, each with its 'meeting'
 * @var array $open         open grading periods
 * @var array $announcements
 */
use App\Services\ClassScheduleService;
?>
<div class="space-y-6">
    <div>
        <h2 class="text-lg font-semibold text-brand-navy">Welcome, <?= e($user->first_name) ?></h2>
        <p class="mt-1 text-sm text-slate-600">Your classes, attendance, and grading tasks.</p>
    </div>

    <?= partial('dashboard-cards', ['cards' => $cards]) ?>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="card" aria-labelledby="today-heading">
                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                    <h2 id="today-heading" class="text-base font-semibold text-brand-navy">Today, <?= e(date('l, F j')) ?></h2>
                    <a href="<?= e(url('/teacher/schedule')) ?>" class="text-sm font-semibold text-brand-600 hover:underline">Full schedule</a>
                </div>
                <?php if ($todayClasses === []): ?>
                    <?= component('empty-state', ['title' => 'No classes today']) ?>
                <?php else: ?>
                    <ul class="divide-y divide-slate-100">
                        <?php foreach ($todayClasses as $class): ?>
                            <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                                <div>
                                    <p class="text-xs font-semibold text-brand-600"><?= e(ClassScheduleService::timeRange($class['meeting']['start_time'], $class['meeting']['end_time'])) ?><?= $class['meeting']['room_code'] ? ' · ' . e($class['meeting']['room_code']) : '' ?></p>
                                    <p class="font-display font-bold text-brand-navy"><?= e($class['code']) ?> <span class="font-sans text-sm font-normal text-slate-700"><?= e($class['title']) ?> · <?= e($class['section_code']) ?></span></p>
                                </div>
                                <a href="<?= e(url('/teacher/classes/' . $class['id'] . '/attendance?date=' . date('Y-m-d'))) ?>" class="btn-secondary">Take attendance</a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <section class="card" aria-labelledby="classes-heading">
                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                    <h2 id="classes-heading" class="text-base font-semibold text-brand-navy">My classes</h2>
                    <?php if ($open !== []): ?>
                        <a href="<?= e(url('/teacher/grades')) ?>" class="text-sm font-semibold text-brand-600 hover:underline">Enter grades</a>
                    <?php endif; ?>
                </div>
                <?php if ($classes === []): ?>
                    <?= component('empty-state', ['title' => 'No classes assigned', 'message' => 'The registrar assigns classes when the term is set up.']) ?>
                <?php else: ?>
                    <ul class="divide-y divide-slate-100">
                        <?php foreach ($classes as $class): ?>
                            <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm">
                                <a href="<?= e(url('/teacher/classes/' . $class['id'])) ?>" class="font-semibold text-brand-600 hover:underline"><?= e($class['code']) ?> · <?= e($class['section_code']) ?></a>
                                <span class="text-brand-gray"><?= e($class['term_name']) ?> · <?= e($class['enlisted_count']) ?> students</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </div>

        <?= partial('announcement-list', ['announcements' => $announcements]) ?>
    </div>
</div>
