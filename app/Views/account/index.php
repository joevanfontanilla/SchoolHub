<?php
/**
 * My Account (docs/08): the signed-in user's name, profile photo, and a link to change the password.
 * Available to every role.
 *
 * @var App\Models\User $user
 * @var ?string $photoUrl   current avatar URL, or null
 * @var int     $maxBytes
 * @var ?string $nameLocked why the name cannot be changed now (a student with an application at the registrar), or null
 */
?>
<div class="mx-auto max-w-3xl space-y-6">
    <div>
        <p class="eyebrow">Account</p>
        <h2 class="mt-1 text-2xl">My Account</h2>
        <p class="mt-1 text-sm text-slate-600"><?= e($user->fullName()) ?> · <?= e($user->role_name) ?></p>
    </div>

    <section id="name" class="card p-5 sm:p-6" aria-labelledby="name-heading">
        <h3 id="name-heading" class="text-base">Your name</h3>
        <?php if ($nameLocked !== null): ?>
            <p class="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600"><?= e($nameLocked) ?></p>
            <p class="mt-3 text-base font-medium text-slate-800"><?= e($user->fullName()) ?></p>
        <?php else: ?>
            <p class="mt-1 text-sm text-slate-600">This is how your name appears on receipts, records, and messages. Your email and username are changed by the administrator.</p>
            <form method="POST" action="<?= e(url('/account/name')) ?>" class="mt-4 space-y-4" novalidate>
                <?= csrf_field() ?>
                <div class="grid gap-4 sm:grid-cols-2">
                    <?= component('field', ['name' => 'first_name', 'label' => 'First name', 'required' => true, 'value' => old('first_name', $user->first_name), 'autocomplete' => 'given-name']) ?>
                    <?= component('field', ['name' => 'middle_name', 'label' => 'Middle name', 'value' => old('middle_name', $user->middle_name ?? ''), 'autocomplete' => 'additional-name']) ?>
                    <?= component('field', ['name' => 'last_name', 'label' => 'Last name', 'required' => true, 'value' => old('last_name', $user->last_name), 'autocomplete' => 'family-name']) ?>
                    <?= component('field', ['name' => 'suffix', 'label' => 'Suffix', 'value' => old('suffix', $user->suffix ?? ''), 'help' => 'Jr., Sr., III…']) ?>
                </div>
                <button type="submit" class="btn-primary">Save name</button>
            </form>
        <?php endif; ?>
    </section>

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
