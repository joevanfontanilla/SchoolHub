<?php
/**
 * My Account (docs/08): the signed-in user's profile photo and a link to change the password.
 * Available to every role.
 *
 * @var App\Models\User $user
 * @var ?string $photoUrl  current avatar URL, or null
 * @var int     $maxBytes
 */
?>
<div class="mx-auto max-w-3xl space-y-6">
    <div>
        <p class="eyebrow">Account</p>
        <h2 class="mt-1 text-2xl">My Account</h2>
        <p class="mt-1 text-sm text-slate-600"><?= e($user->fullName()) ?> · <?= e($user->role_name) ?></p>
    </div>

    <form method="POST" action="<?= e(url('/account/photo')) ?>" enctype="multipart/form-data" class="space-y-4" novalidate>
        <?= csrf_field() ?>
        <?= partial('student/photo-field', ['photoUrl' => $photoUrl, 'maxBytes' => $maxBytes]) ?>
        <button type="submit" class="btn-primary">Save photo</button>
    </form>

    <section class="card p-5 sm:p-6" aria-labelledby="security-heading">
        <h3 id="security-heading" class="text-base">Password &amp; security</h3>
        <p class="mt-1 text-sm text-slate-600">Change the password you use to sign in.</p>
        <a href="<?= e(url('/password/change')) ?>" class="btn-secondary mt-3"><?= icon('key', 'h-5 w-5') ?> Change password</a>
    </section>
</div>
