<?php
/**
 * @var App\Models\User|null $user
 * @var string $title
 */
?>
<header class="sticky top-0 z-20 border-b border-slate-200 bg-white px-4 py-3 sm:px-6 lg:px-8">
    <div class="flex items-center justify-between gap-4">
        <div class="flex min-w-0 items-center gap-3">
            <button type="button"
                    class="rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden"
                    data-sidebar-toggle
                    aria-expanded="false"
                    aria-controls="app-sidebar">
                <span class="sr-only">Open sidebar</span>
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>

            <h1 class="truncate text-lg font-semibold text-brand-navy"><?= e($title ?: 'Dashboard') ?></h1>
        </div>

        <?php if ($user !== null): ?>
            <div class="flex items-center gap-3">
                <div class="hidden text-right sm:block">
                    <p class="text-sm font-medium text-brand-navy"><?= e($user->fullName()) ?></p>
                    <p class="text-xs text-brand-gray"><?= e($user->role_name) ?></p>
                </div>
                <span class="flex h-9 w-9 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700"
                      aria-hidden="true"><?= e($user->initials()) ?></span>
            </div>
        <?php endif; ?>
    </div>
</header>
