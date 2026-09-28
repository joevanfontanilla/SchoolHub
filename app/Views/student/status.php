<?php
/**
 * docs/08 "Status": where the application in progress stands.
 *
 * @var array $application
 * @var array $history
 */
?>
<div class="mx-auto max-w-4xl space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">Student</p>
            <h2 class="mt-1 text-2xl">Enrollment status</h2>
        </div>
        <div class="flex items-center gap-2">
            <?= partial('print-button') ?>
            <a href="<?= e(url('/student/enrollment/' . $application['id'])) ?>" class="btn-secondary">Open application</a>
        </div>
    </div>

    <?= partial('enrollment/overview', ['application' => $application]) ?>
    <?= partial('enrollment/timeline', ['history' => $history]) ?>
</div>
