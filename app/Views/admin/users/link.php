<?php
/**
 * A one-time password link, shown once right after it is issued (docs/07).
 *
 * @var App\Models\User $account
 * @var string          $link
 * @var string          $expiresAt
 * @var bool            $newAccount
 */
?>
<div class="mx-auto max-w-2xl space-y-6">
    <a href="<?= e(url('/admin/users/' . $account->id)) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; <?= e($account->fullName()) ?></a>

    <section class="card space-y-5 p-5 sm:p-6" aria-labelledby="link-heading">
        <div class="flex items-start gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-success-bg text-success-text"><?= icon('key', 'h-6 w-6') ?></span>
            <div>
                <h2 id="link-heading" class="text-xl"><?= $newAccount ? 'Account created' : 'Password link ready' ?></h2>
                <p class="mt-1 text-sm text-slate-600">
                    <?= $newAccount
                        ? 'Give this link to <strong>' . e($account->fullName()) . '</strong> (' . e($account->username) . ') so they can set their password and start using the account.'
                        : 'Give this link to <strong>' . e($account->fullName()) . '</strong> (' . e($account->username) . ') so they can choose a new password.' ?>
                </p>
            </div>
        </div>

        <div>
            <label for="password-link" class="field-label">One-time link</label>
            <div class="flex flex-col gap-2 sm:flex-row">
                <input id="password-link" type="text" readonly value="<?= e($link) ?>" class="field-input font-mono text-xs" data-select-on-focus>
                <button type="button" class="btn-secondary shrink-0" data-copy="#password-link"><?= icon('document', 'h-5 w-5') ?> Copy</button>
            </div>
            <p class="mt-1.5 text-xs text-brand-gray">Works once, until <?= e(dt($expiresAt)) ?>. Any earlier link for this account no longer works.</p>
        </div>

        <div class="flex items-start gap-2 rounded-xl border border-warning-line bg-warning-bg px-4 py-3 text-sm text-warning-text" role="note">
            <?= icon('alert', 'mt-0.5 h-5 w-5 shrink-0') ?>
            <p><strong>This is the only time the link is shown.</strong> It is not stored and cannot be displayed again. Share it privately with the person, not in a group chat. If it is lost, issue a new one.</p>
        </div>

        <div class="flex flex-wrap gap-3">
            <?php if (App\Core\Mailer::enabled()): ?>
                <form method="POST" action="<?= e(url('/admin/users/' . $account->id . '/email-link')) ?>"
                      data-confirm="The link goes to <?= e($account->email) ?>, the address on this account. Anyone who can read that mailbox can set the password." data-confirm-title="Email the link?" data-confirm-tone="primary" data-confirm-label="Send email">
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="<?= e(basename($link)) ?>">
                    <button type="submit" class="btn-primary" data-busy-label="Sending…"><?= icon('mail', 'h-5 w-5') ?> Email the link</button>
                </form>
            <?php endif; ?>
            <a href="<?= e(url('/admin/users/' . $account->id)) ?>" class="<?= App\Core\Mailer::enabled() ? 'btn-secondary' : 'btn-primary' ?>">Done</a>
        </div>
    </section>
</div>
