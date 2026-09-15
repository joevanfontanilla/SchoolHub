<?php
/**
 * Sign-in form (docs/07).
 *
 * The error message is deliberately identical for an unknown account and a
 * wrong password — see AuthService::INVALID_CREDENTIALS.
 */
?>
<div class="card p-6 sm:p-8">
    <h1 class="text-xl font-semibold text-brand-navy">Sign in</h1>
    <p class="mt-1.5 text-sm text-slate-600">Students may sign in with their email address.</p>

    <form method="POST" action="<?= e(url('/login')) ?>" class="mt-6 space-y-5" novalidate>
        <?= csrf_field() ?>

        <?= component('field', [
            'name'         => 'login',
            'label'        => 'Email or username',
            'type'         => 'text',
            'required'     => true,
            'autocomplete' => 'username',
        ]) ?>

        <?= component('field', [
            'name'         => 'password',
            'label'        => 'Password',
            'type'         => 'password',
            'required'     => true,
            'autocomplete' => 'current-password',
            'value'        => '',
        ]) ?>

        <button type="submit" class="btn-primary w-full">Sign in</button>
    </form>

    <div class="mt-6 space-y-2 border-t border-slate-200 pt-5 text-sm text-slate-600">
        <p>
            New student?
            <a href="<?= e(url('/register')) ?>" class="font-semibold text-brand-600 hover:underline">Create an account</a>
        </p>
        <p>Forgot your password? Contact the school administrator for a one-time reset link.</p>
    </div>
</div>
