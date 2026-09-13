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
</head>
<body class="min-h-screen bg-slate-100 font-sans">
    <div class="flex min-h-screen flex-col items-center justify-center px-4 py-12">
        <div class="w-full max-w-md">
            <div class="mb-8 text-center">
                <a href="<?= e(url('/')) ?>" class="inline-flex items-center gap-2.5">
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-600 text-lg font-bold text-white">SH</span>
                    <span class="text-xl font-semibold text-brand-navy"><?= e($schoolName) ?></span>
                </a>
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
