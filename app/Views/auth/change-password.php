<?php
/** Password change (docs/07). Also the forced first-login screen. */
?>
<div class="mx-auto max-w-lg">
    <div class="card p-6 sm:p-8">
        <h2 class="text-lg font-semibold text-brand-navy">Change your password</h2>
        <p class="mt-1.5 text-sm text-slate-600">
            Choose a password of at least 8 characters containing a letter and a number.
        </p>

        <form method="POST" action="<?= e(url('/password/change')) ?>" class="mt-6 space-y-5" novalidate>
            <?= csrf_field() ?>

            <?= component('field', [
                'name'         => 'current_password',
                'label'        => 'Current password',
                'type'         => 'password',
                'required'     => true,
                'autocomplete' => 'current-password',
                'value'        => '',
            ]) ?>

            <?= component('field', [
                'name'         => 'password',
                'label'        => 'New password',
                'type'         => 'password',
                'required'     => true,
                'autocomplete' => 'new-password',
                'value'        => '',
                'help'         => 'At least 8 characters, with a letter and a number.',
            ]) ?>

            <?= component('field', [
                'name'         => 'password_confirmation',
                'label'        => 'Confirm new password',
                'type'         => 'password',
                'required'     => true,
                'autocomplete' => 'new-password',
                'value'        => '',
            ]) ?>

            <button type="submit" class="btn-primary w-full">Change password</button>
        </form>
    </div>
</div>
