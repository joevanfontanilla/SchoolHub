<?php
/**
 * Attendance sheet for one class on one date (docs/12).
 *
 * @var array   $class
 * @var string  $date
 * @var ?string $problem  why this date cannot be recorded
 * @var array   $sheet
 * @var array   $dates    recent dates with counts
 * @var array   $statuses
 */
use App\Services\ClassScheduleService;

$failed = errors() !== [];
$oldStatus = (array) old('status', []);
$oldRemarks = (array) old('remarks', []);
$labels = ['PRESENT' => 'Present', 'ABSENT' => 'Absent', 'LATE' => 'Late', 'EXCUSED' => 'Excused'];
$maxDate = min(date('Y-m-d'), (string) $class['term_end']);
$meetingDays = implode(' and ', array_unique(array_map(static fn ($m) => ClassScheduleService::DAYS[(int) $m['day_of_week']] . 's', $class['meetings'])));
?>
<div class="space-y-6">
    <a href="<?= e(url('/teacher/classes/' . $class['id'])) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; <?= e($class['code']) ?> roster</a>

    <div>
        <p class="eyebrow"><?= e($class['section_code']) ?> · <?= e($class['term_name']) ?> <?= e($class['academic_year_code']) ?></p>
        <h2 class="mt-1 text-2xl">Attendance · <?= e($class['code']) ?></h2>
        <p class="mt-1 text-sm text-slate-600"><?= e($class['title']) ?> meets on <?= e($meetingDays ?: 'no set days') ?>.</p>
    </div>

    <form method="GET" action="<?= e(url('/teacher/classes/' . $class['id'] . '/attendance')) ?>" class="card flex flex-wrap items-end gap-4 p-4" data-auto-submit>
        <div>
            <label for="attendance-date" class="field-label">Date</label>
            <input id="attendance-date" type="date" name="date" value="<?= e($date) ?>" min="<?= e($class['term_start']) ?>" max="<?= e($maxDate) ?>" class="field-input">
        </div>
        <button type="submit" class="btn-secondary">Open sheet</button>
    </form>

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="card lg:col-span-2" aria-labelledby="sheet-heading">
            <h3 id="sheet-heading" class="border-b border-slate-200 px-5 py-4 text-base"><?= e(date('l, F j, Y', strtotime($date) ?: time())) ?></h3>

            <?php if ($problem !== null): ?>
                <p class="m-5 flex items-start gap-2 rounded-lg border border-warning-line bg-warning-bg px-4 py-3 text-sm text-warning-text" role="status">
                    <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
                    <span><strong>Attendance cannot be taken for this date.</strong> <?= e($problem) ?></span>
                </p>
            <?php elseif ($sheet === []): ?>
                <?= component('empty-state', ['title' => 'No students enlisted yet']) ?>
            <?php else: ?>
                <form method="POST" action="<?= e(url('/teacher/classes/' . $class['id'] . '/attendance')) ?>" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="date" value="<?= e($date) ?>">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-3">
                        <p class="text-sm text-slate-600">Students left unmarked are not saved.</p>
                        <button type="button" class="btn-secondary" data-mark-all="PRESENT">Mark all present</button>
                    </div>
                    <ul class="divide-y divide-slate-100">
                        <?php foreach ($sheet as $row): ?>
                            <?php
                            $eid = (int) $row['enlistment_id'];
                            $current = $failed ? ($oldStatus[$eid] ?? '') : ($row['status'] ?? '');
                            $error = error_for("attendance.{$eid}");
                            ?>
                            <li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3 <?= $error ? 'bg-danger-bg/40' : '' ?>">
                                <div class="min-w-[10rem] flex-1">
                                    <p class="font-semibold text-brand-navy"><?= e($row['last_name'] . ', ' . $row['first_name']) ?></p>
                                    <p class="text-xs text-brand-gray tabular-nums"><?= e($row['student_number']) ?></p>
                                    <?php if ($error): ?><p class="field-error"><span><?= e($error) ?></span></p><?php endif; ?>
                                </div>
                                <fieldset>
                                    <legend class="sr-only">Attendance for <?= e($row['first_name'] . ' ' . $row['last_name']) ?></legend>
                                    <div class="flex flex-wrap gap-1.5">
                                        <?php foreach ($statuses as $status): ?>
                                            <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50 has-[:checked]:font-semibold has-[:checked]:text-brand-navy">
                                                <input type="radio" name="status[<?= $eid ?>]" value="<?= e($status) ?>" class="h-4 w-4 border-slate-300 text-brand-600 focus:ring-brand-600" <?= $current === $status ? 'checked' : '' ?>>
                                                <?= e($labels[$status]) ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </fieldset>
                                <div class="w-full sm:w-52">
                                    <label for="remarks-<?= $eid ?>" class="sr-only">Remarks for <?= e($row['first_name'] . ' ' . $row['last_name']) ?></label>
                                    <input id="remarks-<?= $eid ?>" name="remarks[<?= $eid ?>]" maxlength="255" placeholder="Remarks (optional)"
                                           value="<?= e($failed ? ($oldRemarks[$eid] ?? '') : ($row['remarks'] ?? '')) ?>" class="field-input">
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="border-t border-slate-200 px-5 py-4">
                        <button type="submit" class="btn-primary">Save attendance</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <section class="card self-start" aria-labelledby="recent-heading">
            <h3 id="recent-heading" class="border-b border-slate-200 px-5 py-4 text-base">Taken so far</h3>
            <?php if ($dates === []): ?>
                <p class="px-5 py-4 text-sm text-brand-gray">No attendance recorded yet.</p>
            <?php else: ?>
                <ul class="divide-y divide-slate-100">
                    <?php foreach ($dates as $entry): ?>
                        <li class="px-5 py-3 text-sm">
                            <a href="<?= e(url('/teacher/classes/' . $class['id'] . '/attendance?date=' . $entry['attendance_date'])) ?>"
                               class="font-semibold text-brand-600 hover:underline" <?= $entry['attendance_date'] === $date ? 'aria-current="page"' : '' ?>><?= e(d($entry['attendance_date'])) ?></a>
                            <p class="text-xs text-brand-gray"><?= e($entry['present']) ?> present · <?= e($entry['absent']) ?> absent · <?= e($entry['late']) ?> late · <?= e($entry['excused']) ?> excused</p>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</div>
