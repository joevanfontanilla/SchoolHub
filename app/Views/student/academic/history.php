<?php
/**
 * Every term the student has been enrolled in, newest first (docs/13).
 *
 * @var array $history AcademicRecordService::history()
 */
use App\Services\GradeCalculator;

$passedUnits = 0.0;
foreach ($history as $term) {
    foreach ($term['classes'] as $class) {
        if ($class['final']['remark'] === GradeCalculator::REMARK_PASSED) {
            $passedUnits += (float) $class['units'];
        }
    }
}
?>
<div class="space-y-6">
    <div>
        <p class="eyebrow">My records</p>
        <h2 class="mt-1 text-2xl">Academic history</h2>
        <p class="mt-1 text-sm text-slate-600">
            <?= count($history) ?> term<?= count($history) === 1 ? '' : 's' ?> · <?= e(rtrim(rtrim(number_format($passedUnits, 1, '.', ''), '0'), '.')) ?> units passed.
            This is not an official transcript.
        </p>
    </div>

    <?php if ($history === []): ?>
        <section class="card"><?= component('empty-state', ['title' => 'No terms yet', 'message' => 'Each term you are enrolled in is kept here, with its grades.']) ?></section>
    <?php else: ?>
        <?php foreach ($history as $term): ?>
            <section class="card" aria-labelledby="term-<?= e($term['id']) ?>">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h3 id="term-<?= e($term['id']) ?>" class="text-base"><?= e($term['term_name']) ?>, A.Y. <?= e($term['academic_year_code']) ?></h3>
                    <p class="text-xs text-brand-gray"><?= e($term['program_code']) ?> · <?= e($term['year_level_name']) ?> · Section <?= e($term['section_code']) ?></p>
                </div>
                <?php if ($term['classes'] === []): ?>
                    <p class="px-5 py-4 text-sm text-brand-gray">No classes recorded for this term.</p>
                <?php else: ?>
                    <?= partial('grade-table', ['periods' => $term['periods'], 'classes' => $term['classes'], 'caption' => $term['term_name'] . ' ' . $term['academic_year_code']]) ?>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
