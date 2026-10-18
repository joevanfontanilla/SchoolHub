<?php
/**
 * Grade submissions (docs/27): the sheets teachers sent, waiting for the registrar's confirmation.
 *
 * @var string $status       the filter
 * @var array  $submissions
 * @var int    $waiting      how many are submitted right now
 */
$labels = ['SUBMITTED' => 'Submitted', 'RETURNED' => 'Returned', 'CONFIRMED' => 'Confirmed', 'DRAFT' => 'Drafts'];
$when = static fn (array $row): string => dt((string) ($row['submitted_at'] ?? $row['confirmed_at'] ?? $row['returned_at'] ?? ''));
?>
<div class="space-y-6">
    <div>
        <p class="eyebrow">Academic records</p>
        <h2 class="mt-1 text-2xl">Grade submissions</h2>
        <p class="mt-1 max-w-3xl text-sm text-slate-600">
            Students see a grade only after you confirm it. Open a sheet to review it, then confirm it (it is released and the students are told) or return it to the teacher with a reason.
        </p>
    </div>

    <nav aria-label="Status" class="flex flex-wrap gap-2">
        <?php foreach ($labels as $key => $label): ?>
            <a href="<?= e(url('/registrar/grading/submissions?status=' . $key)) ?>"
               class="inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-semibold <?= $status === $key ? 'border-brand-600 bg-brand-50 text-brand-navy' : 'border-slate-200 bg-white text-slate-700 hover:border-brand-sky' ?>"
               <?= $status === $key ? 'aria-current="page"' : '' ?>>
                <?= e($label) ?>
                <?php if ($key === 'SUBMITTED' && $waiting > 0): ?><span class="badge bg-warning-bg text-warning-text"><?= e((string) $waiting) ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <section class="card">
        <?php if ($submissions === []): ?>
            <?= component('empty-state', ['title' => $status === 'SUBMITTED' ? 'Nothing is waiting for you' : 'Nothing here', 'message' => $status === 'SUBMITTED' ? 'When a teacher submits a class\'s grades, it appears here.' : '']) ?>
        <?php else: ?>
            <form method="POST" action="<?= e(url('/registrar/grading/submissions/confirm')) ?>" novalidate>
                <?= csrf_field() ?>
                <div class="table-scroll">
                    <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                        <thead class="bg-slate-50 text-brand-navy">
                            <tr>
                                <?php if ($status === 'SUBMITTED'): ?><th scope="col" class="w-10 px-4 py-3"><span class="sr-only">Select</span></th><?php endif; ?>
                                <th scope="col" class="px-4 py-3 font-semibold">Class</th>
                                <th scope="col" class="px-4 py-3 font-semibold">Teacher</th>
                                <th scope="col" class="px-4 py-3 font-semibold">Period</th>
                                <th scope="col" class="px-4 py-3 text-right font-semibold">Grades</th>
                                <th scope="col" class="px-4 py-3 font-semibold">When</th>
                                <th scope="col" class="px-4 py-3"><span class="sr-only">Open</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <?php foreach ($submissions as $row): ?>
                                <tr>
                                    <?php if ($status === 'SUBMITTED'): ?>
                                        <td class="px-4 py-3"><input type="checkbox" name="ids[]" value="<?= e($row['id']) ?>" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-600" aria-label="Select <?= e($row['subject_code'] . ' ' . $row['period_name']) ?>"></td>
                                    <?php endif; ?>
                                    <td class="px-4 py-3"><span class="font-semibold text-brand-navy"><?= e($row['subject_code']) ?></span> <?= e($row['subject_title']) ?><span class="block text-xs text-brand-gray">Section <?= e($row['section_code']) ?></span></td>
                                    <td class="px-4 py-3"><?= e($row['teacher_name']) ?></td>
                                    <td class="px-4 py-3"><?= e($row['period_name']) ?></td>
                                    <td class="px-4 py-3 text-right tabular-nums"><?= e((string) $row['grade_count']) ?></td>
                                    <td class="whitespace-nowrap px-4 py-3 text-xs text-brand-gray"><?= e($when($row)) ?></td>
                                    <td class="px-4 py-3 text-right"><a href="<?= e(url('/registrar/grading/submissions/' . $row['id'])) ?>" class="font-semibold text-brand-600 hover:underline">Review<span class="sr-only"> <?= e($row['subject_code'] . ' ' . $row['period_name']) ?></span></a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($status === 'SUBMITTED'): ?>
                    <div class="border-t border-slate-200 px-5 py-4">
                        <button type="submit" class="btn-primary"
                                data-confirm-click="Every ticked sheet is released to students, and they are told. You can still return one afterwards." data-confirm-title="Confirm the ticked sheets?" data-confirm-tone="success" data-confirm-label="Confirm and release">Confirm the ticked sheets</button>
                        <p class="mt-2 text-xs text-brand-gray">Review a sheet first if you want to check its grades.</p>
                    </div>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </section>
</div>