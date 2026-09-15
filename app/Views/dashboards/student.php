<?php
/**
 * Student dashboard (docs/08, docs/21).
 *
 * @var ?array $current       the application in progress
 * @var array  $steps         [label, done, link|null]
 * @var array  $openTerms
 * @var array  $cards
 * @var ?array $enrollment    current enrollment, once enrolled
 * @var array  $classes
 * @var array  $todayClasses  [class, meeting] meeting today
 * @var array  $termSummary
 * @var ?array $progress
 * @var ?int   $yearLevel
 * @var array  $announcements
 */
use App\Services\ClassScheduleService;

$number = static fn ($value): string => rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');
$ordinal = static fn (int $n): string => $n . (['th', 'st', 'nd', 'rd'][($n % 100 >= 11 && $n % 100 <= 13) ? 0 : min($n % 10, 4) % 4] ?? 'th');
$firstOpen = null;
foreach ($steps as $i => [, $done]) {
    if (!$done) {
        $firstOpen = $i;
        break;
    }
}
$enrolled = $enrollment !== null;
if ($enrolled) {
    $cards = [
        ['label' => 'Enrolled term', 'value' => $enrollment['term_name'], 'hint' => 'A.Y. ' . $enrollment['academic_year_code'] . ' · ' . $enrollment['section_code'], 'icon' => 'calendar-check', 'tone' => 'success', 'href' => '/student/schedule'],
        ['label' => 'Units this term', 'value' => $number($termSummary['units']), 'hint' => $termSummary['taken'] . ' class' . ($termSummary['taken'] === 1 ? '' : 'es'), 'icon' => 'layers', 'href' => '/student/schedule'],
        ['label' => 'Term GWA', 'value' => $termSummary['gwa'] ?? '—', 'hint' => $termSummary['gwa'] === null ? 'Shown once finals are complete' : 'NSTP and PE not included', 'icon' => 'trophy', 'href' => '/student/grades'],
        ['label' => 'Curriculum progress', 'value' => $progress === null ? '—' : $progress['percent'] . '%', 'hint' => $progress === null ? 'Not assigned yet' : $number($progress['earned']) . ' of ' . $number($progress['total']) . ' units', 'icon' => 'target', 'href' => '/student/history'],
    ];
}
$cardTitle = 'flex items-center gap-3 font-display text-lg font-bold text-brand-navy';
?>
<?= partial('page-hero', [
    'heading'     => 'Welcome back, ' . $user->first_name,
    'subtitle'    => $enrolled
        ? 'You are enrolled for ' . $enrollment['term_name'] . ', A.Y. ' . $enrollment['academic_year_code'] . '. Here is your day at a glance.'
        : 'Your enrollment, requirements, and account at a glance.',
    'tagline'     => $school['school_tagline'] ?? null,
    'breadcrumbs' => [['Home', null], ['Dashboard', null]],
]) ?>

<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-end gap-3">
        <?php if ($enrolled): ?>
            <a href="<?= e(url('/student/grades')) ?>" class="btn-primary"><?= icon('book-open', 'h-5 w-5') ?> Academic Records</a>
        <?php endif; ?>
        <?php if ($current !== null): ?>
            <a href="<?= e(url('/student/enrollment/' . $current['id'])) ?>" class="<?= $enrolled ? 'btn-secondary' : 'btn-primary' ?>"><?= icon('enrollment', 'h-5 w-5') ?> Open my application</a>
        <?php elseif ($openTerms !== []): ?>
            <a href="<?= e(url('/student/enrollment/apply')) ?>" class="btn-primary"><?= icon('plus', 'h-5 w-5') ?> Apply for enrollment</a>
        <?php endif; ?>
    </div>

    <?= partial('dashboard-cards', ['cards' => $cards]) ?>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <?php if ($enrolled): ?>
                <section class="card p-5 sm:p-6" aria-labelledby="today-heading">
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <h2 id="today-heading" class="<?= $cardTitle ?>"><span class="text-brand-600"><?= icon('clock', 'h-7 w-7') ?></span> Today, <?= e(date('l, F j')) ?></h2>
                        <a href="<?= e(url('/student/schedule')) ?>" class="text-sm font-semibold text-brand-600 hover:underline">Full schedule</a>
                    </div>
                    <?php if ($todayClasses === []): ?>
                        <p class="rounded-xl bg-brand-50/60 px-4 py-6 text-center text-sm text-slate-600">No classes today.</p>
                    <?php else: ?>
                        <ol class="space-y-3">
                            <?php foreach ($todayClasses as $row): ?>
                                <li class="flex flex-wrap items-center gap-4 rounded-xl border border-brand-100 p-4">
                                    <span class="w-40 font-display text-sm font-bold text-brand-600"><?= e(ClassScheduleService::timeRange($row['meeting']['start_time'], $row['meeting']['end_time'])) ?></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block font-display font-bold text-brand-navy"><?= e($row['class']['code']) ?> · <?= e($row['class']['title']) ?></span>
                                        <span class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-slate-600">
                                            <span class="inline-flex items-center gap-1"><?= icon('map-pin', 'h-4 w-4') ?> <?= e($row['meeting']['room_code'] ?? 'Room TBA') ?></span>
                                            <span class="inline-flex items-center gap-1"><?= icon('user', 'h-4 w-4') ?> <?= e($row['class']['teacher_name'] ?? 'Teacher TBA') ?></span>
                                        </span>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?= partial('announcement-list', ['announcements' => $announcements]) ?>
        </div>

        <div class="space-y-6">
            <?php if ($enrolled && $progress !== null): ?>
                <section class="card p-5 sm:p-6" aria-labelledby="progress-heading">
                    <h2 id="progress-heading" class="<?= $cardTitle ?> mb-4"><span class="text-brand-600"><?= icon('target', 'h-7 w-7') ?></span> Academic Progress</h2>
                    <div class="flex items-center gap-5">
                        <?= component('donut', ['percent' => $progress['percent'], 'title' => $yearLevel ? $ordinal((int) $yearLevel) : '—', 'subtitle' => 'Year', 'label' => 'Curriculum units earned', 'size' => 130]) ?>
                        <p class="text-sm text-slate-600">
                            <span class="block font-display text-2xl font-bold text-brand-navy"><?= e($number($progress['earned'])) ?> / <?= e($number($progress['total'])) ?></span>
                            curriculum units earned
                        </p>
                    </div>
                    <div class="mt-4"><?= component('progress-bar', ['percent' => $progress['percent'], 'label' => 'Curriculum completed']) ?></div>
                </section>
            <?php endif; ?>

            <section class="card" aria-labelledby="next-steps-heading">
                <div class="border-b border-brand-100 px-5 py-4">
                    <h2 id="next-steps-heading" class="<?= $cardTitle ?>"><span class="text-brand-600"><?= icon('list-check', 'h-7 w-7') ?></span> Enrollment Steps</h2>
                </div>
                <ol class="space-y-1 px-3 py-3">
                    <?php foreach ($steps as $i => [$label, $done, $link]): ?>
                        <?php $isNext = $i === $firstOpen; ?>
                        <li>
                            <?php $inner = sprintf(
                                '<span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold %s" aria-hidden="true">%s</span>'
                                . '<span class="flex-1">%s</span><span class="%s">%s</span>',
                                $done ? 'bg-success-bg text-success-text' : ($isNext ? 'bg-brand-600 text-white' : 'bg-brand-50 text-slate-500'),
                                $done ? icon('attendance', 'h-4 w-4') : ($i + 1),
                                e($label),
                                $done ? 'text-xs font-semibold text-success-text' : ($isNext ? 'text-xs font-semibold text-brand-600' : 'text-xs text-brand-gray'),
                                $done ? 'Done' : ($isNext ? 'Next' : 'Later')
                            ); ?>
                            <?php if ($link !== null && !$done): ?>
                                <a href="<?= e(url($link)) ?>" class="flex items-center gap-3 rounded-xl px-2 py-2.5 text-sm <?= $isNext ? 'bg-brand-50 font-semibold text-brand-navy' : 'text-slate-700 hover:bg-brand-50/60' ?>">
                                    <?= $inner ?>
                                </a>
                            <?php else: ?>
                                <div class="flex items-center gap-3 px-2 py-2.5 text-sm <?= $done ? 'text-slate-500' : 'text-slate-700' ?>">
                                    <?= $inner ?>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>
        </div>
    </div>
</div>
