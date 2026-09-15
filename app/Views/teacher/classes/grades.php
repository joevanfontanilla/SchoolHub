<?php
/**
 * Grade sheet for one class (docs/12, docs/27). Entry for one period at a time;
 * every period and the computed final are shown alongside.
 *
 * @var array  $class
 * @var array  $periods
 * @var ?array $period  the period being entered
 * @var array  $sheet   ClassSchedule::gradeSheet()
 * @var array  $steps   valid numeric grades
 */
use App\Services\GradeCalculator;

$failed = errors() !== [];
$old = (array) old('grades', []);
$open = $period !== null && (int) $period['is_open'] === 1;
$valueOf = static function (?array $entry): string {
    if ($entry === null) {
        return '';
    }
    return (string) ($entry['remark'] ?? $entry['grade'] ?? '');
};
?>
<div class="space-y-6">
    <a href="<?= e(url('/teacher/classes/' . $class['id'])) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; <?= e($class['code']) ?> roster</a>

    <div>
        <p class="eyebrow"><?= e($class['section_code']) ?> · <?= e($class['term_name']) ?> <?= e($class['academic_year_code']) ?></p>
        <h2 class="mt-1 text-2xl">Grade sheet · <?= e($class['code']) ?></h2>
        <p class="mt-1 text-sm text-slate-600">
            1.00 is the highest grade and 3.00 the lowest passing grade; 5.00 is failing. INC is incomplete, DRP dropped.
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
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if (!$open): ?>
            <p class="flex items-start gap-2 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700" role="status">
                <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/></svg>
                <span><strong><?= e($period['name']) ?> is closed.</strong> Its grades are locked. If one must change, ask the registrar to reopen the period.</span>
            </p>
        <?php endif; ?>

        <section class="card">
            <?php if ($sheet === []): ?>
                <?= component('empty-state', ['title' => 'No students enlisted yet']) ?>
            <?php else: ?>
                <form method="POST" action="<?= e(url('/teacher/classes/' . $class['id'] . '/grades')) ?>" novalidate
                      data-confirm="Every change is recorded in the audit log. You can still change them while <?= e($period['name']) ?> is open." data-confirm-title="Save <?= e($period['name']) ?> grades?" data-confirm-tone="success">
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
                                                    <select id="grade-<?= $eid ?>" name="grades[<?= $eid ?>]" class="field-input w-24 text-center <?= $error ? 'field-input-error' : '' ?>">
                                                        <option value="">—</option>
                                                        <?php foreach ([...$steps, GradeCalculator::INC, GradeCalculator::DRP] as $value): ?>
                                                            <option value="<?= e($value) ?>" <?= $current !== '' && $current === $value ? 'selected' : '' ?>><?= e($value) ?></option>
                                                        <?php endforeach; ?>
                                                        <?php if ($current !== '' && !in_array($current, [...$steps, 'INC', 'DRP'], true)): ?>
                                                            <option value="<?= e($current) ?>" selected><?= e($current) ?> (not valid)</option>
                                                        <?php endif; ?>
                                                    </select>
                                                <?php else: ?>
                                                    <?= e($stored === '' ? '—' : $stored) ?>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                        <td class="whitespace-nowrap px-4 py-3 text-center font-display font-bold text-brand-navy tabular-nums"><?= e($row['final']['grade'] ?? '—') ?></td>
                                        <td class="whitespace-nowrap px-4 py-3"><?= component('status-badge', ['status' => $row['final']['remark'], 'label' => GradeCalculator::remarkLabel($row['final']['remark'])]) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($open): ?>
                        <div class="flex flex-wrap items-center gap-3 border-t border-slate-200 px-5 py-4">
                            <button type="submit" class="btn-primary">Save <?= e($period['name']) ?> grades</button>
                            <p class="text-xs text-brand-gray">A blank leaves that grade unchanged. Every change is recorded with its old and new value.</p>
                        </div>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
