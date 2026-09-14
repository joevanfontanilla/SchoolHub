<?php
/**
 * Minimal centered layout for sign-in and password pages.
 *
 * @var string $content
 * @var string $title
 */
$schoolName = $school['school_name'] ?? 'SchoolHub';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Sign in') ?> · <?= e($schoolName) ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/app.css')) ?>">
    <link rel="icon" type="image/jpeg" href="<?= e(asset('assets/images/lyceum-logo.jpg')) ?>">
</head>
<body class="hero-backdrop min-h-screen font-sans">
    <div class="flex min-h-screen flex-col items-center justify-center px-4 py-12">
        <div class="w-full max-w-md">
            <div class="mb-8 text-center">
                <a href="<?= e(url('/')) ?>" class="inline-flex flex-col items-center gap-3">
                    <img src="<?= e(asset('assets/images/lyceum-logo.jpg')) ?>" alt="" width="88" height="88"
                         class="school-seal h-20 w-20">
                    <span class="font-display text-lg font-extrabold uppercase tracking-tight text-brand-navy"><?= e($schoolName) ?></span>
                </a>
                <p class="tagline mt-1"><?= e($school['school_tagline'] ?? '') ?></p>
                <span class="brand-rule mx-auto mt-4" aria-hidden="true"></span>
            </div>

            <?= partial('flash') ?>
            <?= $content ?>

            <p class="mt-8 text-center text-sm text-brand-gray">
                <a href="<?= e(url('/')) ?>" class="font-medium text-brand-600 hover:underline">Back to the website</a>
            </p>
        </div>
    </div>
</body>
</html>
