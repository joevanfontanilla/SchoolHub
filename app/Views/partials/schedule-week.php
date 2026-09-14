<?php
/**
 * Weekly timetable (docs/21). A column per day on wide screens, stacked on
 * phones. Monday to Saturday always show; Sunday only when something meets.
 *
 * @var array  $blocks  ClassSchedule::blocks()
 * @var ?bool  $highlightToday
 */
use App\Services\ClassScheduleService;

$byDay = [];
foreach ($blocks as $block) {
    $byDay[$block['day']][] = $block;
}
$days = array_filter(ClassScheduleService::DAYS, static fn ($name, $day) => $day <= 6 || isset($byDay[$day]), ARRAY_FILTER_USE_BOTH);
$today = ($highlightToday ?? true) ? (int) date('N') : 0;
$time = static fn (string $value): string => date('g:i A', strtotime($value));
?>
<ol class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 <?= count($days) === 7 ? 'xl:grid-cols-7' : 'xl:grid-cols-6' ?>" aria-label="Weekly schedule">
    <?php foreach ($days as $day => $name): ?>
        <li class="rounded-xl border <?= $day === $today ? 'border-brand-600 bg-brand-50' : 'border-slate-200 bg-white' ?> p-3">
            <h3 class="flex items-center justify-between font-display text-sm font-bold text-brand-navy">
                <?= e($name) ?>
                <?php if ($day === $today): ?><span class="badge bg-brand-600 text-white">Today</span><?php endif; ?>
            </h3>
            <?php if (empty($byDay[$day])): ?>
                <p class="mt-2 text-xs text-brand-gray">No classes</p>
            <?php else: ?>
                <ul class="mt-2 space-y-2">
                    <?php foreach ($byDay[$day] as $block): ?>
                        <li class="rounded-lg border-l-4 border-brand-primary bg-white px-3 py-2 shadow-sm ring-1 ring-slate-100">
                            <p class="text-xs font-semibold text-brand-600"><?= e($time($block['start'])) ?>–<?= e($time($block['end'])) ?></p>
                            <p class="font-display text-sm font-bold text-brand-navy"><?= e($block['code']) ?></p>
                            <p class="text-xs text-slate-700"><?= e($block['title']) ?></p>
                            <?php if ($block['detail'] !== ''): ?>
                                <p class="mt-0.5 text-xs text-brand-gray"><?= e($block['detail']) ?></p>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ol>
