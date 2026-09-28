<?php
/**
 * Public website layout (docs/06).
 *
 * @var string $content
 * @var string $title
 * @var array  $school
 */
$schoolName = $school['school_name'] ?? 'SchoolHub';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Home') ?> · <?= e($schoolName) ?></title>
    <meta name="description" content="<?= e($school['school_tagline'] ?? 'School enrollment and management') ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/app.css')) ?>">
    <script src="<?= e(asset('assets/theme-init.js')) ?>"></script>
    <link rel="icon" type="image/jpeg" href="<?= e(school_logo_url()) ?>">
</head>
<body class="min-h-screen bg-white font-sans">
    <a href="#main" class="skip-link">Skip to main content</a>

    <?= partial('public-nav', ['school' => $school, 'currentPath' => $currentPath ?? '/']) ?>

    <main id="main" tabindex="-1">
        <?= partial('flash', ['container' => true]) ?>
        <?= $content ?>
    </main>

    <?= partial('public-footer', ['school' => $school]) ?>

    <?= partial('confirm-dialog') ?>
    <script src="<?= e(asset('assets/app.js')) ?>" defer></script>
</body>
</html>
