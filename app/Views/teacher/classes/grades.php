<?php
/**
 * Grade sheet for one class (docs/12, docs/27). Entry for one period at a time;
 * every period and the computed final are shown alongside.
 *
 * @var array  $class
 * @var array  $periods
 * @var ?array $period  the period being entered
 * @var array  $sheet   ClassSchedule::gradeSheet()
 * @var array  $submissions  this class's submissions by period id
 * @var ?array $submission   the shown period's submission, when the teacher has saved anything
 * @var string $status       DRAFT, SUBMITTED, CONFIRMED, or RETURNED for the shown period
 * @var bool   $editable     whether the teacher can type in the shown period now
 */
use App\Services\GradeCalculator;

$failed = errors() !== [];
$old = (array) old('grades', []);
$open = $editable;
$statusLabel = ['DRAFT' => 'Draft', 'SUBMITTED' => 'Submitted', 'CONFIRMED' => 'Confirmed', 'RETURNED' => 'Returned'];
// What goes back in the box: a mark, or the percentage (so saving the sheet again changes nothing), or the equivalent of an old grade.
$valueOf = static function (?array $entry): string {
    if ($entry === null) {
        return '';
    }
    if (!empty($entry['remark'])) {
        return (string) $entry['remark'];
    }
    if (($entry['percentage'] ?? null) !== null) {
        return rtrim(rtrim(number_format((float) $entry['percentage'], 2, '.', ''), '0'), '.');
    }
    return (string) ($entry['grade'] ?? '');
};
?>
<div class="space-y-6">
    <a href="<?= e(url('/teacher/classes/' . $class['id'])) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; <?= e($class['code']) ?> roster</a>

    <div>
        <p class="eyebrow"><?= e($class['section_code']) ?> · <?= e($class['term_name']) ?> <?= e($class['academic_year_code']) ?></p>
        <h2 class="mt-1 text-2xl">Grade sheet · <?= e($class['code']) ?></h2>
        <p class="mt-1 text-sm text-slate-600">
            Type each grade as a <strong>percentage</strong> (such as 92.4), a grade from the <a href="<?= e(url('/grading-system')) ?>" class="font-semibold text-brand-600 hover:underline" target="_blank" rel="noopener">Official Grading System</a> (such as 1.40), or a mark: INC, OW, UW, NGA. The equivalent shows beside the box. Below 75 is 5.00, failed.
            The final is the weighted average of <?= e(implode(', ', array_map(static fn ($p) => $p['name'] . ' ' . rtrim(rtrim((string) $p['weight'], '0'), '.') . '%', $periods))) ?>.
        </p>
    </div>

    <?php if ($periods === []): ?>
        <section class="card"><?= component('empty-state', ['title' => 'This term has no grading periods yet']) ?></section>
    <?php else: ?>
        <nav aria-label="Grading period" class="flex flex-wrap gap-2">
            <?php foreach ($periods as $option): ?>
                <?php $selected = $period !== null && (int) $option['id'] === (int) $period['id']; ?>
                <a href="<?= e(url('/teacher/classes/' . $class['id'] . '/grades?period=' . $option['id'])) ?>"
                   class="inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-semibold <?= $selected ? 'border-brand-600 bg-brand-50 text-brand-navy' : 'border-slate-200 bg-white text-slate-700 hover:border-brand-sky' ?>"
                   <?= $selected ? 'aria-current="page"' : '' ?>>
                    <?= e($option['name']) ?>
                    <span class="text-xs font-normal"><?= (int) $option['is_open'] === 1 ? '(open)' : '(closed)' ?></span>
                    <?php $optionStatus = (string) (($submissions[(int) $option['id']]['status'] ?? null) ?? ''); ?>
                    <?php if ($optionStatus !== '' && $optionStatus !== 'DRAFT'): ?><span class="badge bg-brand-50 text-xs font-normal text-brand-navy"><?= e($statusLabel[$optionStatus] ?? $optionStatus) ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if ($status === 'RETURNED' && $submission !== null): ?>
            <p class="rounded-lg border border-warning-text/30 bg-warning-bg px-4 py-3 text-sm text-warning-text" role="status">
                <strong>The registrar returned these grades.</strong> Reason: <?= e((string) $submission['return_reason']) ?>. Correct them and submit again.
            </p>
        <?php endif; ?>

        <?php if (!$open): ?>
            <p class="flex items-start gap-2 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700" role="status">
                <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/></svg>
                <?php if ($status === 'SUBMITTED'): ?>
                    <span><strong><?= e($period['name']) ?> grades were submitted</strong> and are waiting for the registrar. They are locked, and students cannot see them yet.</span>
                <?php elseif ($status === 'CONFIRMED'): ?>
                    <span><strong><?= e($period['name']) ?> grades are confirmed</strong> and released to students. If one must change, ask the registrar to return them to you.</span>
                <?php else: ?>
                    <span><strong><?= e($period['name']) ?> is closed.</strong> Its grades are locked. If one must change, ask the registrar to reopen the period.</span>
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <section class="card">
            <?php if ($sheet === []): ?>
                <?= component('empty-state', ['title' => 'No students enlisted yet']) ?>
            <?php else: ?>
                <form method="POST" action="<?= e(url('/teacher/classes/' . $class['id'] . '/grades')) ?>" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="period_id" value="<?= e($period['id']) ?>">
                    <div class="table-scroll">
                        <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Student</th>
                                    <?php foreach ($periods as $column): ?>
                                        <th scope="col" class="px-4 py-3 text-center font-display font-semibold <?= (int) $column['id'] === (int) $period['id'] ? 'bg-brand-50 text-brand-navy' : 'text-brand-navy' ?>"><?= e($column['name']) ?></th>
                                    <?php endforeach; ?>
                                    <th scope="col" class="px-4 py-3 text-center font-display font-semibold text-brand-navy">Final</th>
                                    <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Remark</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($sheet as $row): ?>
                                    <?php $eid = (int) $row['enlistment_id']; $error = error_for("grades.{$eid}"); ?>
                                    <tr class="<?= $error ? 'bg-danger-bg/40' : '' ?>">
                                        <th scope="row" class="px-4 py-3 text-left font-normal">
                                            <span class="font-semibold text-brand-navy"><?= e($row['last_name'] . ', ' . $row['first_name']) ?></span>
                                            <span class="block text-xs text-brand-gray tabular-nums"><?= e($row['student_number']) ?></span>
                                            <?php if ($error): ?><span class="field-error"><span><?= e($error) ?></span></span><?php endif; ?>
                                        </th>
                                        <?php foreach ($periods as $column): ?>
                                            <?php $stored = $valueOf($row['period_grades'][(int) $column['id']] ?? null); ?>
                                            <td class="whitespace-nowrap px-4 py-3 text-center tabular-nums <?= (int) $column['id'] === (int) $period['id'] ? 'bg-brand-50/60' : '' ?>">
                                                <?php if ((int) $column['id'] === (int) $period['id'] && $open): ?>
                                                    <?php $current = $failed ? (string) ($old[$eid] ?? '') : $stored; ?>
                                                    <label for="grade-<?= $eid ?>" class="sr-only"><?= e($period['name']) ?> grade for <?= e($row['first_name'] . ' ' . $row['last_name']) ?></label>
                                                    <input id="grade-<?= $eid ?>" name="grades[<?= $eid ?>]" type="text" inputmode="decimal" autocomplete="off" maxlength="6" placeholder="—"
                                                           value="<?= e($current) ?>" data-grade-input data-preview="grade-preview-<?= $eid ?>"
                                                           class="field-input w-24 text-center <?= $error ? 'field-input-error' : '' ?>">
                                                    <span id="grade-preview-<?= $eid ?>" class="mt-1 block min-h-[1rem] text-xs text-brand-gray" aria-live="polite"></span>
                                                <?php else: ?>
                                                    <?= e(GradeCalculator::display($row['period_grades'][(int) $column['id']] ?? null)) ?>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                        <td class="whitespace-nowrap px-4 py-3 text-center font-display font-bold text-brand-navy tabular-nums"><?= e($row['final']['grade'] ?? '—') ?><?php if (($row['final']['percentage'] ?? null) !== null): ?><span class="block text-xs font-normal text-brand-gray"><?= e($row['final']['percentage']) ?>%</span><?php endif; ?></td>
                                        <td class="whitespace-nowrap px-4 py-3"><?= component('status-badge', ['status' => $row['final']['remark'], 'label' => GradeCalculator::remarkLabel($row['final']['remark'])]) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($open): ?>
                        <div class="flex flex-wrap items-center gap-3 border-t border-slate-200 px-5 py-4">
                            <button type="submit" class="btn-secondary"
                                    data-confirm-click="Every change is recorded in the audit log. You can still change them until you submit the grades to the registrar." data-confirm-title="Save <?= e($period['name']) ?> grades?" data-confirm-tone="success">Save <?= e($period['name']) ?> grades</button>
                            <button type="submit" class="btn-primary" formaction="<?= e(url('/teacher/classes/' . $class['id'] . '/grades/submit')) ?>"
                                    data-confirm-click="Your grades are saved and sent to the registrar. They are locked until the registrar confirms or returns them, and students cannot see them until then. Every student needs a grade or a mark." data-confirm-title="Submit <?= e($period['name']) ?> grades?" data-confirm-tone="primary" data-confirm-label="Submit to Registrar">Save and submit to Registrar</button>
                            <p class="text-xs text-brand-gray">A blank leaves that grade unchanged. Every change is recorded with its old and new value.</p>
                        </div>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
<script type="application/json" id="grade-scale"><?= json_encode(GradeCalculator::TABLE, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
