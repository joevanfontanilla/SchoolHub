<?php
/**
 * Portal top bar: menu button (phones), page title, search, notifications, and
 * the account menu (docs/21).
 *
 * Search arrives in a later phase; it is shown disabled and labelled "Soon" rather
 * than pretending to work. The bell opens the latest notifications.
 *
 * @var App\Models\User|null $user
 * @var string $title
 * @var array  $portal ['subtitle' => string, 'unread' => int, 'recent' => array]
 */
$unread = (int) ($portal['unread'] ?? 0);
$recent = $portal['recent'] ?? [];
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

        <div class="relative hidden max-w-xl flex-1 lg:block">
            <label for="portal-search" class="sr-only">Search (coming soon)</label>
            <span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-brand-gray"><?= icon('search', 'h-5 w-5') ?></span>
            <input id="portal-search" type="search" disabled
                   placeholder="Search for subjects, announcements, or forms…"
                   class="w-full cursor-not-allowed rounded-2xl border border-brand-100 bg-white py-3 pl-12 pr-20 text-sm text-slate-700 shadow-sm placeholder:text-brand-gray">
            <span class="pointer-events-none absolute inset-y-0 right-4 flex items-center">
                <span class="rounded-md bg-brand-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-600">Soon</span>
            </span>
        </div>

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
                                    <button type="submit" class="text-xs font-semibold text-brand-600 hover:underline">Mark all read</button>
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
                        <span class="hidden text-left sm:block">
                            <span class="block font-display text-sm font-bold text-brand-navy"><?= e($user->fullName()) ?></span>
                            <span class="block text-xs text-brand-gray"><?= e($portal['subtitle'] ?? $user->role_name) ?></span>
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
                        <form method="POST" action="<?= e(url('/logout')) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm text-slate-700 hover:bg-brand-50 hover:text-brand-navy"><?= icon('logout', 'h-5 w-5 text-brand-600') ?> Sign out</button>
                        </form>
                    </div>
                </details>
            </div>
        <?php endif; ?>
    </div>
</header>
