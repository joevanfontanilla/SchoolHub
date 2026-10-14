<?php
/**
 * One account (docs/04, docs/07).
 *
 * @var App\Models\User $account
 * @var array           $logins     recent sign-in attempts
 * @var array           $activity   what this user did
 * @var array           $aboutThem  audit entries about this account
 * @var bool            $self
 * @var bool            $hasSignature whether the user has saved an e-signature (never shown here)
 */
use App\Models\User;
use App\Services\UserAdminService;

$statusBadge = match ($account->status) {
    User::STATUS_ACTIVE  => ['status' => 'APPROVED', 'label' => 'Active'],
    User::STATUS_PENDING => ['status' => 'SCHEDULED', 'label' => 'Pending setup'],
    User::STATUS_LOCKED  => ['status' => 'REJECTED', 'label' => 'Locked'],
    default              => ['status' => 'ARCHIVED', 'label' => 'Disabled'],
};
$lastSuperAdmin = $account->isSuperAdmin() && UserAdminService::otherActiveSuperAdmins($account->id) === 0;
$reasonError = error_for('reason');
$auditRows = static function (array $entries): string {
    if ($entries === []) {
        return '<p class="px-5 py-4 text-sm text-brand-gray">Nothing recorded.</p>';
    }
    $html = '<ul class="divide-y divide-brand-50">';
    foreach ($entries as $entry) {
        $html .= '<li class="flex flex-wrap items-center justify-between gap-2 px-5 py-2.5 text-sm"><a class="font-medium text-brand-navy hover:underline" href="' . e(url('/admin/audit-logs/' . $entry['id'])) . '">' . e($entry['action']) . '</a>'
            . '<span class="text-xs text-brand-gray">' . e(dt($entry['created_at'])) . ' · ' . e($entry['result']) . '</span></li>';
    }
    return $html . '</ul>';
};
?>
<div class="space-y-6">
    <a href="<?= e(url('/admin/users')) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; Users</a>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="eyebrow"><?= e($account->role_name) ?></p>
            <h2 class="mt-1 text-2xl"><?= e($account->fullName()) ?></h2>
            <p class="mt-1 text-sm text-slate-600"><?= e($account->username) ?> · <?= e($account->email) ?></p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <?= component('status-badge', $statusBadge + ['size' => 'md']) ?>
            <?php if (!$account->isStudent()): ?>
                <a href="<?= e(url('/admin/users/' . $account->id . '/e-id')) ?>" class="btn-secondary"><?= icon('shield', 'h-5 w-5') ?> E-ID</a>
            <?php endif; ?>
            <a href="<?= e(url('/admin/users/' . $account->id . '/edit')) ?>" class="btn-secondary"><?= icon('pencil', 'h-5 w-5') ?> Edit</a>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <section class="card p-5" aria-labelledby="details-heading">
                <h3 id="details-heading" class="mb-3 text-base">Account</h3>
                <dl class="grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
                    <div class="flex justify-between gap-3 border-b border-brand-50 py-1.5"><dt class="text-slate-500">Last sign-in</dt><dd class="font-medium"><?= e($account->last_login_at ? dt($account->last_login_at) : 'Never') ?></dd></div>
                    <div class="flex justify-between gap-3 border-b border-brand-50 py-1.5"><dt class="text-slate-500">Failed attempts</dt><dd class="font-medium"><?= e((string) $account->failed_login_attempts) ?></dd></div>
                    <div class="flex justify-between gap-3 border-b border-brand-50 py-1.5"><dt class="text-slate-500">Locked until</dt><dd class="font-medium"><?= e($account->locked_until ? dt($account->locked_until) : '—') ?></dd></div>
                    <div class="flex justify-between gap-3 border-b border-brand-50 py-1.5"><dt class="text-slate-500">Must change password</dt><dd class="font-medium"><?= $account->must_change_password ? 'Yes' : 'No' ?></dd></div>
                    <div class="flex items-center justify-between gap-3 border-b border-brand-50 py-1.5"><dt class="text-slate-500">E-signature</dt><dd class="font-medium">
                        <?php if ($hasSignature): ?>
                            On file
                            <form method="POST" action="<?= e(url('/admin/users/' . $account->id . '/signature/remove')) ?>" class="ml-2 inline"
                                  data-confirm="Their documents print the blank signature line again. They can add a new one on My Account." data-confirm-title="Remove this signature?" data-confirm-tone="danger" data-confirm-label="Remove">
                                <?= csrf_field() ?>
                                <button type="submit" class="text-sm font-semibold text-danger-text hover:underline">Remove</button>
                            </form>
                        <?php else: ?>
                            None
                        <?php endif; ?>
                    </dd></div>
                </dl>
            </section>

            <section class="card" aria-labelledby="logins-heading">
                <div class="flex items-center justify-between border-b border-brand-100 px-5 py-4">
                    <h3 id="logins-heading" class="text-base">Recent sign-in attempts</h3>
                    <a href="<?= e(url('/admin/login-activity?login=' . rawurlencode($account->username))) ?>" class="text-sm font-semibold text-brand-600 hover:underline">All</a>
                </div>
                <?php if ($logins === []): ?>
                    <p class="px-5 py-4 text-sm text-brand-gray">No sign-in attempts recorded.</p>
                <?php else: ?>
                    <ul class="divide-y divide-brand-50">
                        <?php foreach ($logins as $attempt): ?>
                            <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-2.5 text-sm">
                                <span><?= component('status-badge', $attempt['result'] === 'SUCCESS' ? ['status' => 'APPROVED', 'label' => 'Success'] : ['status' => 'REJECTED', 'label' => ucfirst(strtolower(str_replace('_', ' ', $attempt['result'])))]) ?> <span class="ml-2 text-slate-600"><?= e($attempt['ip_address']) ?></span></span>
                                <span class="text-xs text-brand-gray"><?= e(dt($attempt['attempted_at'])) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <div class="grid gap-6 lg:grid-cols-2">
                <section class="card" aria-labelledby="about-heading">
                    <h3 id="about-heading" class="border-b border-brand-100 px-5 py-4 text-base">Changes to this account</h3>
                    <?= $auditRows($aboutThem) ?>
                </section>
                <section class="card" aria-labelledby="activity-heading">
                    <div class="flex items-center justify-between border-b border-brand-100 px-5 py-4">
                        <h3 id="activity-heading" class="text-base">Their recent activity</h3>
                    </div>
                    <?= $auditRows($activity) ?>
                </section>
            </div>
        </div>

        <aside class="space-y-4" aria-label="Account actions">
            <?php if ($account->status !== User::STATUS_DISABLED): ?>
                <section class="card p-5">
                    <h3 class="text-base"><?= $account->status === User::STATUS_PENDING ? 'Set-up link' : 'Password reset link' ?></h3>
                    <p class="mt-1 text-sm text-slate-600">Issue a one-time link the person uses to choose a password. Earlier links stop working.</p>
                    <form method="POST" action="<?= e(url('/admin/users/' . $account->id . '/reset-link')) ?>" class="mt-3"
                          data-confirm="A new one-time link is created for you to give to the person. Any earlier link stops working." data-confirm-title="Issue a password link?" data-confirm-tone="primary" data-confirm-label="Issue link">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn-primary w-full"><?= icon('key', 'h-5 w-5') ?> Issue link</button>
                    </form>
                </section>
            <?php endif; ?>

            <?php if ($account->status === User::STATUS_LOCKED || $account->failed_login_attempts > 0): ?>
                <section class="card p-5">
                    <h3 class="text-base">Unlock</h3>
                    <p class="mt-1 text-sm text-slate-600">Clears the failed attempts so the person can sign in now.</p>
                    <form method="POST" action="<?= e(url('/admin/users/' . $account->id . '/unlock')) ?>" class="mt-3"
                          data-confirm="The failed sign-in attempts are cleared and the person can sign in again now." data-confirm-title="Unlock this account?" data-confirm-tone="warning">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn-secondary w-full"><?= icon('lock', 'h-5 w-5') ?> Unlock account</button>
                    </form>
                </section>
            <?php endif; ?>

            <?php if ($account->status === User::STATUS_DISABLED): ?>
                <section class="card p-5">
                    <h3 class="text-base">Enable</h3>
                    <p class="mt-1 text-sm text-slate-600">Lets the account sign in again.</p>
                    <form method="POST" action="<?= e(url('/admin/users/' . $account->id . '/enable')) ?>" class="mt-3"
                          data-confirm="The account can sign in again with its current password." data-confirm-title="Enable this account?" data-confirm-tone="success">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn-primary w-full">Enable account</button>
                    </form>
                </section>
            <?php elseif ($self): ?>
                <p class="rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-navy">This is your own account. You cannot disable it or change its role.</p>
            <?php elseif ($lastSuperAdmin): ?>
                <p class="rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-navy">This is the last active Super Admin, so it cannot be disabled.</p>
            <?php else: ?>
                <section class="card p-5">
                    <h3 class="text-base">Disable</h3>
                    <p class="mt-1 text-sm text-slate-600">The account can no longer sign in, and any open session ends. Its records stay.</p>
                    <form method="POST" action="<?= e(url('/admin/users/' . $account->id . '/disable')) ?>" class="mt-3 space-y-3" novalidate
                          data-confirm="The person is signed out and can no longer sign in. Their records stay." data-confirm-title="Disable this account?" data-confirm-tone="danger" data-confirm-label="Disable account"
                          data-confirm-requires="#disable-reason" data-confirm-requires-message="Write the reason for disabling the account first. It is recorded in the audit log.">
                        <?= csrf_field() ?>
                        <div>
                            <label for="disable-reason" class="field-label">Reason <span class="text-danger-text" aria-hidden="true">*</span></label>
                            <input id="disable-reason" name="reason" class="field-input <?= $reasonError ? 'field-input-error' : '' ?>" maxlength="255" placeholder="e.g. No longer employed">
                            <?php if ($reasonError): ?><p class="field-error"><span><?= e($reasonError) ?></span></p><?php endif; ?>
                        </div>
                        <button type="submit" class="btn-danger w-full">Disable account</button>
                    </form>
                </section>
            <?php endif; ?>
        </aside>
    </div>
</div>
