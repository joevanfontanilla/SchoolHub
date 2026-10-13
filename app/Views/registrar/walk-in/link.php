<?php
/**
 * The applicant's one-time set-up link, shown once right after it is issued (docs/09, docs/07).
 *
 * @var int                $applicationId
 * @var App\Models\User    $applicant
 * @var string             $link
 * @var string             $expiresAt
 * @var bool               $newAccount  true right after intake; false when a lost link is re-issued
 * @var string             $continueUrl
 */
?>
<div class="mx-auto max-w-2xl space-y-6">
    <section class="card space-y-5 p-5 sm:p-6" aria-labelledby="link-heading">
        <div class="flex items-start gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-success-bg text-success-text"><?= icon('key', 'h-6 w-6') ?></span>
            <div>
                <h2 id="link-heading" class="text-xl"><?= $newAccount ? 'Account created' : 'New set-up link ready' ?></h2>
                <p class="mt-1 text-sm text-slate-600">
                    Give this link to <strong><?= e($applicant->fullName()) ?></strong> (<?= e($applicant->email) ?>) so they can choose their own password.
                    <?= $newAccount ? 'They do not need it to finish the enrollment today.' : 'Any earlier link no longer works.' ?>
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
            <p><strong>This is the only time the link is shown.</strong> It is not stored. If it is lost, issue a new one from the applicant's page.</p>
        </div>

        <div class="flex flex-wrap gap-3">
            <?php if (App\Core\Mailer::enabled()): ?>
                <form method="POST" action="<?= e(url('/registrar/walk-in/' . $applicationId . '/email-link')) ?>"
                      data-confirm="The link goes to <?= e($applicant->email) ?>. Anyone who can read that mailbox can set the password." data-confirm-title="Email the link?" data-confirm-tone="primary" data-confirm-label="Send email">
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="<?= e(basename($link)) ?>">
                    <button type="submit" class="btn-secondary" data-busy-label="Sending…"><?= icon('mail', 'h-5 w-5') ?> Email the link</button>
                </form>
            <?php endif; ?>
            <a href="<?= e(url($continueUrl)) ?>" class="btn-primary"><?= $newAccount ? 'Continue to documents' : 'Done' ?></a>
        </div>
    </section>
</div>
