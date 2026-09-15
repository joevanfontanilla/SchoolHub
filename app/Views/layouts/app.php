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
 * @var array $portal
 */
use App\Models\User;

$schoolName = $school['school_name'] ?? 'SchoolHub';
$portal = $portal ?? ['subtitle' => '', 'unread' => 0];
$portalName = match ($user?->role_name) {
    User::ROLE_SUPER_ADMIN => 'Admin Portal',
    null => 'Portal',
    default => $user->role_name . ' Portal',
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Dashboard') ?> · <?= e($schoolName) ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/app.css')) ?>">
    <link rel="icon" type="image/jpeg" href="<?= e(school_logo_url()) ?>">
</head>
<body class="min-h-screen bg-brand-canvas font-sans">
    <a href="#main" class="skip-link">Skip to main content</a>

    <div class="flex min-h-screen">
        <?= partial('app-sidebar', ['user' => $user, 'currentPath' => $currentPath ?? '', 'school' => $school, 'portal' => $portal]) ?>

        <div class="flex min-w-0 flex-1 flex-col">
            <?= partial('app-topbar', ['user' => $user, 'title' => $title ?? '', 'portal' => $portal]) ?>

            <main id="main" tabindex="-1" class="flex-1 px-4 py-6 sm:px-6 lg:px-8">
                <?= partial('urgent-banner', ['urgent' => $portal['urgent'] ?? []]) ?>
                <?= partial('flash') ?>
                <?= $content ?>
            </main>

            <footer class="bg-brand-950 px-4 py-5 text-sm text-white/80 sm:px-6 lg:px-8">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <p>
                        <span class="font-display font-semibold uppercase tracking-wide text-white"><?= e($schoolName) ?></span>
                        <span class="mx-2 text-white/40" aria-hidden="true">|</span><?= e($portalName) ?>
                    </p>
                    <nav aria-label="Footer" class="flex flex-wrap gap-x-6 gap-y-1">
                        <a href="<?= e(url('/about')) ?>" class="hover:text-white hover:underline">About</a>
                        <a href="<?= e(url('/contact')) ?>" class="hover:text-white hover:underline">Contact</a>
                        <a href="<?= e(url('/announcements')) ?>" class="hover:text-white hover:underline">Announcements</a>
                    </nav>
                </div>
            </footer>
        </div>
    </div>

    <script src="<?= e(asset('assets/app.js')) ?>" defer></script>
</body>
</html>
