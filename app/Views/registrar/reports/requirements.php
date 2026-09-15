<?php
/**
 * Requirements status (docs/15): each document requirement by status for a term's live
 * applications, and every application still missing a required document.
 *
 * @var array $filters
 * @var array $terms
 * @var array $programs
 * @var array $statuses
 * @var array $report  types, outstanding, applications, complete
 * @var array $rows    this page's applications with missing documents
 * @var int   $page
 * @var int   $pages
 */
use App\Models\RegistrarReport;

$missing = count($report['outstanding']);
?>
<div class="space-y-6">
    <?= partial('registrar/report-filters', [
        'active' => 'requirements', 'heading' => 'Requirements Status', 'path' => '/registrar/reports/requirements',
        'subtitle' => 'Documents of the term\'s applications that are still in progress (not rejected or cancelled).',
        'fields' => ['term', 'program'], 'filters' => $filters,
        'terms' => $terms, 'programs' => $programs, 'yearLevels' => [], 'sections' => [], 'statuses' => [],
    ]) ?>

    <div class="grid gap-4 sm:grid-cols-2 2xl:grid-cols-3">
        <?= component('stat-card', ['label' => 'Applications', 'value' => number_format($report['applications']), 'hint' => 'In progress this term', 'icon' => 'inbox']) ?>
        <?= component('stat-card', ['label' => 'All required documents verified', 'value' => number_format($report['complete']), 'hint' => $report['applications'] > 0 ? round($report['complete'] / $report['applications'] * 100) . '% of applications' : 'None yet', 'icon' => 'check', 'tone' => 'success']) ?>
        <?= component('stat-card', ['label' => 'Still missing documents', 'value' => number_format($missing), 'hint' => $missing === 0 ? 'Nothing outstanding' : 'Listed below', 'icon' => 'alert', 'tone' => $missing > 0 ? 'warning' : 'neutral']) ?>
    </div>

    <section class="card" aria-labelledby="types-heading">
        <h3 id="types-heading" class="border-b border-brand-100 px-5 py-4 text-base">By requirement</h3>
        <?php if ($report['types'] === []): ?>
            <?= component('empty-state', ['title' => 'No requirements', 'message' => 'No application in this selection has document requirements.']) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                    <thead class="bg-brand-50 text-brand-navy">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Requirement</th>
                            <?php foreach ($statuses as $status): ?>
                                <th scope="col" class="px-3 py-3 text-right font-semibold"><?= e(RegistrarReport::label($status)) ?></th>
                            <?php endforeach; ?>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-50 bg-white">
                        <?php foreach ($report['types'] as $type): ?>
                            <tr>
                                <th scope="row" class="px-4 py-3 font-semibold text-brand-navy"><?= e($type['name']) ?><?php if ($type['required'] < $type['total']): ?><span class="block text-xs font-normal text-brand-gray">Optional for <?= e($type['total'] - $type['required']) ?></span><?php endif; ?></th>
                                <?php foreach ($statuses as $status): ?>
                                    <td class="px-3 py-3 text-right tabular-nums <?= $type['counts'][$status] === 0 ? 'text-slate-400' : '' ?>"><?= e($type['counts'][$status]) ?></td>
                                <?php endforeach; ?>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums"><?= e($type['total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($rows !== []): ?>
        <section class="card" aria-labelledby="missing-heading">
            <h3 id="missing-heading" class="border-b border-brand-100 px-5 py-4 text-base">Applications missing a required document</h3>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                    <thead class="bg-brand-50 text-brand-navy">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Applicant</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Program</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Application</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Not yet verified</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-50 bg-white">
                        <?php foreach ($rows as $row): ?>
                            <tr class="align-top hover:bg-brand-50/40">
                                <td class="px-4 py-3"><a href="<?= e(url('/registrar/applications/' . $row['id'])) ?>" class="font-semibold text-brand-navy hover:underline"><?= e($row['last_name'] . ', ' . $row['first_name']) ?></a><span class="block text-xs text-brand-gray"><?= e($row['application_number']) ?></span></td>
                                <td class="px-4 py-3"><?= e($row['program_code'] . ' · ' . $row['year_level_name']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3"><?= component('status-badge', ['status' => $row['application_status']]) ?></td>
                                <td class="px-4 py-3">
                                    <ul class="space-y-1">
                                        <?php foreach ($row['missing'] as $item): ?>
                                            <li><?= e($item['name']) ?> <span class="text-xs text-brand-gray">(<?= e(strtolower(RegistrarReport::label($item['status']))) ?>)</span></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= partial('pagination', ['page' => $page, 'pages' => $pages, 'total' => $missing, 'path' => '/registrar/reports/requirements', 'query' => array_filter($filters, static fn ($v) => $v !== null), 'noun' => 'application']) ?>
        </section>
    <?php endif; ?>
</div>
