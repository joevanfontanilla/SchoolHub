<?php
/**
 * My Account (docs/08): the signed-in user's name, profile photo, and a link to change the password.
 * Available to every role.
 *
 * @var App\Models\User $user
 * @var ?string $photoUrl   current avatar URL, or null
 * @var int     $maxBytes
 * @var ?string $nameLocked why the name cannot be changed now (a student with an application at the registrar), or null
 * @var ?string $signatureUrl the user's own saved e-signature preview, or null
 * @var int     $signatureMaxBytes
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

    <section id="signature" class="card p-5 sm:p-6" aria-labelledby="signature-heading">
        <h3 id="signature-heading" class="text-base">Your e-signature</h3>
        <p class="mt-1 text-sm text-slate-600">An image of your handwritten signature. It prints above your name on the documents you prepare, such as receipts, vouchers, reports, and grade sheets. Only you can see or change it here.</p>

        <?php if ($signatureUrl !== null): ?>
            <div class="mt-4 flex flex-wrap items-center gap-4">
                <div class="rounded-lg border border-slate-200 bg-white p-3"><img src="<?= e($signatureUrl) ?>" alt="Your saved signature" class="h-16 max-w-[14rem] object-contain"></div>
                <form method="POST" action="<?= e(url('/account/signature/remove')) ?>"
                      data-confirm="Documents you prepare will print the blank signature line again." data-confirm-title="Remove your signature?" data-confirm-tone="danger" data-confirm-label="Remove">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn-secondary">Remove signature</button>
                </form>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?= e(url('/account/signature')) ?>" enctype="multipart/form-data" class="mt-5 space-y-4" novalidate data-signature-form>
            <?= csrf_field() ?>
            <input type="hidden" name="drawn" value="" data-signature-drawn>

            <div hidden data-signature-pad-wrap>
                <p class="field-label">Draw it here</p>
                <canvas width="560" height="180" class="mt-1 w-full max-w-xl touch-none rounded-lg border border-slate-300 bg-white" aria-label="Signature drawing pad" data-signature-pad></canvas>
                <button type="button" class="btn-secondary mt-2" data-signature-clear>Clear</button>
            </div>

            <div>
                <label for="signature-file" class="field-label"><span data-signature-or hidden>Or upload</span><span data-signature-upload-only>Upload</span> an image (PNG or JPG, up to <?= e(number_format($signatureMaxBytes / 1048576, 0)) ?> MB)</label>
                <input id="signature-file" type="file" name="signature" accept="image/png,image/jpeg" class="field-input" <?= error_for('signature') ? 'aria-invalid="true"' : '' ?>>
                <?php if (error_for('signature')): ?><p class="field-error mt-1"><?= e(error_for('signature')) ?></p><?php endif; ?>
            </div>

            <div>
                <label class="flex items-start gap-2 text-sm text-slate-700">
                    <input type="checkbox" name="agree" value="1" class="mt-1 h-4 w-4 rounded border-slate-300 text-brand-600">
                    <span>This is my own signature and I agree it may appear on documents I prepare.</span>
                </label>
                <?php if (error_for('agree')): ?><p class="field-error mt-1"><?= e(error_for('agree')) ?></p><?php endif; ?>
            </div>

            <button type="submit" class="btn-primary"><?= $signatureUrl !== null ? 'Replace signature' : 'Save signature' ?></button>
        </form>
    </section>
    <section class="card p-5 sm:p-6" id="devices" aria-labelledby="devices-heading">
        <h3 id="devices-heading" class="text-base">Where you&rsquo;re signed in</h3>
        <p class="mt-1 text-sm text-slate-600">If someone enters your password while you are signed in here, you are asked before they get in. Sign out any device you do not recognise.</p>
        <?php foreach ($pendingDevices as $p): ?>
            <div class="mt-3 flex flex-wrap items-center gap-3 rounded-xl border border-danger-line bg-danger-bg p-3 text-sm">
                <span class="min-w-0 flex-1"><strong>Waiting for your approval:</strong> <?= e($p['device_label']) ?> &middot; IP <?= e($p['ip_address']) ?> &middot; <?= e(dt($p['created_at'])) ?></span>
                <form method="POST" action="<?= e(url('/security/devices/' . $p['id'] . '/approve')) ?>"><?= csrf_field() ?><button type="submit" class="btn-secondary">Yes, it&rsquo;s me</button></form>
                <form method="POST" action="<?= e(url('/security/devices/' . $p['id'] . '/deny')) ?>"><?= csrf_field() ?><button type="submit" class="btn-primary">No, deny</button></form>
            </div>
        <?php endforeach; ?>
        <ul class="mt-3 divide-y divide-slate-100 text-sm">
            <?php foreach ($devices as $d): ?>
                <li class="flex flex-wrap items-center gap-3 py-2">
                    <span class="min-w-0 flex-1">
                        <span class="font-semibold text-brand-navy"><?= e($d['device_label']) ?></span>
                        <?php if ($d['current']): ?><span class="ml-1 rounded-full bg-brand-50 px-2 py-0.5 text-xs font-semibold text-brand-navy">This device</span><?php endif; ?>
                        <span class="block text-xs text-brand-gray">IP <?= e($d['ip_address']) ?> &middot; signed in <?= e(dt($d['created_at'])) ?> &middot; last active <?= e(dt($d['last_seen_at'])) ?></span>
                    </span>
                    <?php if (!$d['current']): ?>
                        <form method="POST" action="<?= e(url('/security/devices/' . $d['id'] . '/sign-out')) ?>" data-confirm="That device is signed out the next time it loads a page." data-confirm-title="Sign out this device?" data-confirm-tone="warning"><?= csrf_field() ?><button type="submit" class="btn-secondary">Sign out<span class="sr-only">: <?= e($d['device_label']) ?></span></button></form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <section class="card p-5 sm:p-6" aria-labelledby="security-heading">
        <h3 id="security-heading" class="text-base">Password &amp; security</h3>
        <p class="mt-1 text-sm text-slate-600">Change the password you use to sign in.</p>
        <a href="<?= e(url('/password/change')) ?>" class="btn-secondary mt-3"><?= icon('key', 'h-5 w-5') ?> Change password</a>
    </section>
</div>
