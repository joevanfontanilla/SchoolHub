<?php
/**
 * One submitted sheet, read-only (docs/27).
 *
 * @var array $submission
 * @var array $sheet       the enlisted students with their grade for the period
 */
use App\Services\GradeCalculator;

$status = (string) $submission['status'];
$reasonError = error_for('reason');
$statusLabel = ['DRAFT' => 'Draft', 'SUBMITTED' => 'Submitted', 'CONFIRMED' => 'Confirmed', 'RETURNED' => 'Returned'][$status] ?? $status;
$id = (int) $submission['id'];
?>
<div class="space-y-6">
    <a href="<?= e(url('/registrar/grading/submissions')) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; Grade submissions</a>

    <div>
        <p class="eyebrow">Section <?= e($submission['section_code']) ?> · <?= e($submission['teacher_name']) ?></p>
        <h2 class="mt-1 flex flex-wrap items-center gap-3 text-2xl"><?= e($submission['subject_code']) ?> · <?= e($submission['period_name']) ?> grades
            <span class="badge bg-brand-50 text-sm text-brand-navy"><?= e($statusLabel) ?></span></h2>
        <p class="mt-1 text-sm text-slate-600"><?= e($submission['subject_title']) ?>.
            <?php if ($status === 'SUBMITTED'): ?>Submitted <?= e(dt((string) $submission['submitted_at'])) ?>. Students cannot see these grades until you confirm them.
            <?php elseif ($status === 'CONFIRMED'): ?>Confirmed <?= e(dt((string) $submission['confirmed_at'])) ?>. Students can see these grades.
            <?php elseif ($status === 'RETURNED'): ?>Returned <?= e(dt((string) $submission['returned_at'])) ?>: <?= e((string) $submission['return_reason']) ?>
            <?php else: ?>The teacher is still entering these grades.<?php endif; ?>
        </p>
    </div>

    <section class="card">
        <div class="table-scroll">
            <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                <thead class="bg-slate-50 text-brand-navy">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">Student</th>
                        <th scope="col" class="px-4 py-3 text-center font-semibold">Grade</th>
                        <th scope="col" class="px-4 py-3 text-center font-semibold">Percentage</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                    <?php foreach ($sheet as $row): ?>
                        <?php $mark = (string) ($row['remark'] ?? ''); ?>
                        <tr>
                            <th scope="row" class="px-4 py-2.5 text-left font-normal"><span class="font-semibold text-brand-navy"><?= e($row['last_name'] . ', ' . $row['first_name']) ?></span><span class="block text-xs text-brand-gray"><?= e((string) $row['student_number']) ?></span></th>
                            <td class="px-4 py-2.5 text-center font-semibold tabular-nums"><?= e($mark !== '' ? $mark : ($row['grade'] ?? '—')) ?></td>
                            <td class="px-4 py-2.5 text-center tabular-nums"><?= e($row['percentage'] !== null ? rtrim(rtrim(number_format((float) $row['percentage'], 2, '.', ''), '0'), '.') . '%' : '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($status === 'SUBMITTED'): ?>
        <section class="card p-5">
            <h3 class="text-base">Confirm and release</h3>
            <p class="mt-1 text-sm text-slate-600">Students will see these grades, and each of them is told.</p>
            <form method="POST" action="<?= e(url('/registrar/grading/submissions/' . $id . '/confirm')) ?>" class="mt-3"
                  data-confirm="These grades are released to students, and each student is told. You can still return the sheet afterwards." data-confirm-title="Confirm these grades?" data-confirm-tone="success" data-confirm-label="Confirm and release">
                <?= csrf_field() ?>
                <button type="submit" class="btn-primary">Confirm and release</button>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($status === 'SUBMITTED' || $status === 'CONFIRMED'): ?>
        <section class="card p-5">
            <h3 class="text-base">Return to the teacher</h3>
            <p class="mt-1 text-sm text-slate-600">The teacher can correct the grades and submit again. <?= $status === 'CONFIRMED' ? 'Students stop seeing them until they are confirmed again.' : '' ?></p>
            <form method="POST" action="<?= e(url('/registrar/grading/submissions/' . $id . '/return')) ?>" class="mt-3 flex flex-wrap items-end gap-3" novalidate
                  data-confirm="The teacher is told, and can correct these grades and submit them again. Students cannot see them until they are confirmed again." data-confirm-title="Return these grades?" data-confirm-tone="warning" data-confirm-label="Return to teacher"
                  data-confirm-requires="#return-reason" data-confirm-requires-message="Write the reason for returning the grades first. The teacher sees it.">
                <?= csrf_field() ?>
                <div class="min-w-[16rem] flex-1">
                    <label for="return-reason" class="field-label">Reason <span class="text-danger-text" aria-hidden="true">*</span></label>
                    <input id="return-reason" name="reason" class="field-input <?= $reasonError ? 'field-input-error' : '' ?>" maxlength="500" placeholder="e.g. Two students are missing their quiz scores">
                    <?php if ($reasonError): ?><p class="field-error"><span><?= e($reasonError) ?></span></p><?php endif; ?>
                </div>
                <button type="submit" class="btn-secondary">Return to teacher</button>
            </form>
        </section>
    <?php endif; ?>
</div>