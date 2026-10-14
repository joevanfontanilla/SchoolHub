<?php
/**
 * Walk-in registration, step 2 (docs/09): record the documents received in person, then submit.
 *
 * @var array              $application
 * @var App\Models\User    $applicant
 * @var array              $requirements
 * @var bool               $hasPhoto the applicant already has a profile photo
 */
$required = array_filter($requirements, static fn ($r) => (int) $r['is_required'] === 1);
$receivedRequired = array_filter($required, static fn ($r) => $r['status'] === 'VERIFIED');
?>
<div class="mx-auto max-w-3xl space-y-6">
    <div>
        <p class="eyebrow">Walk-in registration</p>
        <h2 class="mt-1 text-2xl"><?= e($applicant->fullName()) ?></h2>
        <p class="mt-1 text-sm text-slate-600">
            <?= e($application['application_number']) ?> · <?= e($application['program_code']) ?> · <?= e($application['year_level_name']) ?>
            · <?= e($application['term_name']) ?>, <?= e($application['academic_year_code']) ?>
        </p>
    </div>

    <form method="POST" action="<?= e(url('/registrar/walk-in/' . $application['id'])) ?>" enctype="multipart/form-data" class="space-y-6" novalidate>
        <?= csrf_field() ?>

        <section class="card" aria-labelledby="docs-heading">
            <div class="border-b border-slate-200 px-5 py-4">
                <h3 id="docs-heading" class="text-base">Documents received in person</h3>
                <p class="mt-0.5 text-sm text-brand-gray">
                    Tick each document the applicant handed over. A ticked document counts as verified.
                    <?= e(count($receivedRequired)) ?> of <?= e(count($required)) ?> required documents received.
                </p>
            </div>
            <ul class="divide-y divide-slate-100">
                <?php foreach ($requirements as $requirement): ?>
                    <?php $inputId = 'received-' . $requirement['id']; ?>
                    <li class="px-5 py-4">
                        <label for="<?= e($inputId) ?>" class="flex items-start gap-3">
                            <input type="checkbox" id="<?= e($inputId) ?>" name="received[]" value="<?= e($requirement['id']) ?>"
                                   class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-600"
                                   <?= $requirement['status'] === 'VERIFIED' ? 'checked' : '' ?>>
                            <span class="text-sm">
                                <span class="font-medium text-slate-800"><?= e($requirement['name']) ?></span>
                                <span class="ml-1 text-xs font-medium <?= (int) $requirement['is_required'] === 1 ? 'text-danger-text' : 'text-brand-gray' ?>">
                                    <?= (int) $requirement['is_required'] === 1 ? 'Required' : 'Optional' ?>
                                </span>
                                <?php if (!empty($requirement['description'])): ?>
                                    <span class="mt-0.5 block text-brand-gray"><?= e($requirement['description']) ?></span>
                                <?php endif; ?>
                            </span>
                        </label>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="card p-5" aria-labelledby="photo-heading">
            <h3 id="photo-heading" class="text-base">Applicant's photo</h3>
            <p class="mt-0.5 text-sm text-brand-gray">Optional. A recent photo with a plain background, used on the e-ID card. <?= $hasPhoto ? 'A photo is already saved; choosing another replaces it.' : 'There is no photo yet, so the card would show initials.' ?> Saving a photo does not tick the 2x2 photograph document above.</p>
            <div class="mt-3 flex flex-wrap items-center gap-4">
                <?php if ($hasPhoto): ?>
                    <img src="<?= e(url('/registrar/students/' . $application['student_id'] . '/photo')) ?>" alt="Saved photo of <?= e($applicant->fullName()) ?>" class="h-24 w-20 rounded-lg object-cover ring-1 ring-slate-300">
                <?php endif; ?>
                <div class="min-w-0 flex-1">
                    <label for="walkin-photo" class="field-label">Photo (JPG or PNG, up to 2 MB)</label>
                    <input id="walkin-photo" type="file" name="photo" accept="image/png,image/jpeg" class="field-input" <?= error_for('photo') ? 'aria-invalid="true"' : '' ?>>
                    <?php if (error_for('photo')): ?><p class="field-error mt-1"><?= e(error_for('photo')) ?></p><?php endif; ?>
                </div>
            </div>
        </section>

        <div class="flex flex-wrap gap-3">
            <button type="submit" name="action" value="submit" class="btn-primary btn-lg"
                    data-confirm-click="Every required document must be ticked. The application then goes straight to review, where you choose the section and approve it." data-confirm-title="Submit this walk-in?" data-confirm-tone="primary" data-confirm-label="Submit">
                Submit application
            </button>
            <button type="submit" name="action" value="save" class="btn-secondary btn-lg">Save and finish later</button>
        </div>
        <p class="text-sm text-brand-gray">Not every required document today? Choose <strong>Save and finish later</strong>. The ticks and photo are kept, and the walk-in waits under <em>Walk-ins not yet submitted</em> on the Walk-in registration page until the rest arrive.</p>
    </form>

    <section class="card space-y-4 p-5" aria-labelledby="account-heading">
        <h3 id="account-heading" class="text-base">Applicant's account</h3>
        <?php if ($applicant->status === App\Models\User::STATUS_PENDING): ?>
            <p class="text-sm text-slate-600">The applicant has not set a password yet. Issue a new link if the first one was lost; the old one stops working.</p>
            <form method="POST" action="<?= e(url('/registrar/walk-in/' . $application['id'] . '/link')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn-secondary">Issue a new set-up link</button>
            </form>
        <?php else: ?>
            <p class="text-sm text-slate-600">The applicant has set a password and can sign in.</p>
        <?php endif; ?>

        <form method="POST" action="<?= e(url('/registrar/walk-in/' . $application['id'] . '/cancel')) ?>"
              data-confirm="The draft is cancelled and cannot be reopened. The applicant's account stays." data-confirm-title="Cancel this walk-in?" data-confirm-tone="danger" data-confirm-label="Cancel walk-in">
            <?= csrf_field() ?>
            <button type="submit" class="text-sm font-semibold text-danger-text hover:underline">Cancel this walk-in</button>
        </form>
    </section>
</div>
