<?php
/**
 * docs/08 "Requirements": documents for the application in progress.
 *
 * @var array $application
 * @var array $requirements
 * @var bool  $canUpload
 */
?>
<div class="mx-auto max-w-4xl space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">Application <?= e($application['application_number']) ?></p>
            <h2 class="mt-1 text-2xl">Requirements</h2>
            <p class="mt-1 text-sm text-slate-600">
                <?= e($application['term_name']) ?>, A.Y. <?= e($application['academic_year_code']) ?> · <?= e($application['program_code']) ?>
            </p>
        </div>
        <a href="<?= e(url('/student/enrollment/' . $application['id'])) ?>" class="btn-secondary">Open application</a>
    </div>

    <?= partial('enrollment/requirements-list', [
        'application'  => $application,
        'requirements' => $requirements,
        'canUpload'    => $canUpload,
        'returnTo'     => 'requirements',
    ]) ?>
</div>
