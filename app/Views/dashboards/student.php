<?php
/**
 * Student dashboard (docs/08).
 *
 * @var ?array $current
 * @var array  $steps [label, done, link|null]
 * @var array  $openTerms
 */
$firstOpen = null;
foreach ($steps as $i => [, $done]) {
    if (!$done) {
        $firstOpen = $i;
        break;
    }
}
?>
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h2 class="text-xl">Welcome, <?= e($user->first_name) ?></h2>
            <p class="mt-1 text-sm text-slate-600">Your enrollment, requirements, and account at a glance.</p>
        </div>
        <?php if ($current !== null): ?>
            <a href="<?= e(url('/student/enrollment/' . $current['id'])) ?>" class="btn-primary">Open my application</a>
        <?php elseif ($openTerms !== []): ?>
            <a href="<?= e(url('/student/enrollment/apply')) ?>" class="btn-primary">Apply for enrollment</a>
        <?php endif; ?>
    </div>

    <?= partial('dashboard-cards', ['cards' => $cards]) ?>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <?= partial('announcement-list', ['announcements' => $announcements]) ?>
        </div>

        <section class="card" aria-labelledby="next-steps-heading">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 id="next-steps-heading" class="text-base">Your enrollment steps</h2>
            </div>
            <ol class="space-y-1 px-3 py-3">
                <?php foreach ($steps as $i => [$label, $done, $link]): ?>
                    <?php $isNext = $i === $firstOpen; ?>
                    <li>
                        <?php $inner = sprintf(
                            '<span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-bold %s" aria-hidden="true">%s</span>'
                            . '<span class="flex-1">%s</span><span class="%s">%s</span>',
                            $done ? 'bg-success-bg text-success-text' : ($isNext ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-500'),
                            $done ? '✓' : ($i + 1),
                            e($label),
                            $done ? 'text-xs font-semibold text-success-text' : ($isNext ? 'text-xs font-semibold text-brand-600' : 'text-xs text-brand-gray'),
                            $done ? 'Done' : ($isNext ? 'Next' : 'Later')
                        ); ?>
                        <?php if ($link !== null && !$done): ?>
                            <a href="<?= e(url($link)) ?>" class="flex items-center gap-3 rounded-lg px-2 py-2.5 text-sm <?= $isNext ? 'bg-brand-50 font-semibold text-brand-navy' : 'text-slate-700 hover:bg-slate-50' ?>">
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
