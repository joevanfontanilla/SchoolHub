<?php
/**
 * Public site navigation.
 *
 * @var array  $school
 * @var string $currentPath
 */
$links = [
    '/'              => 'Home',
    '/about'         => 'About',
    '/programs'      => 'Programs',
    '/enrollment'    => 'Enrollment',
    '/requirements'  => 'Requirements',
    '/announcements' => 'Announcements',
    '/downloads'     => 'Downloads',
    '/contact'       => 'Contact',
];
$schoolName = $school['school_name'] ?? 'SchoolHub';
?>
<header class="sticky top-0 z-40 border-b border-slate-200 bg-white/95 backdrop-blur">
    <nav class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-8" aria-label="Main">
        <a href="<?= e(url('/')) ?>" class="flex shrink-0 items-center gap-2.5">
            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-600 text-sm font-bold text-white">SH</span>
            <span class="text-base font-semibold text-brand-navy sm:text-lg"><?= e($schoolName) ?></span>
        </a>

        <ul class="hidden items-center gap-1 lg:flex">
            <?php foreach ($links as $path => $label): ?>
                <?php $active = $currentPath === $path || ($path !== '/' && str_starts_with($currentPath, $path)); ?>
                <li>
                    <a href="<?= e(url($path)) ?>"
                       class="rounded-lg px-3 py-2 text-sm font-medium transition <?= $active ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:bg-slate-100 hover:text-brand-navy' ?>"
                       <?= $active ? 'aria-current="page"' : '' ?>>
                        <?= e($label) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="flex items-center gap-2">
            <a href="<?= e(url('/login')) ?>" class="btn-primary">Sign in</a>

            <button type="button"
                    class="rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden"
                    data-menu-toggle="public-menu"
                    aria-expanded="false"
                    aria-controls="public-menu">
                <span class="sr-only">Open main menu</span>
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>
        </div>
    </nav>

    <div id="public-menu" class="border-t border-slate-200 lg:hidden" hidden>
        <ul class="space-y-1 px-4 py-3">
            <?php foreach ($links as $path => $label): ?>
                <?php $active = $currentPath === $path; ?>
                <li>
                    <a href="<?= e(url($path)) ?>"
                       class="block rounded-lg px-3 py-2.5 text-sm font-medium <?= $active ? 'bg-brand-50 text-brand-700' : 'text-slate-700 hover:bg-slate-100' ?>"
                       <?= $active ? 'aria-current="page"' : '' ?>>
                        <?= e($label) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</header>
