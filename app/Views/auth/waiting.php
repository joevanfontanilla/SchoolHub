<?php
/**
 * Shown to a browser whose password was right while the account is signed in on another device (docs/07 "One device at a time").
 *
 * @var string $state waiting | denied | expired
 */
$message = match ($state) {
    'denied'  => ['Sign-in denied', 'The owner of this account did not allow this sign-in. If this is your account, sign in again and approve it from your other device.'],
    'expired' => ['Request expired', 'Nobody answered in time, so this sign-in was cancelled. You can try again.'],
    default   => ['Waiting for approval', 'This account is signed in on another device. We asked its owner to approve this sign-in. This page continues by itself once they do.'],
};
?>
<div class="card p-6 sm:p-8" data-waiting data-status-url="<?= e(url('/login/waiting/status')) ?>" data-login-url="<?= e(url('/login')) ?>">
    <h1 class="text-xl"><?= e($message[0]) ?></h1>
    <p class="mt-2 text-sm text-slate-600" role="status" data-waiting-text><?= e($message[1]) ?></p>

    <?php if ($state === 'waiting'): ?>
        <p class="mt-3 text-xs text-brand-gray">If you cannot reach the other device, this page lets you in on its own once that device has been idle for 30 minutes. You can also ask the school administrator to release the account.</p>
        <noscript><a href="<?= e(url('/login/waiting')) ?>" class="btn-secondary mt-4">Check again</a></noscript>
    <?php endif; ?>

    <form method="POST" action="<?= e(url('/login/waiting/cancel')) ?>" class="mt-6">
        <?= csrf_field() ?>
        <button type="submit" class="btn-secondary w-full"><?= icon('arrow-left', 'h-5 w-5') ?> <?= $state === 'waiting' ? 'Cancel and go back' : 'Back to sign in' ?></button>
    </form>
</div>
