<?php
/**
 * Set a password through a one-time link (docs/07).
 *
 * @var ?array  $found  user, purpose, expires_at; null when the link is not usable
 * @var string  $token
 */
use App\Services\PasswordResetService;

$min = App\Models\SystemSetting::security('password_min_length', 8);
?>
<div class="card p-6 sm:p-8">
    <?php if ($found === null): ?>
        <h1 class="text-xl font-semibold text-brand-navy">This link can't be used</h1>
        <p class="mt-2 text-sm text-slate-600">It may have expired, been used already, or been replaced by a newer link. Ask the school administrator for a new one.</p>
        <a href="<?= e(url('/login')) ?>" class="btn-secondary mt-6 w-full">Back to sign in</a>
    <?php else: ?>
        <h1 class="text-xl font-semibold text-brand-navy"><?= $found['purpose'] === PasswordResetService::SETUP ? 'Set your password' : 'Reset your password' ?></h1>
        <p class="mt-1.5 text-sm text-slate-600">
            For <strong><?= e($found['user']->fullName()) ?></strong> (<?= e($found['user']->username) ?>).
            This link works once, until <?= e(dt($found['expires_at'])) ?>.
        </p>

        <form method="POST" action="<?= e(url('/password/reset/' . $token)) ?>" class="mt-6 space-y-5" novalidate>
            <?= csrf_field() ?>
            <?= component('field', [
                'name' => 'password', 'label' => 'New password', 'type' => 'password', 'required' => true,
                'autocomplete' => 'new-password', 'value' => '',
                'help' => "At least {$min} characters, with at least one letter and one number.",
            ]) ?>
            <?= component('field', [
                'name' => 'password_confirmation', 'label' => 'Type it again', 'type' => 'password', 'required' => true,
                'autocomplete' => 'new-password', 'value' => '',
            ]) ?>
            <button type="submit" class="btn-primary w-full">Save password</button>
        </form>
    <?php endif; ?>
</div>
