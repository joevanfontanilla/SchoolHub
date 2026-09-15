<?php
/**
 * Sign-in page (docs/07): the campus and what the portal is for on the left (wide screens),
 * the form on the right.
 *
 * The error message is deliberately identical for an unknown account and a
 * wrong password — see AuthService::INVALID_CREDENTIALS.
 */
$schoolName = $school['school_name'] ?? 'SchoolHub';
$features = [
    ['enrollment', 'Apply for enrollment and track every step'],
    ['requirements', 'Upload your documents and see them verified'],
    ['credit-card', 'Pay online and keep your official receipts'],
    ['book-open', 'See your schedule, attendance, and grades'],
];
?>
<div class="overflow-hidden rounded-3xl bg-white shadow-xl ring-1 ring-brand-100 lg:grid lg:grid-cols-2">
    <div class="relative hidden min-h-[36rem] lg:block">
        <img src="<?= e(asset('assets/images/campus.jpg')) ?>" alt="" class="absolute inset-0 h-full w-full object-cover">
        <div class="absolute inset-0 bg-gradient-to-br from-brand-900/95 via-brand-900/85 to-brand-700/70" aria-hidden="true"></div>
        <div class="relative flex h-full flex-col justify-between p-10 text-white">
            <a href="<?= e(url('/')) ?>" class="flex items-center gap-3">
                <img src="<?= e(school_logo_url()) ?>" alt="" width="64" height="64" class="school-seal h-14 w-14 ring-4 ring-white/20">
                <span>
                    <span class="block font-display text-lg font-extrabold uppercase leading-tight tracking-tight"><?= e($schoolName) ?></span>
                    <span class="block text-xs font-semibold uppercase tracking-[0.14em] text-white/75"><?= e($school['school_tagline'] ?? '') ?></span>
                </span>
            </a>

            <div>
                <p class="font-display text-3xl font-extrabold leading-tight">One portal for your whole school year.</p>
                <ul class="mt-6 space-y-3 text-sm text-white/90">
                    <?php foreach ($features as [$iconName, $text]): ?>
                        <li class="flex items-center gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white/15 text-brand-teal"><?= icon($iconName, 'h-5 w-5') ?></span>
                            <?= e($text) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <p class="text-xs text-white/70">For students, teachers, the registrar, the cashier, and administrators.</p>
        </div>
    </div>

    <div class="p-6 sm:p-10">
        <a href="<?= e(url('/')) ?>" class="mb-6 flex items-center gap-3 lg:hidden">
            <img src="<?= e(school_logo_url()) ?>" alt="" width="56" height="56" class="school-seal h-12 w-12">
            <span class="font-display text-base font-extrabold uppercase leading-tight tracking-tight text-brand-navy"><?= e($schoolName) ?></span>
        </a>

        <p class="eyebrow">Portal sign-in</p>
        <h1 class="mt-1 font-display text-3xl font-extrabold text-brand-navy">Welcome back</h1>
        <p class="mt-2 text-sm text-slate-600">Sign in with your email address or username.</p>

        <form method="POST" action="<?= e(url('/login')) ?>" class="mt-7 space-y-5" novalidate>
            <?= csrf_field() ?>

            <?= component('field', [
                'name'         => 'login',
                'label'        => 'Email or username',
                'type'         => 'text',
                'required'     => true,
                'autocomplete' => 'username',
                'icon'         => 'user',
                'autofocus'    => old('login') === '',
            ]) ?>

            <?= component('field', [
                'name'         => 'password',
                'label'        => 'Password',
                'type'         => 'password',
                'required'     => true,
                'autocomplete' => 'current-password',
                'value'        => '',
                'icon'         => 'lock',
                'autofocus'    => old('login') !== '',
            ]) ?>
            <?php if (App\Core\Mailer::enabled()): ?>
                <p class="-mt-3 text-right text-sm"><a href="<?= e(url('/password/forgot')) ?>" class="inline-block py-1 font-semibold text-brand-600 hover:underline">Forgot password?</a></p>
            <?php endif; ?>

            <button type="submit" class="btn-primary w-full py-3 text-base" data-busy-label="Signing in…">
                Sign in <?= icon('arrow-right', 'h-5 w-5') ?>
            </button>
        </form>

        <div class="mt-8 space-y-4 border-t border-brand-100 pt-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-slate-600">New student?</p>
                <a href="<?= e(url('/register')) ?>" class="btn-secondary"><?= icon('plus', 'h-5 w-5') ?> Create an account</a>
            </div>
            <p class="flex items-start gap-2 rounded-xl bg-brand-50 px-4 py-3 text-sm text-slate-700">
                <span class="mt-0.5 text-brand-600"><?= icon('key', 'h-5 w-5') ?></span>
                <span><?php if (App\Core\Mailer::enabled()): ?><strong class="text-brand-navy">No longer use your account's email?</strong> Ask the school administrator for a one-time reset link.<?php else: ?><strong class="text-brand-navy">Forgot your password?</strong> Ask the school administrator for a one-time reset link.<?php endif; ?></span>
            </p>
        </div>
    </div>
</div>
