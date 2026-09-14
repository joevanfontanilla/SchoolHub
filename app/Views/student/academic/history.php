<?php
/**
 * Academic Records → Academic History: every term, oldest to newest in the chart
 * and newest first below (docs/13, docs/27).
 *
 * @var array   $enrollments
 * @var ?array  $enrollment
 * @var array   $termRows   history() terms, each with 'summary'
 * @var ?string $cumulativeGwa
 * @var ?array  $curriculum
 * @var ?array  $progress
 */
use App\Services\GradeCalculator;

$number = static fn ($value): string => rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');
$shortTerm = static fn (string $name): string => ['1st Semester' => '1st Sem', '2nd Semester' => '2nd Sem'][$name] ?? $name;
$unitsPassed = array_sum(array_map(static fn ($t) => $t['summary']['units_passed'], $termRows));
$chartItems = array_map(static fn ($term) => [
    'label'    => $shortTerm($term['term_name']),
    'sublabel' => $term['academic_year_code'],
    'value'    => $term['summary']['units_passed'],
    'display'  => $number($term['summary']['units_passed']),
], array_reverse($termRows));
?>
<?= partial('student/records-header', ['tab' => 'history', 'enrollments' => $enrollments, 'enrollment' => $enrollment, 'school' => $school]) ?>

<?php if ($termRows === []): ?>
    <section class="card"><?= component('empty-state', ['title' => 'No terms yet', 'message' => 'Each term you are enrolled in is kept here, with its grades.']) ?></section>
<?php else: ?>
    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <?= component('stat-card', ['label' => 'Cumulative GWA', 'value' => $cumulativeGwa ?? '—', 'hint' => 'NSTP and PE not included', 'icon' => 'trophy']) ?>
        <?= component('stat-card', ['label' => 'Terms enrolled', 'value' => (string) count($termRows), 'hint' => 'Since ' . end($termRows)['term_name'] . ' ' . end($termRows)['academic_year_code'], 'icon' => 'history']) ?>
        <?= component('stat-card', ['label' => 'Units passed', 'value' => $number($unitsPassed), 'hint' => 'All terms', 'icon' => 'attendance', 'tone' => 'success']) ?>
        <?= component('stat-card', ['label' => 'Curriculum', 'value' => $progress === null ? '—' : $progress['percent'] . '%', 'hint' => $curriculum === null ? 'Not assigned yet' : $curriculum['code'] . ' completed', 'icon' => 'target']) ?>
    </div>

    <section class="card mb-6 p-5 sm:p-6" aria-labelledby="chart-heading">
        <h2 id="chart-heading" class="mb-2 flex items-center gap-3 font-display text-lg font-bold text-brand-navy"><span class="text-brand-600"><?= icon('chart-bar', 'h-7 w-7') ?></span> Units Passed per Term</h2>
        <?= component('column-chart', ['items' => $chartItems, 'caption' => 'Units passed per term', 'unit' => 'units']) ?>
    </section>

    <div class="space-y-6">
        <?php foreach ($termRows as $term): ?>
            <section class="card p-5 sm:p-6" aria-labelledby="term-<?= e($term['id']) ?>">
                <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 id="term-<?= e($term['id']) ?>" class="font-display text-lg font-bold text-brand-navy"><?= e($term['term_name']) ?>, A.Y. <?= e($term['academic_year_code']) ?></h2>
                        <p class="text-sm text-slate-600"><?= e($term['program_code']) ?> · <?= e($term['year_level_name']) ?> · Section <?= e($term['section_code']) ?> · <?= e($number($term['summary']['units'])) ?> units</p>
                    </div>
                    <?php if ($term['summary']['remark'] !== null): ?>
                        <?= component('status-badge', ['status' => $term['summary']['remark'], 'label' => GradeCalculator::remarkLabel($term['summary']['remark']), 'size' => 'md']) ?>
                    <?php endif; ?>
                </div>
                <?php if ($term['classes'] === []): ?>
                    <p class="text-sm text-brand-gray">No classes recorded for this term.</p>
                <?php else: ?>
                    <?= partial('grade-table', ['periods' => $term['periods'], 'classes' => $term['classes'], 'summary' => $term['summary'], 'caption' => $term['term_name'] . ' ' . $term['academic_year_code']]) ?>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
