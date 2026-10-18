<?php
/**
 * The sign-in alert (docs/07 "One device at a time"): a request to sign in with the account's password is waiting for the owner's answer. Shown at the top of
 * every portal page, and refreshed in the background by public/assets/app.js.
 *
 * @var App\Models\User|null $user
 */
use App\Services\DeviceSessionService;

$pending = $user === null ? [] : DeviceSessionService::pendingFor($user->id);
if ($pending === []) {
    return;
}
?>
<section aria-label="Sign-in approval" class="mb-5 space-y-3">
    <?php foreach ($pending as $request): ?>
        <div class="flex flex-wrap items-center gap-3 rounded-2xl border border-danger-line bg-white p-3 shadow-sm sm:flex-nowrap" role="alert">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-danger-bg text-danger-text ring-1 ring-danger-line"><?= icon('shield', 'h-5 w-5') ?></span>
            <p class="min-w-0 flex-1 text-sm text-slate-700">
                <span class="block font-display font-bold text-danger-text">Someone is trying to sign in to your account</span>
                <?= e($request['device_label']) ?> &middot; IP address <?= e($request['ip_address']) ?> &middot; <?= e(dt($request['created_at'])) ?>.
                They entered your password. Was this you?
            </p>
            <div class="ml-auto flex gap-2">
                <form method="POST" action="<?= e(url('/security/devices/' . $request['id'] . '/approve')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn-secondary">Yes, it&rsquo;s me</button>
                </form>
                <form method="POST" action="<?= e(url('/security/devices/' . $request['id'] . '/deny')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn-primary">No, deny</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</section>
