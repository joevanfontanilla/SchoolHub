<?php
/**
 * The heading of a printable class report: which class, when it meets, and who teaches it.
 *
 * @var string $heading
 * @var array  $class  ClassSchedule::find() with meetings
 * @var App\Models\User $user
 */
use App\Services\ClassScheduleService;

$meetings = implode('; ', array_map(
    static fn (array $m) => ClassScheduleService::DAYS[(int) $m['day_of_week']] . ' ' . ClassScheduleService::timeRange($m['start_time'], $m['end_time']) . ($m['room_code'] ? ' · ' . $m['room_code'] : ''),
    $class['meetings']
));
?>
<div class="flex flex-wrap items-start justify-between gap-4">
    <div>
        <h1 class="font-display text-2xl font-extrabold uppercase tracking-wide text-brand-navy"><?= e($heading) ?></h1>
        <p class="mt-1 text-sm font-semibold text-slate-800"><?= e($class['code'] . ' · ' . $class['title']) ?></p>
        <p class="text-sm text-slate-600"><?= e($class['section_code'] . ' · ' . $class['term_name'] . ', A.Y. ' . $class['academic_year_code'] . ' · ' . $class['units'] . ' units') ?></p>
        <?php if ($meetings !== ''): ?><p class="text-sm text-slate-600"><?= e($meetings) ?></p><?php endif; ?>
    </div>
    <div class="text-right text-xs text-slate-500">
        <p>Instructor: <?= e($class['teacher_name'] ?? $user->fullName()) ?></p>
        <p>As of <?= e(dt(date('Y-m-d H:i:s'), 'F j, Y g:i A')) ?></p>
    </div>
</div>
