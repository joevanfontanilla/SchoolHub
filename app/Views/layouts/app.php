<?php
/**
 * Authenticated application shell: sidebar + topbar (docs/21).
 *
 * The sidebar is built from the signed-in user's role. It MIRRORS the backend
 * authorization in routes/web.php — it does not provide it.
 *
 * @var string $content
 * @var string $title
 * @var App\Models\User|null $user
 */
$schoolName = $school['school_name'] ?? 'SchoolHub';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Dashboard') ?> · <?= e($schoolName) ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/app.css')) ?>">
    <link rel="icon" type="image/jpeg" href="<?= e(asset('assets/images/lyceum-logo.jpg')) ?>">
</head>
<body class="min-h-screen bg-slate-50 font-sans">
    <a href="#main" class="skip-link">Skip to main content</a>

    <div class="flex min-h-screen">
        <?= partial('app-sidebar', ['user' => $user, 'currentPath' => $currentPath ?? '', 'school' => $school]) ?>

        <div class="flex min-w-0 flex-1 flex-col">
            <?= partial('app-topbar', ['user' => $user, 'title' => $title ?? '']) ?>

            <main id="main" tabindex="-1" class="flex-1 px-4 py-6 sm:px-6 lg:px-8">
                <?= partial('flash') ?>
                <?= $content ?>
            </main>

            <footer class="border-t border-slate-200 px-4 py-4 text-center text-xs text-brand-gray sm:px-6">
                <?= e($schoolName) ?> · SchoolHub v1.0.0
            </footer>
        </div>
    </div>

    <script src="<?= e(asset('assets/app.js')) ?>" defer></script>
</body>
</html>
