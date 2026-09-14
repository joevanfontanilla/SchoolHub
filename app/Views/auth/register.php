<?php
/**
 * Student sign-up (docs/09 step 1).
 *
 * @var string $honeypot the honeypot field name
 */
$schoolName = $school['school_name'] ?? 'the school';
?>
<div class="card p-6 sm:p-8">
    <h1 class="text-xl">Create your student account</h1>
    <p class="mt-1.5 text-sm text-slate-600">
        Use your own email address. You will use it to sign in and follow your application.
    </p>

    <form method="POST" action="<?= e(url('/register')) ?>" class="mt-6 space-y-5" novalidate>
        <?= csrf_field() ?>

        <!-- Bot trap: hidden from people and from screen readers. Leave it empty. -->
        <div class="absolute -left-[9999px] h-px w-px overflow-hidden" aria-hidden="true">
            <label for="field-<?= e($honeypot) ?>">Website</label>
            <input type="text" id="field-<?= e($honeypot) ?>" name="<?= e($honeypot) ?>" value="" tabindex="-1" autocomplete="off">
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
            <?= component('field', ['name' => 'first_name', 'label' => 'First name', 'required' => true, 'autocomplete' => 'given-name']) ?>
            <?= component('field', ['name' => 'last_name', 'label' => 'Last name', 'required' => true, 'autocomplete' => 'family-name']) ?>
        </div>

        <?= component('field', ['name' => 'middle_name', 'label' => 'Middle name', 'autocomplete' => 'additional-name', 'help' => 'Leave blank if you have none.']) ?>

        <?= component('field', ['name' => 'email', 'label' => 'Email address', 'type' => 'email', 'required' => true, 'autocomplete' => 'email']) ?>

        <?= component('field', [
            'name' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true,
            'autocomplete' => 'new-password', 'value' => '', 'help' => 'At least 8 characters, with a letter and a number.',
        ]) ?>

        <?= component('field', [
            'name' => 'password_confirmation', 'label' => 'Confirm password', 'type' => 'password', 'required' => true,
            'autocomplete' => 'new-password', 'value' => '',
        ]) ?>

        <div>
            <details class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                <summary class="cursor-pointer font-display font-semibold text-brand-navy">Data privacy notice</summary>
                <div class="mt-2 space-y-2">
                    <p>
                        <?= e($schoolName) ?> collects the personal information and documents you provide to process
                        your enrollment, keep your academic and financial records, and contact you about your application.
                    </p>
                    <p>
                        Your uploaded documents are visible only to you, the registrar's office, and the system administrator.
                        They are never published. Records are kept as required for school records under the
                        Data Privacy Act of 2012 (RA 10173), and you may ask the registrar to review or correct them.
                    </p>
                </div>
            </details>

            <?php $consentError = error_for('privacy_consent'); ?>
            <label class="mt-3 flex items-start gap-3 text-sm text-slate-700">
                <input type="checkbox" name="privacy_consent" value="1"
                       class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-600"
                       <?= old('privacy_consent') ? 'checked' : '' ?>
                       <?= $consentError ? 'aria-invalid="true" aria-describedby="privacy-consent-error"' : '' ?>
                       required>
                <span>I have read the data privacy notice and agree to the processing of my personal information. <span class="text-danger-text" aria-hidden="true">*</span></span>
            </label>
            <?php if ($consentError): ?>
                <p id="privacy-consent-error" class="field-error"><?= e($consentError) ?></p>
            <?php endif; ?>
        </div>

        <button type="submit" class="btn-primary w-full">Create account</button>
    </form>

    <p class="mt-6 border-t border-slate-200 pt-5 text-sm text-slate-600">
        Already have an account?
        <a href="<?= e(url('/login')) ?>" class="font-semibold text-brand-600 hover:underline">Sign in</a>
    </p>
</div>
