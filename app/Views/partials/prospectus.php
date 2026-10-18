<?php
/**
 * Curriculum checklist / prospectus (docs/28), shared by the student and registrar pages.
 *
 * @var array|null $prospectus  from AcademicRecordService::prospectus(), or null when the
 *                              student has no curriculum
 */
if ($prospectus === null) {
    echo component('empty-state', ['title' => 'No curriculum assigned yet', 'message' => 'A curriculum is set when the student is enrolled into a program. The checklist appears here after that.']);
    return;
}

$statusMeta = [
    'PASSED'      => ['Passed', 'bg-success-bg text-success-text'],
    'CREDITED'    => ['Credited', 'bg-brand-50 text-brand-700'],
    'IN_PROGRESS' => ['In progress', 'bg-warning-bg text-warning-text'],
    'FAILED'      => ['Failed', 'bg-danger-bg text-danger-text'],
    'INC'         => ['Incomplete', 'bg-warning-bg text-warning-text'],
    'OW'          => ['Officially withdrawn', 'bg-slate-100 text-slate-600'],
    'UW'          => ['Unofficially withdrawn', 'bg-slate-100 text-slate-600'],
    'NGA'         => ['No grade due to absences', 'bg-warning-bg text-warning-text'],
    'NOT_TAKEN'   => ['Not yet taken', 'bg-slate-100 text-slate-500'],
];
$c = $prospectus['curriculum'];
?>
<div class="space-y-6">
    <section class="card p-5 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="eyebrow"><?= e($c['program_code'] ?? '') ?> · <?= e($c['code'] ?? '') ?></p>
                <h3 class="mt-1 text-lg font-bold text-brand-navy"><?= e($c['name'] ?? 'Curriculum') ?></h3>
            </div>
            <div class="text-right">
                <p class="text-xs uppercase tracking-wide text-slate-500">GWA</p>
                <p class="font-display text-2xl font-extrabold tabular-nums text-brand-navy"><?= e($prospectus['gwa'] ?? '—') ?></p>
            </div>
        </div>

        <div class="mt-4">
            <div class="flex items-center justify-between text-sm">
                <span class="font-medium text-brand-navy"><?= e(rtrim(rtrim(number_format($prospectus['earned'], 1), '0'), '.')) ?> of <?= e(rtrim(rtrim(number_format($prospectus['total'], 1), '0'), '.')) ?> units earned</span>
                <span class="font-semibold text-brand-600"><?= (int) $prospectus['percent'] ?>%</span>
            </div>
            <?= component('progress-bar', ['percent' => (int) $prospectus['percent'], 'label' => 'Curriculum progress']) ?>
        </div>

        <ul class="mt-4 flex flex-wrap gap-2 text-xs">
            <?php foreach (['PASSED', 'CREDITED', 'IN_PROGRESS', 'FAILED', 'INC', 'NOT_TAKEN'] as $key): ?>
                <?php if (($prospectus['counts'][$key] ?? 0) > 0): ?>
                    <li class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 font-medium <?= e($statusMeta[$key][1]) ?>"><?= e($statusMeta[$key][0]) ?> · <?= (int) $prospectus['counts'][$key] ?></li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>
    </section>

    <?php foreach ($prospectus['groups'] as $gi => $group): ?>
        <section class="card overflow-hidden" aria-labelledby="pros-<?= (int) $gi ?>">
            <h3 id="pros-<?= (int) $gi ?>" class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-brand-50 px-5 py-3">
                <span class="font-display text-base font-bold text-brand-navy"><?= e($group['year_name']) ?> · <?= e($group['term_name']) ?></span>
                <span class="text-xs font-medium text-brand-gray"><?= e(rtrim(rtrim(number_format((float) $group['units'], 1), '0'), '.')) ?> units</span>
            </h3>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="px-5 py-2.5 font-semibold">Code</th>
                            <th scope="col" class="px-5 py-2.5 font-semibold">Subject</th>
                            <th scope="col" class="px-5 py-2.5 text-center font-semibold">Units</th>
                            <th scope="col" class="px-5 py-2.5 font-semibold">Status</th>
                            <th scope="col" class="px-5 py-2.5 text-center font-semibold">Grade</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-50">
                        <?php foreach ($group['subjects'] as $subject): ?>
                            <?php $meta = $statusMeta[$subject['status']] ?? $statusMeta['NOT_TAKEN']; ?>
                            <tr>
                                <td class="whitespace-nowrap px-5 py-2.5 font-medium text-brand-navy"><?= e($subject['code']) ?></td>
                                <td class="px-5 py-2.5">
                                    <?= e($subject['title']) ?>
                                    <?php if (!empty($subject['prerequisite_codes'])): ?><span class="block text-xs text-brand-gray">Prereq: <?= e($subject['prerequisite_codes']) ?></span><?php endif; ?>
                                </td>
                                <td class="whitespace-nowrap px-5 py-2.5 text-center tabular-nums"><?= e(rtrim(rtrim(number_format((float) $subject['units'], 1), '0'), '.')) ?></td>
                                <td class="px-5 py-2.5"><span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium <?= e($meta[1]) ?>"><?= e($meta[0]) ?></span></td>
                                <td class="whitespace-nowrap px-5 py-2.5 text-center font-semibold tabular-nums"><?= e($subject['final_grade'] ?? ($subject['status'] === 'CREDITED' ? 'CR' : '—')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endforeach; ?>
</div>
