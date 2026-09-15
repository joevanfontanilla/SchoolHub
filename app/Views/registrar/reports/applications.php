<?php
/**
 * Applicants by status (docs/15): every submitted application of a term, with counts by
 * program and status. Drafts are never included.
 *
 * @var array $filters
 * @var array $terms
 * @var array $programs
 * @var array $statuses
 * @var array $report  rows, counts, matrix
 * @var array $rows    this page's applications
 * @var int   $page
 * @var int   $pages
 */
use App\Models\RegistrarReport;

$all = array_sum($report['counts']);
$inQueue = $report['counts']['SUBMITTED'] + $report['counts']['UNDER_REVIEW'];
$returned = $report['counts']['REQUIREMENTS_INCOMPLETE'] + $report['counts']['CORRECTION_REQUIRED'];
?>
<div class="space-y-6">
    <?= partial('registrar/report-filters', [
        'active' => 'applications', 'heading' => 'Applicants by Status', 'path' => '/registrar/reports/applications',
        'subtitle' => 'Every submitted application of a term. Counts cover the whole term and program; the status filter narrows the list.',
        'fields' => ['term', 'program', 'status'], 'filters' => $filters,
        'terms' => $terms, 'programs' => $programs, 'yearLevels' => [], 'sections' => [], 'statuses' => $statuses,
    ]) ?>

    <div class="grid gap-4 sm:grid-cols-2 2xl:grid-cols-4">
        <?= component('stat-card', ['label' => 'Applicants', 'value' => number_format($all), 'hint' => 'Submitted this term', 'icon' => 'inbox']) ?>
        <?= component('stat-card', ['label' => 'Waiting for review', 'value' => number_format($inQueue), 'hint' => 'Submitted or under review', 'icon' => 'clock', 'tone' => $inQueue > 0 ? 'warning' : 'neutral']) ?>
        <?= component('stat-card', ['label' => 'Returned to students', 'value' => number_format($returned), 'hint' => 'Incomplete requirements or corrections', 'icon' => 'alert', 'tone' => $returned > 0 ? 'warning' : 'neutral']) ?>
        <?= component('stat-card', ['label' => 'Approved', 'value' => number_format($report['counts']['APPROVED']), 'hint' => $all > 0 ? round($report['counts']['APPROVED'] / $all * 100) . '% of applicants' : 'None yet', 'icon' => 'check', 'tone' => 'success']) ?>
    </div>

    <section class="card" aria-labelledby="matrix-heading">
        <h3 id="matrix-heading" class="border-b border-brand-100 px-5 py-4 text-base">By program and status</h3>
        <?php if ($report['matrix'] === []): ?>
            <?= component('empty-state', ['title' => 'No applications', 'message' => 'No application has been submitted for this term and program.']) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                    <thead class="bg-brand-50 text-brand-navy">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Program</th>
                            <?php foreach ($statuses as $status): ?>
                                <th scope="col" class="px-3 py-3 text-right font-semibold"><?= e(RegistrarReport::label($status)) ?></th>
                            <?php endforeach; ?>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-50 bg-white">
                        <?php foreach ($report['matrix'] as $program => $counts): ?>
                            <tr>
                                <th scope="row" class="px-4 py-3 font-semibold text-brand-navy"><?= e($program) ?></th>
                                <?php foreach ($statuses as $status): ?>
                                    <td class="px-3 py-3 text-right tabular-nums <?= $counts[$status] === 0 ? 'text-slate-400' : '' ?>"><?= e($counts[$status]) ?></td>
                                <?php endforeach; ?>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums"><?= e(array_sum($counts)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-brand-50/50 font-semibold">
                        <tr>
                            <th scope="row" class="px-4 py-3">All programs</th>
                            <?php foreach ($statuses as $status): ?>
                                <td class="px-3 py-3 text-right tabular-nums"><?= e($report['counts'][$status]) ?></td>
                            <?php endforeach; ?>
                            <td class="px-4 py-3 text-right tabular-nums"><?= e($all) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($rows !== []): ?>
        <section class="card" aria-labelledby="applications-heading">
            <h3 id="applications-heading" class="border-b border-brand-100 px-5 py-4 text-base">Applications<?= $filters['status'] !== null ? ': ' . e(RegistrarReport::label($filters['status'])) : '' ?></h3>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                    <thead class="bg-brand-50 text-brand-navy">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Applicant</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Program</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Status</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Requirements</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Submitted</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-50 bg-white">
                        <?php foreach ($rows as $row): ?>
                            <tr class="hover:bg-brand-50/40">
                                <td class="px-4 py-3"><a href="<?= e(url('/registrar/applications/' . $row['id'])) ?>" class="font-semibold text-brand-navy hover:underline"><?= e($row['last_name'] . ', ' . $row['first_name']) ?></a><span class="block text-xs text-brand-gray"><?= e($row['application_number']) ?> · <?= e($row['student_type']) ?></span></td>
                                <td class="px-4 py-3"><?= e($row['program_code'] . ' · ' . $row['year_level_name']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3"><?= component('status-badge', ['status' => $row['application_status']]) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums"><?= e((int) $row['verified_count'] . ' of ' . (int) $row['required_count']) ?> verified</td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600"><?= e($row['submitted_at'] ? d($row['submitted_at']) : '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= partial('pagination', ['page' => $page, 'pages' => $pages, 'total' => count($report['rows']), 'path' => '/registrar/reports/applications', 'query' => array_filter($filters, static fn ($v) => $v !== null), 'noun' => 'application']) ?>
        </section>
    <?php endif; ?>
</div>
