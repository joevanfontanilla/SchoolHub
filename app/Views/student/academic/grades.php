<?php
/**
 * The student's grades for the current term (docs/08, docs/27).
 *
 * @var ?array $enrollment
 * @var array  $classes
 * @var array  $periods
 */
?>
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">My classes</p>
            <h2 class="mt-1 text-2xl">Grades</h2>
            <?php if ($enrollment !== null): ?>
                <p class="mt-1 text-sm text-slate-600"><?= e($enrollment['term_name']) ?>, A.Y. <?= e($enrollment['academic_year_code']) ?> · Section <?= e($enrollment['section_code']) ?></p>
            <?php endif; ?>
        </div>
        <a href="<?= e(url('/student/history')) ?>" class="btn-secondary">All terms</a>
    </div>

    <?php if ($enrollment === null || $classes === []): ?>
        <section class="card"><?= component('empty-state', ['title' => 'No grades yet', 'message' => 'Grades appear here as your teachers record them.']) ?></section>
    <?php else: ?>
        <section class="card">
            <?= partial('grade-table', ['periods' => $periods, 'classes' => $classes, 'caption' => 'Grades for ' . $enrollment['term_name']]) ?>
        </section>
        <p class="text-xs text-brand-gray">
            1.00 is the highest grade and 3.00 the lowest passing grade; 5.00 is failing. The final grade is the weighted average of
            <?= e(implode(', ', array_map(static fn ($p) => $p['name'] . ' (' . rtrim(rtrim((string) $p['weight'], '0'), '.') . '%)', $periods))) ?>,
            shown once every period is graded. A grade may still change while its period is open.
        </p>
    <?php endif; ?>
</div>
