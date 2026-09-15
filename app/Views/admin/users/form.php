<?php
/**
 * Create a staff account, or edit any account (docs/04).
 *
 * @var ?App\Models\User $account
 * @var array            $roles   staff roles
 */
use App\Models\User;

$editing = $account !== null;
$val = static fn (string $field) => (string) old($field, $editing ? (string) ($account->{$field} ?? '') : '');
$selectedRole = (string) old('role', $editing ? (string) $account->role_name : User::ROLE_TEACHER);
$roleError = error_for('role');
$action = $editing ? '/admin/users/' . $account->id . '/edit' : '/admin/users/create';
?>
<div class="mx-auto max-w-2xl space-y-6">
    <a href="<?= e(url($editing ? '/admin/users/' . $account->id : '/admin/users')) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; <?= $editing ? e($account->fullName()) : 'Users' ?></a>
    <div>
        <h2 class="text-2xl"><?= $editing ? 'Edit account' : 'New staff account' ?></h2>
        <?php if (!$editing): ?>
            <p class="mt-1 text-sm text-slate-600">The account starts as <strong>Pending</strong>. You will get a one-time link to give the person, so they can set their own password. Nobody else ever knows it.</p>
        <?php endif; ?>
    </div>

    <form method="POST" action="<?= e(url($action)) ?>" class="card space-y-5 p-5 sm:p-6" novalidate>
        <div class="grid gap-5 sm:grid-cols-3">
            <?= component('field', ['name' => 'first_name', 'label' => 'First name', 'required' => true, 'value' => $val('first_name'), 'autocomplete' => 'off']) ?>
            <?= component('field', ['name' => 'middle_name', 'label' => 'Middle name', 'value' => $val('middle_name'), 'autocomplete' => 'off']) ?>
            <?= component('field', ['name' => 'last_name', 'label' => 'Last name', 'required' => true, 'value' => $val('last_name'), 'autocomplete' => 'off']) ?>
        </div>
        <div class="grid gap-5 sm:grid-cols-2">
            <?= component('field', ['name' => 'username', 'label' => 'Username', 'required' => true, 'value' => $val('username'), 'autocomplete' => 'off', 'help' => 'Letters, numbers, dots, dashes, underscores.']) ?>
            <?= component('field', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'value' => $val('email'), 'autocomplete' => 'off']) ?>
        </div>

        <?php if (!$editing || !$account->isStudent()): ?>
            <div>
                <label for="field-role" class="field-label">Role <span class="text-danger-text" aria-hidden="true">*</span></label>
                <select id="field-role" name="role" class="field-input <?= $roleError ? 'field-input-error' : '' ?>" aria-describedby="field-role-help">
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= e($role) ?>" <?= $selectedRole === $role ? 'selected' : '' ?>><?= e($role) ?></option>
                    <?php endforeach; ?>
                </select>
                <p id="field-role-help" class="mt-1.5 text-xs text-brand-gray">What the person can do is set by their role (see Roles &amp; Permissions). A teacher who still teaches classes keeps the Teacher role.</p>
                <?php if ($roleError): ?><p class="field-error"><span><?= e($roleError) ?></span></p><?php endif; ?>
            </div>
        <?php else: ?>
            <p class="rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-navy">Student accounts keep the Student role.</p>
        <?php endif; ?>

        <div class="flex gap-3">
            <button type="submit" class="btn-primary"><?= $editing ? 'Save account' : 'Create account' ?></button>
            <a href="<?= e(url($editing ? '/admin/users/' . $account->id : '/admin/users')) ?>" class="btn-secondary">Cancel</a>
        </div>
        <?= csrf_field() ?>
    </form>
</div>
