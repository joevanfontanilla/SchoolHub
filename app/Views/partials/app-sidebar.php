<?php
/**
 * Role-driven sidebar (docs/21).
 *
 * This mirrors the backend authorization in routes/web.php. Hiding a link here
 * is NOT authorization — the route middleware is (docs/00). The entries are defined
 * once, in app/Helpers/navigation.php, and shared with the top-bar quick search.
 *
 * Links whose modules arrive in later phases are rendered disabled with a "Soon"
 * label rather than hidden, so the shape of the system is visible without
 * offering dead ends.
 *
 * Entry: [path, label, icon, available, extra paths that also mark it active]
 * A string entry is a group heading.
 *
 * @var App\Models\User|null $user
 * @var string $currentPath
 * @var array  $portal ['subtitle' => string, 'unread' => int]
 */

use App\Models\User;

$nav = portal_nav($user?->role_name);

$portalName = match ($user?->role_name) {
    User::ROLE_SUPER_ADMIN => 'Admin Portal',
    null => 'Portal',
    default => $user->role_name . ' Portal',
};
$schoolName = $school['school_name'] ?? 'SchoolHub';
$unread = (int) ($portal['unread'] ?? 0);
$itemClass = 'group relative flex items-center gap-3 whitespace-nowrap rounded-xl px-3.5 py-2.5 text-sm font-medium transition';
?>
<aside id="app-sidebar"
       class="fixed inset-y-0 left-0 z-40 w-72 shrink-0 -translate-x-full bg-gradient-to-b from-brand-900 to-brand-950 text-white shadow-xl transition-transform lg:sticky lg:top-0 lg:h-screen lg:translate-x-0 lg:shadow-none"
       aria-label="Sidebar">
    <div class="relative flex h-full flex-col overflow-hidden">
        <a href="<?= e(url('/')) ?>" class="flex flex-col items-center px-6 pb-5 pt-7 text-center">
            <img src="<?= e(school_logo_url()) ?>" alt="" width="84" height="84"
                 class="school-seal h-20 w-20 ring-4 ring-white/15">
            <span class="mt-3 font-display text-base font-extrabold uppercase leading-tight tracking-tight"><?= e($schoolName) ?></span>
            <span class="mt-1 text-sm text-white/75"><?= e($portalName) ?></span>
        </a>

        <nav class="flex-1 space-y-1 overflow-y-auto px-3 pb-4" aria-label="<?= e($portalName) ?>">
            <?php foreach ($nav as $entry): ?>
                <?php if (is_string($entry)): ?>
                    <p class="px-4 pb-1 pt-4 text-[11px] font-semibold uppercase tracking-wider text-white/55"><?= e($entry) ?></p>
                    <?php continue; ?>
                <?php endif; ?>
                <?php
                [$path, $label, $iconName, $available] = $entry;
                $paths = [$path, ...($entry[4] ?? [])];
                // A section stays highlighted on its sub-pages (/student/enrollment/12).
                $active = array_filter($paths, static fn ($p) => $currentPath === $p || str_starts_with($currentPath, $p . '/')) !== [];
                $badge = $iconName === 'bell' && $unread > 0
                    ? '<span class="absolute -right-1.5 -top-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-brand-alert px-1 text-[10px] font-bold leading-none text-white">' . ($unread > 9 ? '9+' : $unread) . '</span>'
                    : '';
                $iconHtml = '<span class="relative">' . icon($iconName, 'h-[22px] w-[22px]') . $badge . '</span>';
                ?>
                <?php if ($available): ?>
                    <a href="<?= e(url($path)) ?>"
                       class="<?= $itemClass ?> <?= $active ? 'bg-white/15 font-semibold text-white' : 'text-white/85 hover:bg-white/10 hover:text-white' ?>"
                       <?= $active ? 'aria-current="page"' : '' ?>>
                        <?= $iconHtml ?>
                        <span><?= e($label) ?></span>
                    </a>
                <?php else: ?>
                    <span class="<?= $itemClass ?> cursor-not-allowed text-white/60" title="Available in a later phase">
                        <?= $iconHtml ?>
                        <span><?= e($label) ?><?= $badge !== '' ? '<span class="sr-only"> (' . $unread . ' unread)</span>' : '' ?></span>
                        <span class="ml-auto rounded-md bg-white/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white/75">Soon</span>
                    </span>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>

        <div class="relative px-3 pb-6">
            <div class="mx-4 border-t border-white/15"></div>
            <form method="POST" action="<?= e(url('/logout')) ?>" data-confirm="You will need to sign in again to use the portal. Anything you have not saved on this page is lost." data-confirm-title="Sign out?" data-confirm-tone="primary" data-confirm-label="Sign out" class="mt-3">
                <?= csrf_field() ?>
                <button type="submit" class="<?= $itemClass ?> w-full text-white/85 hover:bg-white/10 hover:text-white">
                    <?= icon('logout', 'h-[22px] w-[22px]') ?>
                    <span>Logout</span>
                </button>
            </form>
            <p class="relative z-10 mt-4 px-4 pr-24 text-xs font-medium leading-relaxed text-white/70">
                <?= e($school['school_tagline'] ?? '') ?>
            </p>
        </div>

        <div class="brand-stripes" aria-hidden="true"></div>
    </div>
</aside>

<div id="sidebar-backdrop" class="fixed inset-0 z-30 bg-brand-950/50 lg:hidden" hidden></div>
