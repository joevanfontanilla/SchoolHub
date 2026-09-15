<?php
/**
 * Roles and permissions (docs/04). One form per role; Super Admin is shown, not editable.
 *
 * @var array $roles
 * @var array $modules  module => permissions
 * @var array $granted  role id => keys
 */
use App\Models\User;
use App\Services\PermissionService;

$check = 'h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-600';
?>
<div class="space-y-6">
    <div>
        <p class="eyebrow">Administration</p>
        <h2 class="mt-1 text-2xl">Roles &amp; Permissions</h2>
        <p class="mt-1 max-w-3xl text-sm text-slate-600">What each role may do. Screens also require the matching role, so granting a permission to another role does not open those screens; use this mainly to narrow access. Every change is recorded in the audit log.</p>
    </div>

    <?php foreach ($roles as $role): ?>
        <?php
        $isAdmin = $role['name'] === User::ROLE_SUPER_ADMIN;
        $held = $granted[(int) $role['id']] ?? [];
        ?>
        <section id="role-<?= (int) $role['id'] ?>" class="card" aria-labelledby="role-heading-<?= (int) $role['id'] ?>">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-100 px-5 py-4">
                <div>
                    <h3 id="role-heading-<?= (int) $role['id'] ?>" class="text-lg"><?= e($role['name']) ?></h3>
                    <p class="text-sm text-slate-600"><?= e($role['description'] ?? '') ?></p>
                </div>
                <span class="rounded-full bg-brand-50 px-3 py-1 text-xs font-semibold text-brand-700"><?= $isAdmin ? 'All permissions' : count($held) . ' permissions' ?></span>
            </div>

            <?php if ($isAdmin): ?>
                <p class="px-5 py-4 text-sm text-slate-600">Super Admin holds every permission and cannot be restricted, so the system can always be administered. It still cannot edit the audit log, record payments, or enter grades (docs/04).</p>
            <?php else: ?>
                <form method="POST" action="<?= e(url('/admin/roles/' . $role['id'] . '/permissions')) ?>" class="space-y-4 p-5"
                      data-confirm="Save the permissions of the <?= e($role['name']) ?> role? This takes effect on the next page each <?= e($role['name']) ?> opens.">
                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <?php foreach ($modules as $module => $permissions): ?>
                            <?php if (in_array($module, PermissionService::ADMIN_ONLY_MODULES, true)) { continue; } ?>
                            <fieldset class="rounded-xl border border-brand-100 p-3">
                                <legend class="px-1 font-display text-xs font-bold uppercase tracking-wide text-brand-navy"><?= e(ucfirst($module)) ?></legend>
                                <div class="space-y-1.5">
                                    <?php foreach ($permissions as $permission): ?>
                                        <label class="flex items-start gap-2 text-sm text-slate-700">
                                            <input type="checkbox" name="permissions[]" value="<?= e($permission['key']) ?>" class="mt-0.5 <?= $check ?>" <?= in_array($permission['key'], $held, true) ? 'checked' : '' ?>>
                                            <span><?= e($permission['description']) ?> <code class="text-[11px] text-brand-gray"><?= e($permission['key']) ?></code></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </fieldset>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" class="btn-primary">Save <?= e($role['name']) ?> permissions</button>
                    <?= csrf_field() ?>
                </form>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</div>
