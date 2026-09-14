<?php
/**
 * A class roster (docs/12).
 *
 * @var array $class
 * @var array $roster
 * @var array $periods with 'graded'
 * @var array $dates   recent attendance dates
 */
use App\Services\ClassScheduleService;
?>
<div class="space-y-6">
    <a href="<?= e(url('/teacher/classes')) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; My classes</a>

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow"><?= e($class['section_code']) ?> · <?= e($class['term_name']) ?> <?= e($class['academic_year_code']) ?></p>
            <h2 class="mt-1 text-2xl"><?= e($class['code']) ?> · <?= e($class['title']) ?></h2>
            <p class="mt-1 text-sm text-slate-600">
                <?= e(implode('; ', array_map(static fn ($m) => ClassScheduleService::DAYS[(int) $m['day_of_week']] . ' ' . ClassScheduleService::timeRange($m['start_time'], $m['end_time']) . ($m['room_code'] ? ' (' . $m['room_code'] . ')' : ''), $class['meetings']))) ?>
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="<?= e(url('/teacher/classes/' . $class['id'] . '/attendance')) ?>" class="btn-primary">Take attendance</a>
            <a href="<?= e(url('/teacher/classes/' . $class['id'] . '/grades')) ?>" class="btn-secondary">Grade sheet</a>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="card lg:col-span-2" aria-labelledby="roster-heading">
            <h3 id="roster-heading" class="border-b border-slate-200 px-5 py-4 text-base">Roster · <?= count($roster) ?> student<?= count($roster) === 1 ? '' : 's' ?></h3>
            <?php if ($roster === []): ?>
                <?= component('empty-state', ['title' => 'No students enlisted yet']) ?>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">#</th>
                                <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Student</th>
                                <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Student no.</th>
                                <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Program</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($roster as $index => $student): ?>
                                <tr>
                                    <td class="px-4 py-3 text-brand-gray"><?= $index + 1 ?></td>
                                    <td class="px-4 py-3 font-semibold text-brand-navy"><?= e($student['last_name'] . ', ' . $student['first_name']) ?></td>
                                    <td class="whitespace-nowrap px-4 py-3 tabular-nums"><?= e($student['student_number']) ?></td>
                                    <td class="whitespace-nowrap px-4 py-3"><?= e(trim(($student['program_code'] ?? '') . ' ' . ($student['year_level_name'] ?? ''))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <div class="space-y-6">
            <section class="card" aria-labelledby="periods-heading">
                <h3 id="periods-heading" class="border-b border-slate-200 px-5 py-4 text-base">Grading</h3>
                <ul class="divide-y divide-slate-100">
                    <?php foreach ($periods as $period): ?>
                        <li class="flex items-center justify-between gap-2 px-5 py-3 text-sm">
                            <a href="<?= e(url('/teacher/classes/' . $class['id'] . '/grades?period=' . $period['id'])) ?>" class="font-semibold text-brand-600 hover:underline"><?= e($period['name']) ?></a>
                            <span class="flex items-center gap-2 text-xs text-brand-gray">
                                <?= e($period['graded']) ?>/<?= count($roster) ?>
                                <?= component('status-badge', ['status' => (int) $period['is_open'] === 1 ? 'OPEN' : 'CLOSED', 'label' => (int) $period['is_open'] === 1 ? 'Open' : 'Closed']) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>

            <section class="card" aria-labelledby="dates-heading">
                <h3 id="dates-heading" class="border-b border-slate-200 px-5 py-4 text-base">Recent attendance</h3>
                <?php if ($dates === []): ?>
                    <p class="px-5 py-4 text-sm text-brand-gray">Not taken yet.</p>
                <?php else: ?>
                    <ul class="divide-y divide-slate-100">
                        <?php foreach ($dates as $date): ?>
                            <li class="flex items-center justify-between px-5 py-3 text-sm">
                                <a href="<?= e(url('/teacher/classes/' . $class['id'] . '/attendance?date=' . $date['attendance_date'])) ?>" class="font-semibold text-brand-600 hover:underline"><?= e(d($date['attendance_date'])) ?></a>
                                <span class="text-xs text-brand-gray"><?= e($date['present']) ?> present · <?= e($date['absent']) ?> absent · <?= e($date['late']) ?> late</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </div>
    </div>
</div>
