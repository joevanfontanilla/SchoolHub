<?php
/**
 * "Forgot password?": ask for a reset link by email (docs/07).
 *
 * @var bool $enabled whether email is set up on this installation
 */
?>
<div class="card p-6 sm:p-8">
    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50 text-brand-600 ring-1 ring-brand-200"><?= icon('key', 'h-6 w-6') ?></span>
    <h1 class="mt-4 text-xl font-semibold text-brand-navy">Forgot your password?</h1>

    <?php if ($enabled): ?>
        <p class="mt-1.5 text-sm text-slate-600">Enter the email address of your portal account. We will send a link to choose a new password.</p>

        <form method="POST" action="<?= e(url('/password/forgot')) ?>" class="mt-6 space-y-5" novalidate>
            <?= csrf_field() ?>
            <?= component('field', [
                'name'         => 'email',
                'label'        => 'Email address',
                'type'         => 'email',
                'required'     => true,
                'autocomplete' => 'email',
                'icon'         => 'mail',
                'autofocus'    => true,
            ]) ?>
            <button type="submit" class="btn-primary w-full" data-busy-label="Sending…"><?= icon('send', 'h-5 w-5') ?> Send reset link</button>
        </form>

        <p class="mt-5 text-xs text-brand-gray">No longer have that email address? Ask the registrar or the school administrator to update your account.</p>
    <?php else: ?>
        <p class="mt-1.5 text-sm text-slate-600">Password reset by email is not available on this site. Ask the school administrator for a one-time reset link.</p>
    <?php endif; ?>

    <a href="<?= e(url('/login')) ?>" class="btn-secondary mt-6 w-full"><?= icon('arrow-left', 'h-5 w-5') ?> Back to sign in</a>
</div>
