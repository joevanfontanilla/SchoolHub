<?php
/**
 * Grading overview: each class against the grading periods open now.
 *
 * @var array $classes
 * @var array $progress class id => open periods with 'graded' counts
 * @var array $open
 */
?>
<div class="space-y-6">
    <div>
        <p class="eyebrow">Teaching</p>
        <h2 class="mt-1 text-2xl">Grades</h2>
        <p class="mt-1 text-sm text-slate-600">
            <?= $open === []
                ? 'No grading period is open. The registrar opens Prelim, Midterm, and Finals; you can view grade sheets in the meantime.'
                : 'Open now: ' . e(implode(', ', array_unique(array_column($open, 'name')))) . '. Grades lock when the registrar closes the period.' ?>
        </p>
    </div>

    <?php if ($classes === []): ?>
        <section class="card"><?= component('empty-state', ['title' => 'No classes assigned']) ?></section>
    <?php else: ?>
        <section class="card">
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Class</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Students</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Open periods</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($classes as $class): ?>
                            <tr>
                                <td class="px-4 py-3">
                                    <span class="font-display font-bold text-brand-navy"><?= e($class['code']) ?></span> · <?= e($class['section_code']) ?>
                                    <span class="block text-xs text-brand-gray"><?= e($class['title']) ?> · <?= e($class['term_name']) ?></span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3"><?= e($class['enlisted_count']) ?></td>
                                <td class="px-4 py-3">
                                    <?php if (empty($progress[(int) $class['id']])): ?>
                                        <span class="text-brand-gray">None open</span>
                                    <?php else: ?>
                                        <?php foreach ($progress[(int) $class['id']] as $period): ?>
                                            <?php $done = (int) $period['graded'] >= (int) $class['enlisted_count']; ?>
                                            <a href="<?= e(url('/teacher/classes/' . $class['id'] . '/grades?period=' . $period['id'])) ?>" class="mr-2 inline-flex items-center gap-1 hover:underline">
                                                <?= component('status-badge', ['status' => $done ? 'PASSED' : 'INC', 'label' => $period['name'] . ': ' . $period['graded'] . ' of ' . $class['enlisted_count']]) ?>
                                            </a>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <a href="<?= e(url('/teacher/classes/' . $class['id'] . '/grades')) ?>" class="font-semibold text-brand-600 hover:underline">Grade sheet<span class="sr-only"> for <?= e($class['code'] . ' ' . $class['section_code']) ?></span></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>
