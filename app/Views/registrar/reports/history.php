<?php
/**
 * Enrollment history (docs/15): applicants and enrolled students for every term, newest
 * first, with enrolled students per program.
 *
 * @var ?int  $programId
 * @var array $programs
 * @var array $report  terms, programs
 */
$filters = ['term_id' => null, 'program_id' => $programId, 'year_level_id' => null, 'section_id' => null, 'status' => null];
?>
<div class="space-y-6">
    <?= partial('registrar/report-filters', [
        'active' => 'history', 'heading' => 'Enrollment History', 'path' => '/registrar/reports/history',
        'subtitle' => 'Applicants and officially enrolled students for every term, newest first.',
        'fields' => ['program'], 'filters' => $filters,
        'terms' => [], 'programs' => $programs, 'yearLevels' => [], 'sections' => [], 'statuses' => [],
    ]) ?>

    <section class="card" aria-labelledby="history-heading">
        <h3 id="history-heading" class="border-b border-brand-100 px-5 py-4 text-base">By term</h3>
        <?php if ($report['terms'] === []): ?>
            <?= component('empty-state', ['title' => 'No terms', 'message' => 'Terms appear here once the registrar sets up the academic calendar.']) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                    <thead class="bg-brand-50 text-brand-navy">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Term</th>
                            <th scope="col" class="px-3 py-3 text-right font-semibold">Applicants</th>
                            <th scope="col" class="px-3 py-3 text-right font-semibold">Approved</th>
                            <th scope="col" class="px-3 py-3 text-right font-semibold">Enrolled</th>
                            <th scope="col" class="px-3 py-3 text-right font-semibold">New students</th>
                            <th scope="col" class="px-3 py-3 text-right font-semibold">Units</th>
                            <?php foreach ($report['programs'] as $code): ?>
                                <th scope="col" class="px-3 py-3 text-right font-semibold"><?= e($code) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-50 bg-white">
                        <?php foreach ($report['terms'] as $term): ?>
                            <tr>
                                <th scope="row" class="whitespace-nowrap px-4 py-3 font-semibold text-brand-navy"><?= e($term['name']) ?><span class="block text-xs font-normal text-brand-gray">A.Y. <?= e($term['academic_year_code']) ?></span></th>
                                <td class="px-3 py-3 text-right tabular-nums"><?= e(number_format($term['applicants'])) ?></td>
                                <td class="px-3 py-3 text-right tabular-nums"><?= e(number_format($term['approved'])) ?></td>
                                <td class="px-3 py-3 text-right font-semibold tabular-nums"><?= e(number_format($term['enrolled'])) ?></td>
                                <td class="px-3 py-3 text-right tabular-nums"><?= e(number_format($term['new'])) ?></td>
                                <td class="px-3 py-3 text-right tabular-nums"><?= e(number_format($term['units'], 1)) ?></td>
                                <?php foreach ($report['programs'] as $code): ?>
                                    <td class="px-3 py-3 text-right tabular-nums <?= ($term['by_program'][$code] ?? 0) === 0 ? 'text-slate-400' : '' ?>"><?= e(number_format($term['by_program'][$code] ?? 0)) ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="border-t border-brand-100 px-5 py-3 text-xs text-brand-gray">A student's own terms, classes, and grades are on their record under Students.</p>
        <?php endif; ?>
    </section>
</div>
