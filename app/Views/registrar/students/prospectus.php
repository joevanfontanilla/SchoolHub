<?php
/**
 * Registrar → student → Curriculum Checklist (docs/28): the student's curriculum with each
 * subject's status, for advising and enrollment planning.
 *
 * @var array      $student
 * @var array|null $prospectus
 */
$name = trim($student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name']);
?>
<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="<?= e(url('/registrar/students/' . $student['id'])) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; <?= e($name) ?></a>
            <h2 class="mt-1 text-2xl">Curriculum Checklist</h2>
            <p class="mt-1 text-sm text-slate-600"><?= e($student['student_number'] ?? 'No student number yet') ?></p>
        </div>
        <?= partial('print-button') ?>
    </div>

    <?= partial('prospectus', ['prospectus' => $prospectus]) ?>
</div>
