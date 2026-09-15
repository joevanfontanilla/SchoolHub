<?php
/**
 * Portal top bar: menu button (phones), page title, search, notifications, and
 * the account menu (docs/21).
 *
 * Quick search: typing lists the pages of this portal that match (by name or by the
 * words in navigation.php); the Registrar, Cashier, and Super Admin can also search
 * students, accounts, or users. Without JavaScript the form still submits the search
 * where there is one. Press "/" to focus it. The bell opens the latest notifications.
 *
 * @var App\Models\User|null $user
 * @var string $title
 * @var array  $portal ['subtitle' => string, 'unread' => int, 'recent' => array]
 */
use App\Models\User;

$unread = (int) ($portal['unread'] ?? 0);
$recent = $portal['recent'] ?? [];
[$searchPath, $searchWhat, $searchPlaceholder] = match ($user?->role_name) {
    User::ROLE_REGISTRAR   => ['/registrar/students', 'students', 'Find a student or a page…'],
    User::ROLE_CASHIER     => ['/cashier/accounts', 'student accounts', 'Find a student account or a page…'],
    User::ROLE_SUPER_ADMIN => ['/admin/users', 'users', 'Find a user or a page…'],
    default                => [null, null, 'Go to a page: grades, schedule, receipts…'],
};
$destinations = portal_destinations($user?->role_name);
?>
<header class="sticky top-0 z-20 border-b border-brand-100 bg-white/95 px-4 py-3 backdrop-blur sm:px-6 lg:px-8">
    <div class="flex items-center gap-3 sm:gap-4">
        <button type="button"
                class="rounded-lg p-2 text-brand-navy hover:bg-brand-50 lg:hidden"
                data-sidebar-toggle
                aria-expanded="false"
                aria-controls="app-sidebar">
            <span class="sr-only">Open menu</span>
            <?= icon('menu', 'h-7 w-7') ?>
        </button>

        <!-- The page's h1. Pages show their own large heading on wide screens. -->
        <h1 class="min-w-0 flex-1 truncate text-lg font-semibold text-brand-navy lg:sr-only"><?= e($title ?: 'Dashboard') ?></h1>

        <?php if ($user !== null): ?>
            <form method="GET" action="<?= e(url($searchPath ?? '/portal/announcements')) ?>" role="search"
                  class="relative hidden max-w-xl flex-1 lg:block" data-quick-search <?= $searchPath !== null ? 'data-search-target' : '' ?>>
                <label for="portal-search" class="sr-only">Search <?= e($searchWhat ?? 'pages') ?></label>
                <span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-brand-gray"><?= icon('search', 'h-5 w-5') ?></span>
                <input id="portal-search" type="search" name="q" autocomplete="off" spellcheck="false"
                       placeholder="<?= e($searchPlaceholder) ?>"
                       role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="portal-search-results"
                       class="peer w-full rounded-2xl border border-brand-100 bg-white py-3 pl-12 pr-12 text-sm text-slate-700 shadow-sm placeholder:text-brand-gray focus:border-brand-600 focus:outline-none focus:ring-1 focus:ring-brand-600">
                <kbd class="pointer-events-none absolute inset-y-0 right-4 my-auto hidden h-6 items-center peer-placeholder-shown:flex rounded-md border border-brand-100 bg-brand-50 px-2 font-sans text-xs font-semibold text-brand-600" aria-hidden="true">/</kbd>
                <ul id="portal-search-results" role="listbox" aria-label="Search suggestions" hidden
                    class="absolute left-0 right-0 z-30 mt-2 max-h-96 overflow-y-auto rounded-2xl border border-brand-100 bg-white py-2 shadow-xl">
                    <?php if ($searchPath !== null): ?>
                        <li id="portal-search-submit" role="option" aria-selected="false" data-search-submit
                            class="flex cursor-pointer items-center gap-3 px-4 py-2.5 text-sm text-slate-700 aria-selected:bg-brand-50 aria-selected:text-brand-navy">
                            <?= icon('search', 'h-5 w-5 text-brand-600') ?> <span>Search <?= e($searchWhat) ?> for “<strong data-search-echo></strong>”</span>
                        </li>
                    <?php endif; ?>
                    <?php foreach ($destinations as $i => $page): ?>
                        <li id="portal-search-option-<?= (int) $i ?>" role="option" aria-selected="false" hidden
                            data-href="<?= e(url($page['path'])) ?>" data-terms="<?= e($page['terms']) ?>"
                            class="flex cursor-pointer items-center gap-3 px-4 py-2.5 text-sm text-slate-700 aria-selected:bg-brand-50 aria-selected:text-brand-navy">
                            <?= icon($page['icon'], 'h-5 w-5 text-brand-600') ?> <span><?= e($page['label']) ?></span>
                        </li>
                    <?php endforeach; ?>
                    <li class="px-4 py-2.5 text-sm text-brand-gray" data-search-empty hidden>No page matches. Try another word.</li>
                </ul>
            </form>
        <?php endif; ?>

        <?php if ($user !== null): ?>
            <div class="ml-auto flex items-center gap-2 sm:gap-4">
                <details class="relative" data-dropdown>
                    <summary class="relative flex cursor-pointer list-none rounded-full p-2 text-brand-600 hover:bg-brand-50 [&::-webkit-details-marker]:hidden">
                        <?= icon('bell', 'h-7 w-7') ?>
                        <?php if ($unread > 0): ?>
                            <span class="absolute right-0.5 top-0.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-brand-alert px-1 text-[11px] font-bold leading-none text-white ring-2 ring-white" aria-hidden="true"><?= $unread > 9 ? '9+' : $unread ?></span>
                        <?php endif; ?>
                        <span class="sr-only">Notifications, <?= $unread ?> unread</span>
                    </summary>
                    <div class="absolute right-0 z-30 mt-2 w-[min(22rem,calc(100vw-2rem))] overflow-hidden rounded-2xl border border-brand-100 bg-white shadow-xl">
                        <div class="flex items-center justify-between gap-3 border-b border-brand-50 px-4 py-3">
                            <p class="font-display text-sm font-bold text-brand-navy">Notifications</p>
                            <?php if ($unread > 0): ?>
                                <form method="POST" action="<?= e(url('/portal/notifications/read-all')) ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="-my-1 inline-flex min-h-[1.75rem] items-center rounded-md px-2 text-xs font-semibold text-brand-600 hover:bg-brand-50 hover:underline">Mark all read</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <?php if ($recent === []): ?>
                            <p class="px-4 py-6 text-center text-sm text-brand-gray">No notifications yet.</p>
                        <?php else: ?>
                            <ul class="max-h-96 divide-y divide-brand-50 overflow-y-auto">
                                <?php foreach ($recent as $item): ?>
                                    <li>
                                        <form method="POST" action="<?= e(url('/portal/notifications/' . $item['id'] . '/open')) ?>">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="flex w-full gap-3 px-4 py-3 text-left hover:bg-brand-50 <?= $item['state'] === 'UNREAD' ? 'bg-brand-50/60' : '' ?>">
                                                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full <?= $item['state'] === 'UNREAD' ? 'bg-brand-600' : 'bg-transparent' ?>" aria-hidden="true"></span>
                                                <span class="min-w-0">
                                                    <span class="block text-sm <?= $item['state'] === 'UNREAD' ? 'font-semibold text-brand-navy' : 'text-slate-700' ?>"><?= $item['state'] === 'UNREAD' ? '<span class="sr-only">Unread: </span>' : '' ?><?= e($item['title']) ?></span>
                                                    <span class="block text-xs text-brand-gray"><?= e(dt($item['created_at'])) ?></span>
                                                </span>
                                            </button>
                                        </form>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <a href="<?= e(url('/portal/notifications')) ?>" class="block border-t border-brand-50 px-4 py-3 text-center text-sm font-semibold text-brand-600 hover:bg-brand-50">View all notifications</a>
                    </div>
                </details>

                <details class="relative" data-dropdown>
                    <summary class="flex cursor-pointer list-none items-center gap-3 rounded-2xl border-l border-brand-100 py-1 pl-3 pr-1 hover:bg-brand-50 sm:pl-4 [&::-webkit-details-marker]:hidden">
                        <span class="flex h-11 w-11 items-center justify-center rounded-full bg-gradient-to-br from-brand-primary to-brand-700 font-display text-sm font-bold text-white ring-2 ring-brand-100" aria-hidden="true"><?= e($user->initials()) ?></span>
                        <span class="hidden min-w-0 max-w-[14rem] text-left sm:block">
                            <span class="block truncate font-display text-sm font-bold text-brand-navy" title="<?= e($user->fullName()) ?>"><?= e($user->fullName()) ?></span>
                            <span class="block truncate text-xs text-brand-gray"><?= e($portal['subtitle'] ?? $user->role_name) ?></span>
                        </span>
                        <span class="text-brand-navy"><?= icon('chevron-down', 'h-4 w-4') ?></span>
                        <span class="sr-only">Account menu</span>
                    </summary>
                    <div class="absolute right-0 z-30 mt-2 w-60 overflow-hidden rounded-2xl border border-brand-100 bg-white py-2 shadow-xl">
                        <p class="border-b border-brand-50 px-4 pb-2 text-xs text-brand-gray sm:hidden"><?= e($user->fullName()) ?> · <?= e($portal['subtitle'] ?? '') ?></p>
                        <?php if ($user->isStudent()): ?>
                            <a href="<?= e(url('/student/profile')) ?>" class="flex items-center gap-3 px-4 py-2.5 text-sm text-slate-700 hover:bg-brand-50 hover:text-brand-navy"><?= icon('user', 'h-5 w-5 text-brand-600') ?> My profile</a>
                        <?php endif; ?>
                        <a href="<?= e(url('/password/change')) ?>" class="flex items-center gap-3 px-4 py-2.5 text-sm text-slate-700 hover:bg-brand-50 hover:text-brand-navy"><?= icon('key', 'h-5 w-5 text-brand-600') ?> Change password</a>
                        <form method="POST" action="<?= e(url('/logout')) ?>" data-confirm="You will need to sign in again to use the portal. Anything you have not saved on this page is lost." data-confirm-title="Sign out?" data-confirm-tone="primary" data-confirm-label="Sign out">
                            <?= csrf_field() ?>
                            <button type="submit" class="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm text-slate-700 hover:bg-brand-50 hover:text-brand-navy"><?= icon('logout', 'h-5 w-5 text-brand-600') ?> Sign out</button>
                        </form>
                    </div>
                </details>
            </div>
        <?php endif; ?>
    </div>
</header>
