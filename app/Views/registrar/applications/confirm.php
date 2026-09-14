<?php
/**
 * Confirm enrollment: choose a section (docs/26 section 4).
 *
 * @var array   $application
 * @var array   $student
 * @var array   $sections  matching active sections with enrolled_count
 * @var ?string $paymentProblem
 * @var string  $policyLabel
 */
$id = (int) $application['id'];
$blocked = $paymentProblem !== null;
$selected = (int) old('section_id', 0);
?>
<div class="mx-auto max-w-3xl space-y-6">
    <a href="<?= e(url("/registrar/applications/{$id}")) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; Back to the application</a>

    <div>
        <p class="eyebrow">Application <?= e($application['application_number']) ?></p>
        <h2 class="mt-1 text-2xl">Confirm enrollment</h2>
        <p class="mt-1 text-sm text-slate-600">
            <?= e(trim($student['first_name'] . ' ' . $student['last_name'])) ?> ·
            <?= e($application['program_code']) ?> · <?= e($application['year_level_name']) ?> ·
            <?= e($application['term_name']) ?>, A.Y. <?= e($application['academic_year_code']) ?>
        </p>
    </div>

    <section class="card p-5" aria-labelledby="policy-heading">
        <h2 id="policy-heading" class="text-base">Payment</h2>
        <p class="mt-1 text-sm text-slate-600">School policy: <?= e($policyLabel) ?>.</p>
        <div class="mt-3 flex items-center gap-2">
            <span class="text-sm text-slate-700">Payment status:</span>
            <?= component('status-badge', ['status' => $application['payment_status']]) ?>
        </div>
        <?php if ($blocked): ?>
            <p class="mt-3 rounded-lg border border-warning-line bg-warning-bg px-4 py-3 text-sm text-warning-text" role="note">
                <?= e($paymentProblem) ?>
            </p>
        <?php else: ?>
            <p class="mt-3 rounded-lg border border-success-line bg-success-bg px-4 py-3 text-sm text-success-text">
                The payment policy is satisfied. This application can be confirmed.
            </p>
        <?php endif; ?>
    </section>

    <form method="POST" action="<?= e(url("/registrar/applications/{$id}/confirm")) ?>" class="card"
          data-confirm="Confirm this enrollment? <?= $student['student_number'] === null ? 'A student number will be issued.' : '' ?>">
        <?= csrf_field() ?>
        <fieldset>
            <legend class="w-full border-b border-slate-200 px-5 py-4 font-display text-base font-bold text-brand-navy">Section</legend>

            <?php if ($sections === []): ?>
                <?= component('empty-state', [
                    'title'   => 'No section matches this application',
                    'message' => 'Create an active ' . $application['program_code'] . ' ' . $application['year_level_name'] . ' section for ' . $application['term_name'] . ' first.',
                ]) ?>
                <div class="pb-6 text-center">
                    <a href="<?= e(url('/registrar/sections/create')) ?>" class="btn-secondary">Create a section</a>
                </div>
            <?php else: ?>
                <?php if ($error = error_for('section_id')): ?>
                    <p class="field-error px-5 pt-4" role="alert"><span><?= e($error) ?></span></p>
                <?php endif; ?>
                <ul class="divide-y divide-slate-100">
                    <?php foreach ($sections as $section): ?>
                        <?php
                        $full = (int) $section['enrolled_count'] >= (int) $section['capacity'];
                        $inputId = 'section-' . $section['id'];
                        ?>
                        <li>
                            <label for="<?= e($inputId) ?>" class="flex cursor-pointer items-center gap-4 px-5 py-4 hover:bg-brand-50/50 <?= $full ? 'cursor-not-allowed opacity-60' : '' ?>">
                                <input type="radio" id="<?= e($inputId) ?>" name="section_id" value="<?= e($section['id']) ?>"
                                       class="h-4 w-4 border-slate-300 text-brand-600 focus:ring-brand-600"
                                       <?= $full ? 'disabled' : '' ?> <?= $selected === (int) $section['id'] ? 'checked' : '' ?> required>
                                <span class="flex-1">
                                    <span class="block font-display font-bold text-brand-navy"><?= e($section['code']) ?></span>
                                    <span class="block text-xs text-brand-gray">Adviser: <?= e($section['adviser_name'] ?: 'Not assigned') ?></span>
                                </span>
                                <span class="text-right text-sm <?= $full ? 'font-semibold text-danger-text' : 'text-slate-700' ?>">
                                    <?= e($section['enrolled_count']) ?> / <?= e($section['capacity']) ?> enrolled
                                    <?php if ($full): ?><span class="block text-xs">Full</span><?php endif; ?>
                                </span>
                            </label>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </fieldset>

        <div class="flex flex-wrap items-center gap-3 border-t border-slate-200 px-5 py-4">
            <button type="submit" class="btn-primary" <?= ($blocked || $sections === []) ? 'disabled aria-describedby="confirm-blocked"' : '' ?>>
                Confirm enrollment
            </button>
            <?php if ($blocked || $sections === []): ?>
                <p id="confirm-blocked" class="text-sm text-brand-gray">
                    <?= $blocked ? 'Confirmation opens once the payment policy is met.' : 'A matching section is needed first.' ?>
                </p>
            <?php endif; ?>
        </div>
    </form>
</div>
