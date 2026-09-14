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
$schoolName = $school['school_name'] ?? 'Lyceum of the East Aurora';
?>
<header class="sticky top-0 z-40 border-b border-brand-200/70 bg-white/95 backdrop-blur">
    <nav class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-2.5 sm:px-6 lg:px-8" aria-label="Main">
        <a href="<?= e(url('/')) ?>" class="flex min-w-0 shrink-0 items-center gap-3">
            <img src="<?= e(asset('assets/images/lyceum-logo.jpg')) ?>" alt="" width="44" height="44"
                 class="school-seal h-11 w-11 shadow-sm">
            <!-- Visually hidden on the smallest screens, but always the link's accessible name. -->
            <span class="sr-only font-display text-sm font-extrabold uppercase leading-tight tracking-tight text-brand-navy sm:not-sr-only xl:text-base">
                <?= e($schoolName) ?>
            </span>
        </a>

        <ul class="hidden items-center gap-0.5 lg:flex">
            <?php foreach ($links as $path => $label): ?>
                <?php $active = $currentPath === $path || ($path !== '/' && str_starts_with($currentPath, $path)); ?>
                <li>
                    <a href="<?= e(url($path)) ?>"
                       class="rounded-lg px-2.5 py-2 font-display text-[13px] font-semibold transition xl:px-3 <?= $active ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:bg-brand-50 hover:text-brand-navy' ?>"
                       <?= $active ? 'aria-current="page"' : '' ?>>
                        <?= e($label) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="flex items-center gap-2">
            <a href="<?= e(url('/login')) ?>" class="btn-primary">Sign in</a>

            <button type="button"
                    class="rounded-lg p-2 text-brand-navy hover:bg-brand-50 lg:hidden"
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

    <div id="public-menu" class="border-t border-brand-200/70 lg:hidden" hidden>
        <ul class="space-y-1 px-4 py-3">
            <?php foreach ($links as $path => $label): ?>
                <?php $active = $currentPath === $path; ?>
                <li>
                    <a href="<?= e(url($path)) ?>"
                       class="block rounded-lg px-3 py-2.5 font-display text-sm font-semibold <?= $active ? 'bg-brand-50 text-brand-700' : 'text-slate-700 hover:bg-brand-50' ?>"
                       <?= $active ? 'aria-current="page"' : '' ?>>
                        <?= e($label) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</header>
