<?php
/**
 * One application: overview, documents, actions, and timeline (docs/09, docs/26).
 *
 * Buttons are rendered from EnrollmentStatusService::availableTargets(), but
 * that is only a convenience — every action is re-checked on the server.
 *
 * @var array $application
 * @var array $requirements
 * @var array $history
 * @var array $targets
 * @var bool  $canEdit
 * @var bool  $canUpload
 * @var array $missingProfile
 * @var array $programs
 * @var array $yearLevels
 */
use App\Models\EnrollmentApplication as Application;

$id = (int) $application['id'];
$canSubmit = in_array(Application::SUBMITTED, $targets, true);
$canCancel = in_array(Application::CANCELLED, $targets, true);
$missingRequired = array_values(array_filter(
    $requirements,
    static fn ($r) => (int) $r['is_required'] === 1 && !in_array($r['status'], ['SUBMITTED', 'UNDER_REVIEW', 'VERIFIED'], true)
));
$readyToSubmit = $canSubmit && $missingProfile === [] && $missingRequired === [];

$nextStep = match ($application['application_status']) {
    Application::DRAFT                   => 'Upload your required documents, then submit the application.',
    Application::SUBMITTED               => 'Your application is waiting for the registrar. You will see every update here.',
    Application::UNDER_REVIEW            => 'The registrar is reviewing your application and documents.',
    Application::REQUIREMENTS_INCOMPLETE => 'The registrar needs new copies of some documents. Replace them below, then submit again.',
    Application::CORRECTION_REQUIRED     => 'The registrar asked for a correction. Update the details below, then submit again.',
    Application::APPROVED                => 'Approved. The cashier will issue your fee assessment next.',
    Application::REJECTED                => 'This application was not approved. See the registrar\'s note below.',
    Application::CANCELLED               => 'This application was cancelled.',
    default                              => '',
};
?>
<div class="mx-auto max-w-5xl space-y-6">
    <a href="<?= e(url('/student/enrollment')) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">
        &larr; All applications
    </a>

    <?= partial('enrollment/overview', ['application' => $application]) ?>

    <?php if ($nextStep !== ''): ?>
        <p class="rounded-xl border border-brand-200 bg-brand-50 px-5 py-4 text-sm text-brand-navy">
            <span class="font-semibold">Next step:</span> <?= e($nextStep) ?>
        </p>
    <?php endif; ?>

    <?php if ($missingProfile !== [] && ($canEdit || $canUpload)): ?>
        <div class="rounded-xl border border-warning-line bg-warning-bg px-5 py-4 text-sm text-warning-text" role="note">
            <p><span class="font-semibold">Your profile is incomplete.</span> Still needed: <?= e(implode(', ', $missingProfile)) ?>.</p>
            <a href="<?= e(url('/student/profile')) ?>" class="mt-2 inline-block font-semibold underline">Complete my profile</a>
        </div>
    <?php endif; ?>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <?php if ($canEdit): ?>
                <details class="card group" <?= errors() !== [] ? 'open' : '' ?>>
                    <summary class="flex cursor-pointer items-center justify-between px-5 py-4 font-display text-base font-bold text-brand-navy">
                        Edit application details
                        <span class="text-sm font-semibold text-brand-600 group-open:hidden">Show</span>
                    </summary>
                    <form method="POST" action="<?= e(url("/student/enrollment/{$id}/update")) ?>" class="space-y-5 border-t border-slate-200 px-5 py-5" novalidate>
                        <?= csrf_field() ?>
                        <div class="grid gap-5 sm:grid-cols-2">
                            <div>
                                <label for="field-program_id" class="field-label">Program</label>
                                <select id="field-program_id" name="program_id" class="field-input">
                                    <?php foreach ($programs as $program): ?>
                                        <option value="<?= e($program['id']) ?>" <?= (int) old('program_id', $application['program_id']) === (int) $program['id'] ? 'selected' : '' ?>>
                                            <?= e($program['code'] . ' · ' . $program['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="field-year_level_id" class="field-label">Year level</label>
                                <select id="field-year_level_id" name="year_level_id" class="field-input">
                                    <?php foreach ($yearLevels as $level): ?>
                                        <option value="<?= e($level['id']) ?>" <?= (int) old('year_level_id', $application['year_level_id']) === (int) $level['id'] ? 'selected' : '' ?>>
                                            <?= e($level['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <?php if ($application['student_type'] === 'Transferee'): ?>
                            <div class="grid gap-5 sm:grid-cols-2">
                                <?= component('field', ['name' => 'previous_school', 'label' => 'School you are transferring from', 'required' => true, 'value' => old('previous_school', $application['previous_school'] ?? '')]) ?>
                                <?= component('field', ['name' => 'last_school_year', 'label' => 'Last school year attended', 'value' => old('last_school_year', $application['last_school_year'] ?? '')]) ?>
                            </div>
                        <?php endif; ?>
                        <p class="text-xs text-brand-gray">
                            The term and student type are fixed. To change them, cancel this application and start a new one.
                        </p>
                        <button type="submit" class="btn-secondary">Save details</button>
                    </form>
                </details>
            <?php endif; ?>

            <?= partial('enrollment/requirements-list', [
                'application'  => $application,
                'requirements' => $requirements,
                'canUpload'    => $canUpload,
                'returnTo'     => 'application',
            ]) ?>
        </div>

        <div class="space-y-6">
            <?php if ($canSubmit || $canCancel): ?>
                <section class="card p-5" aria-labelledby="actions-heading">
                    <h2 id="actions-heading" class="text-base">Actions</h2>

                    <?php if ($canSubmit): ?>
                        <ul class="mt-3 space-y-2 text-sm">
                            <li class="flex items-start gap-2">
                                <?= $missingProfile === [] ? '<span class="font-bold text-success-text" aria-hidden="true">✓</span>' : '<span class="font-bold text-warning-text" aria-hidden="true">!</span>' ?>
                                <span><?= $missingProfile === [] ? 'Profile complete' : 'Profile incomplete' ?></span>
                            </li>
                            <li class="flex items-start gap-2">
                                <?= $missingRequired === [] ? '<span class="font-bold text-success-text" aria-hidden="true">✓</span>' : '<span class="font-bold text-warning-text" aria-hidden="true">!</span>' ?>
                                <span>
                                    <?= $missingRequired === []
                                        ? 'All required documents uploaded'
                                        : e(count($missingRequired)) . ' required document' . (count($missingRequired) === 1 ? '' : 's') . ' still missing' ?>
                                </span>
                            </li>
                        </ul>

                        <form method="POST" action="<?= e(url("/student/enrollment/{$id}/submit")) ?>" class="mt-4"
                              data-confirm="Submit this application to the registrar? You won't be able to change your documents while it is being reviewed.">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn-primary w-full" <?= $readyToSubmit ? '' : 'aria-describedby="submit-hint"' ?>>
                                <?= $application['application_status'] === Application::DRAFT ? 'Submit application' : 'Submit again' ?>
                            </button>
                            <?php if (!$readyToSubmit): ?>
                                <p id="submit-hint" class="mt-2 text-xs text-brand-gray">
                                    The checklist above shows what is still missing. If you submit now, you'll be told exactly what to add.
                                </p>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>

                    <?php if ($canCancel): ?>
                        <form method="POST" action="<?= e(url("/student/enrollment/{$id}/cancel")) ?>" class="mt-5 border-t border-slate-200 pt-4"
                              data-confirm="Cancel this application? This cannot be undone. You can start a new application afterwards.">
                            <?= csrf_field() ?>
                            <label for="cancel-remarks" class="field-label">Reason for cancelling (optional)</label>
                            <textarea id="cancel-remarks" name="remarks" rows="2" maxlength="500" class="field-input"></textarea>
                            <button type="submit" class="btn mt-3 w-full border border-danger-line bg-white text-danger-text hover:bg-danger-bg">
                                Cancel application
                            </button>
                        </form>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?= partial('enrollment/timeline', ['history' => $history]) ?>
        </div>
    </div>
</div>
