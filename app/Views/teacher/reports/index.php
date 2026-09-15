<?php
/**
 * Class reports (docs/15): printable and spreadsheet class lists, attendance summaries,
 * and grade sheets for the teacher's own classes.
 *
 * @var array $classes
 */
$reports = [
    ['class-list', 'Class list', 'users', 'Enlisted students with student numbers and contact email.'],
    ['attendance', 'Attendance summary', 'attendance', 'Totals and attendance rate per student, with every date taken.'],
    ['grades', 'Grade sheet', 'chart-bar', 'Period grades, the computed final grade, and the remark.'],
];
?>
<div class="space-y-6">
    <div>
        <p class="eyebrow">Reports</p>
        <h2 class="mt-1 text-2xl">Class Reports</h2>
        <p class="mt-1 text-sm text-slate-600">Open a report to print it or save it as PDF, or export it for a spreadsheet. Only your own classes are listed.</p>
    </div>

    <?php if ($classes === []): ?>
        <section class="card"><?= component('empty-state', ['title' => 'No classes assigned', 'message' => 'Reports appear here once the registrar assigns you a class.']) ?></section>
    <?php else: ?>
        <?php foreach ($classes as $class): ?>
            <section class="card" aria-labelledby="class-<?= (int) $class['id'] ?>-heading">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-100 px-5 py-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-brand-600"><?= e($class['section_code']) ?> · <?= e($class['term_name'] . ' ' . $class['academic_year_code']) ?></p>
                        <h3 id="class-<?= (int) $class['id'] ?>-heading" class="mt-1 text-lg"><?= e($class['code']) ?> <span class="font-normal text-slate-700"><?= e($class['title']) ?></span></h3>
                    </div>
                    <p class="text-sm text-brand-gray"><?= e($class['enlisted_count']) ?> enlisted student<?= (int) $class['enlisted_count'] === 1 ? '' : 's' ?></p>
                </div>
                <ul class="divide-y divide-brand-50">
                    <?php foreach ($reports as [$slug, $label, $iconName, $description]): ?>
                        <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                            <div class="flex min-w-0 items-start gap-3">
                                <span class="mt-0.5 text-brand-600"><?= icon($iconName, 'h-5 w-5') ?></span>
                                <div class="min-w-0">
                                    <p class="font-semibold text-brand-navy"><?= e($label) ?></p>
                                    <p class="text-sm text-slate-600"><?= e($description) ?></p>
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <a href="<?= e(url('/teacher/classes/' . $class['id'] . '/reports/' . $slug)) ?>" class="btn-secondary"><?= icon('document', 'h-5 w-5') ?> Open<span class="sr-only"> <?= e($label . ' for ' . $class['code'] . ' ' . $class['section_code']) ?></span></a>
                                <a href="<?= e(url('/teacher/classes/' . $class['id'] . '/reports/' . $slug . '/export')) ?>" class="btn-primary"><?= icon('download', 'h-5 w-5') ?> CSV<span class="sr-only"> of <?= e($label . ' for ' . $class['code'] . ' ' . $class['section_code']) ?></span></a>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
