<?php
/**
 * Enrollment list (docs/15): officially enrolled students of a term, by program, year
 * level, and section.
 *
 * @var array $filters
 * @var array $terms
 * @var array $programs
 * @var array $yearLevels
 * @var array $sections
 * @var array $report  rows, groups, by_type, units
 * @var array $rows    this page's students
 * @var int   $page
 * @var int   $pages
 */
$total = count($report['rows']);
$types = $report['by_type'];
arsort($types);
?>
<div class="space-y-6">
    <?= partial('registrar/report-filters', [
        'active' => 'enrollment', 'heading' => 'Enrollment List', 'path' => '/registrar/reports/enrollment',
        'subtitle' => 'Officially enrolled students of a term, by program, year level, and section.',
        'fields' => ['term', 'program', 'year', 'section'], 'filters' => $filters,
        'terms' => $terms, 'programs' => $programs, 'yearLevels' => $yearLevels, 'sections' => $sections, 'statuses' => [],
    ]) ?>

    <div class="grid gap-4 sm:grid-cols-2 2xl:grid-cols-4">
        <?= component('stat-card', ['label' => 'Enrolled students', 'value' => number_format($total), 'hint' => count($report['groups']) . ' section' . (count($report['groups']) === 1 ? '' : 's'), 'icon' => 'users', 'tone' => 'success']) ?>
        <?= component('stat-card', ['label' => 'Units carried', 'value' => number_format($report['units'], 1), 'hint' => $total > 0 ? number_format($report['units'] / $total, 1) . ' units per student' : 'No students', 'icon' => 'book']) ?>
        <?= component('stat-card', ['label' => 'By student type', 'value' => $types === [] ? '0' : number_format((int) reset($types)), 'hint' => $types === [] ? 'No students' : implode(', ', array_map(static fn ($type, $count) => $count . ' ' . $type, array_keys($types), $types)), 'icon' => 'academic']) ?>
    </div>

    <section class="card" aria-labelledby="sections-heading">
        <h3 id="sections-heading" class="border-b border-brand-100 px-5 py-4 text-base">By program, year level, and section</h3>
        <?php if ($report['groups'] === []): ?>
            <?= component('empty-state', ['title' => 'No enrolled students', 'message' => 'Nobody is officially enrolled in this selection yet.']) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                    <thead class="bg-brand-50 text-brand-navy">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Program</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Year level</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Section</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Enrolled</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Capacity</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-50 bg-white">
                        <?php foreach ($report['groups'] as $group): ?>
                            <tr>
                                <td class="px-4 py-3 font-semibold text-brand-navy"><?= e($group['program_code']) ?></td>
                                <td class="px-4 py-3"><?= e($group['year_level_name']) ?></td>
                                <td class="px-4 py-3"><?= e($group['section_code']) ?></td>
                                <td class="px-4 py-3 text-right tabular-nums"><?= e(number_format($group['count'])) ?></td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-600"><?= e(number_format($group['capacity'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($rows !== []): ?>
        <section class="card" aria-labelledby="students-heading">
            <h3 id="students-heading" class="border-b border-brand-100 px-5 py-4 text-base">Students</h3>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                    <thead class="bg-brand-50 text-brand-navy">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Student</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Program</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Section</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Type</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Units</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Enrolled</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-50 bg-white">
                        <?php foreach ($rows as $row): ?>
                            <tr class="hover:bg-brand-50/40">
                                <td class="px-4 py-3"><a href="<?= e(url('/registrar/students/' . $row['student_id'])) ?>" class="font-semibold text-brand-navy hover:underline"><?= e($row['last_name'] . ', ' . $row['first_name']) ?></a><span class="block text-xs text-brand-gray"><?= e($row['student_number']) ?></span></td>
                                <td class="px-4 py-3"><?= e($row['program_code'] . ' · ' . $row['year_level_name']) ?></td>
                                <td class="px-4 py-3"><?= e($row['section_code']) ?></td>
                                <td class="px-4 py-3"><?= e($row['student_type']) ?></td>
                                <td class="px-4 py-3 text-right tabular-nums"><?= e($row['total_units']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600"><?= e(d($row['enrolled_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= partial('pagination', ['page' => $page, 'pages' => $pages, 'total' => $total, 'path' => '/registrar/reports/enrollment', 'query' => array_filter($filters, static fn ($v) => $v !== null), 'noun' => 'student']) ?>
        </section>
    <?php endif; ?>
</div>
