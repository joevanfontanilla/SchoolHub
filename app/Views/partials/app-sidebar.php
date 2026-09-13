<?php
/**
 * Role-driven sidebar.
 *
 * This mirrors the backend authorization in routes/web.php. Hiding a link here
 * is NOT authorization — the route middleware is (docs/00).
 *
 * Links whose modules arrive in later phases are rendered disabled rather than
 * hidden, so the shape of the system is visible without offering dead ends.
 *
 * @var App\Models\User|null $user
 * @var string $currentPath
 */

use App\Models\User;

$nav = match ($user?->role_name) {
    User::ROLE_STUDENT => [
        ['/student/dashboard', 'Dashboard', true],
        ['/student/profile', 'Profile', false],
        ['/student/enrollment', 'Enrollment', false],
        ['/student/requirements', 'Requirements', false],
        ['/student/schedule', 'Schedule', false],
        ['/student/grades', 'Grades', false],
        ['/student/attendance', 'Attendance', false],
        ['/student/fees', 'Fees', false],
        ['/student/payments', 'Payments', false],
    ],
    User::ROLE_TEACHER => [
        ['/teacher/dashboard', 'Dashboard', true],
        ['/teacher/classes', 'My classes', false],
        ['/teacher/attendance', 'Attendance', false],
        ['/teacher/grades', 'Grades', false],
        ['/teacher/schedule', 'Schedule', false],
    ],
    User::ROLE_REGISTRAR => [
        ['/registrar/dashboard', 'Dashboard', true],
        ['/registrar/applications', 'Applications', false],
        ['/registrar/students', 'Students', false],
        ['/registrar/programs', 'Programs', false],
        ['/registrar/subjects', 'Subjects', false],
        ['/registrar/sections', 'Sections', false],
        ['/registrar/reports', 'Reports', false],
    ],
    User::ROLE_CASHIER => [
        ['/cashier/dashboard', 'Dashboard', true],
        ['/cashier/accounts', 'Student accounts', false],
        ['/cashier/payments', 'Payments', false],
        ['/cashier/receipts', 'Receipts', false],
        ['/cashier/reports', 'Reports', false],
    ],
    User::ROLE_SUPER_ADMIN => [
        ['/admin/dashboard', 'Dashboard', true],
        ['/admin/users', 'Users', false],
        ['/admin/roles', 'Roles & permissions', false],
        ['/admin/settings', 'System settings', false],
        ['/admin/audit-logs', 'Audit logs', false],
        ['/admin/backups', 'Backups', false],
    ],
    default => [],
};

$schoolName = $school['school_name'] ?? 'SchoolHub';
?>
<aside id="app-sidebar"
       class="fixed inset-y-0 left-0 z-40 w-64 shrink-0 -translate-x-full border-r border-slate-200 bg-white transition-transform lg:static lg:translate-x-0"
       aria-label="Sidebar">
    <div class="flex h-full flex-col">
        <div class="flex items-center gap-2.5 border-b border-slate-200 px-4 py-4">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-600 text-sm font-bold text-white">SH</span>
            <span class="truncate text-sm font-semibold text-brand-navy"><?= e($schoolName) ?></span>
        </div>

        <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4" aria-label="<?= e($user?->role_name ?? '') ?> navigation">
            <p class="px-3 pb-2 text-xs font-semibold uppercase tracking-wider text-slate-400">
                <?= e($user?->role_name ?? '') ?>
            </p>

            <?php foreach ($nav as [$path, $label, $available]): ?>
                <?php if ($available): ?>
                    <?php $active = $currentPath === $path; ?>
                    <a href="<?= e(url($path)) ?>"
                       class="nav-link <?= $active ? 'nav-link-active' : '' ?>"
                       <?= $active ? 'aria-current="page"' : '' ?>>
                        <?= e($label) ?>
                    </a>
                <?php else: ?>
                    <span class="nav-link cursor-not-allowed text-slate-400 hover:bg-transparent hover:text-slate-400"
                          title="Available in a later phase">
                        <?= e($label) ?>
                        <span class="ml-auto rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium uppercase text-brand-gray">soon</span>
                    </span>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>

        <div class="border-t border-slate-200 p-3">
            <form method="POST" action="<?= e(url('/logout')) ?>">
                <?= csrf_field() ?>
                <button type="submit" class="btn-secondary w-full">Sign out</button>
            </form>
        </div>
    </div>
</aside>

<div id="sidebar-backdrop" class="fixed inset-0 z-30 bg-slate-900/40 lg:hidden" hidden></div>
