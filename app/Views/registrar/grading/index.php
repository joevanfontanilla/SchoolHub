<?php
/**
 * Grading periods of a term (docs/27).
 *
 * @var array  $terms
 * @var ?array $term
 * @var array  $periods
 * @var bool   $graded
 */
$failed = errors() !== [];
$weightError = error_for('weights');
?>
<div class="space-y-6">
    <div>
        <p class="eyebrow">Academic records</p>
        <h2 class="mt-1 text-2xl">Grading periods</h2>
        <p class="mt-1 max-w-3xl text-sm text-slate-600">
            Teachers can enter or correct a period's grades only while it is open. Closing a period locks its grades and tells the students.
            You cannot change grades yourself: to correct one, reopen the period and ask the teacher. Every open and close is audited.
        </p>
    </div>

    <form method="GET" action="<?= e(url('/registrar/grading')) ?>" class="card flex flex-wrap items-end gap-4 p-4" data-auto-submit>
        <div class="min-w-[14rem]">
            <label for="grading-term" class="field-label">Term</label>
            <select id="grading-term" name="term_id" class="field-input">
                <?php foreach ($terms as $option): ?>
                    <option value="<?= e($option['id']) ?>" <?= $term !== null && (int) $term['id'] === (int) $option['id'] ? 'selected' : '' ?>><?= e($option['name'] . ', ' . $option['academic_year_code']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <noscript><button type="submit" class="btn-secondary">Show</button></noscript>
    </form>

    <?php if ($term === null || $periods === []): ?>
        <section class="card"><?= component('empty-state', ['title' => 'This term has no grading periods', 'message' => 'Run php database/seed.php to give every term Prelim, Midterm, and Finals.']) ?></section>
    <?php else: ?>
        <section class="card" aria-labelledby="periods-heading">
            <h3 id="periods-heading" class="border-b border-slate-200 px-5 py-4 text-base"><?= e($term['name']) ?> periods</h3>
            <ul class="divide-y divide-slate-100">
                <?php foreach ($periods as $period): ?>
                    <?php $open = (int) $period['is_open'] === 1; ?>
                    <li class="flex flex-wrap items-center justify-between gap-4 px-5 py-4">
                        <div>
                            <p class="flex items-center gap-2 font-display font-bold text-brand-navy">
                                <?= e($period['name']) ?>
                                <?= component('status-badge', ['status' => $open ? 'OPEN' : 'CLOSED', 'label' => $open ? 'Open for grading' : 'Closed']) ?>
                            </p>
                            <p class="mt-1 text-xs text-brand-gray">
                                Weight <?= e(rtrim(rtrim((string) $period['weight'], '0'), '.')) ?>% · <?= e($period['grade_count']) ?> grade<?= (int) $period['grade_count'] === 1 ? '' : 's' ?> recorded
                                <?php if ($open && $period['opened_at']): ?> · opened <?= e(dt($period['opened_at'])) ?><?= $period['opened_by_name'] ? ' by ' . e($period['opened_by_name']) : '' ?><?php endif; ?>
                                <?php if (!$open && $period['closed_at']): ?> · closed <?= e(dt($period['closed_at'])) ?><?= $period['closed_by_name'] ? ' by ' . e($period['closed_by_name']) : '' ?><?php endif; ?>
                            </p>
                        </div>
                        <form method="POST" action="<?= e(url('/registrar/grading/periods/' . $period['id'] . '/' . ($open ? 'close' : 'open'))) ?>"
                              data-confirm="<?= $open
                                  ? e('Close ' . $period['name'] . '? Its grades lock and students with a grade are notified.')
                                  : e('Open ' . $period['name'] . '? Teachers will be able to enter and change ' . $period['name'] . ' grades. This is audited.') ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="<?= $open ? 'btn-secondary' : 'btn-primary' ?>"><?= $open ? 'Close' : ($period['closed_at'] ? 'Reopen' : 'Open') ?> <?= e($period['name']) ?></button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="card" aria-labelledby="weights-heading">
            <h3 id="weights-heading" class="border-b border-slate-200 px-5 py-4 text-base">Weights</h3>
            <?php if ($graded): ?>
                <p class="px-5 py-4 text-sm text-slate-600">Grades have been recorded for this term, so its weights are fixed. The final grade is the weighted average of the periods (docs/27).</p>
            <?php else: ?>
                <form method="POST" action="<?= e(url('/registrar/grading/terms/' . $term['id'] . '/weights')) ?>" class="space-y-4 p-5" novalidate>
                    <?= csrf_field() ?>
                    <p class="text-sm text-slate-600">Percentages must add up to 100. They can change only until the first grade is recorded.</p>
                    <?php if ($weightError): ?><p class="field-error" role="alert"><span><?= e($weightError) ?></span></p><?php endif; ?>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <?php foreach ($periods as $period): ?>
                            <div>
                                <label for="weight-<?= e($period['id']) ?>" class="field-label"><?= e($period['name']) ?> (%)</label>
                                <input id="weight-<?= e($period['id']) ?>" type="number" min="0" max="100" step="0.01" name="weights[<?= e($period['id']) ?>]"
                                       value="<?= e($failed ? ((array) old('weights', []))[$period['id']] ?? '' : rtrim(rtrim((string) $period['weight'], '0'), '.')) ?>" class="field-input">
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" class="btn-primary">Save weights</button>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
