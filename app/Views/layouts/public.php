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
</head>
<body class="min-h-screen bg-white font-sans">
    <a href="#main" class="skip-link">Skip to main content</a>

    <?= partial('public-nav', ['school' => $school, 'currentPath' => $currentPath ?? '/']) ?>

    <main id="main" tabindex="-1">
        <?= partial('flash') ?>
        <?= $content ?>
    </main>

    <?= partial('public-footer', ['school' => $school]) ?>

    <script src="<?= e(asset('assets/app.js')) ?>" defer></script>
</body>
</html>
