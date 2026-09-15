<?php
/**
 * Accounts (docs/04).
 *
 * @var array $filters q, role, status
 * @var array $users
 * @var int   $total
 * @var int   $page
 * @var int   $pages
 * @var array $counts status => count
 */
use App\Models\User;

$statusBadge = static fn (string $status) => component('status-badge', match ($status) {
    User::STATUS_ACTIVE   => ['status' => 'APPROVED', 'label' => 'Active'],
    User::STATUS_PENDING  => ['status' => 'SCHEDULED', 'label' => 'Pending setup'],
    User::STATUS_LOCKED   => ['status' => 'REJECTED', 'label' => 'Locked'],
    default               => ['status' => 'ARCHIVED', 'label' => 'Disabled'],
});
?>
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">Administration</p>
            <h2 class="mt-1 text-2xl">Users</h2>
            <p class="mt-1 text-sm text-slate-600">Every account. Create staff accounts here; students register themselves.</p>
        </div>
        <a href="<?= e(url('/admin/users/create')) ?>" class="btn-primary"><?= icon('plus', 'h-5 w-5') ?> New staff account</a>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <?= component('stat-card', ['label' => 'Active', 'value' => (string) ($counts[User::STATUS_ACTIVE] ?? 0), 'icon' => 'users', 'tone' => 'success']) ?>
        <?= component('stat-card', ['label' => 'Pending setup', 'value' => (string) ($counts[User::STATUS_PENDING] ?? 0), 'hint' => 'Waiting to set a password', 'icon' => 'clock']) ?>
        <?= component('stat-card', ['label' => 'Locked', 'value' => (string) ($counts[User::STATUS_LOCKED] ?? 0), 'icon' => 'lock', 'tone' => ($counts[User::STATUS_LOCKED] ?? 0) > 0 ? 'warning' : 'neutral']) ?>
        <?= component('stat-card', ['label' => 'Disabled', 'value' => (string) ($counts[User::STATUS_DISABLED] ?? 0), 'icon' => 'shield']) ?>
    </div>

    <form method="GET" action="<?= e(url('/admin/users')) ?>" class="card flex flex-wrap items-end gap-4 p-4">
        <div class="min-w-[16rem] flex-1">
            <label for="users-q" class="field-label">Search</label>
            <input id="users-q" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Name, username, or email" class="field-input">
        </div>
        <div class="min-w-[10rem]">
            <label for="users-role" class="field-label">Role</label>
            <select id="users-role" name="role" class="field-input">
                <option value="">All roles</option>
                <?php foreach ([User::ROLE_STUDENT, User::ROLE_TEACHER, User::ROLE_REGISTRAR, User::ROLE_CASHIER, User::ROLE_SUPER_ADMIN] as $role): ?>
                    <option value="<?= e($role) ?>" <?= $filters['role'] === $role ? 'selected' : '' ?>><?= e($role) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="min-w-[10rem]">
            <label for="users-status" class="field-label">Status</label>
            <select id="users-status" name="status" class="field-input">
                <option value="">All</option>
                <?php foreach ([User::STATUS_ACTIVE, User::STATUS_PENDING, User::STATUS_LOCKED, User::STATUS_DISABLED] as $status): ?>
                    <option value="<?= e($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-secondary"><?= icon('search', 'h-5 w-5') ?> Search</button>
    </form>

    <section class="card">
        <?php if ($users === []): ?>
            <?= component('empty-state', ['title' => 'No accounts found']) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                    <thead class="bg-brand-50 text-brand-navy">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Name</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Role</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Status</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Last sign-in</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-50 bg-white">
                        <?php foreach ($users as $row): ?>
                            <tr class="hover:bg-brand-50/40">
                                <td class="px-4 py-3"><span class="font-semibold text-brand-navy"><?= e($row['last_name'] . ', ' . $row['first_name']) ?></span><span class="block text-xs text-brand-gray"><?= e($row['username']) ?> · <?= e($row['email']) ?></span></td>
                                <td class="whitespace-nowrap px-4 py-3"><?= e($row['role_name']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3"><?= $statusBadge($row['status']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600"><?= e($row['last_login_at'] ? dt($row['last_login_at']) : 'Never') ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right"><a href="<?= e(url('/admin/users/' . $row['id'])) ?>" class="font-semibold text-brand-600 hover:underline">Manage<span class="sr-only"> <?= e($row['first_name'] . ' ' . $row['last_name']) ?></span></a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= partial('pagination', ['page' => $page, 'pages' => $pages, 'total' => $total, 'path' => '/admin/users', 'query' => $filters, 'noun' => 'account']) ?>
        <?php endif; ?>
    </section>
</div>
