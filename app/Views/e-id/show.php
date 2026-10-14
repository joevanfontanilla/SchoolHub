<?php
/**
 * An e-ID on screen (docs/29): the card, whether it is valid now, and print / reissue.
 *
 * @var array            $card
 * @var array            $school
 * @var App\Models\User  $holder     whose e-ID this is
 * @var bool             $own        the signed-in user's own e-ID
 * @var string           $printPath
 * @var ?string          $reissuePath set when the signed-in user may reissue this card
 * @var ?string          $backPath
 */
?>
<div class="mx-auto max-w-4xl space-y-6">
    <div>
        <?php if (!$own && $backPath !== null): ?><a href="<?= e(url($backPath)) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; Back</a><?php endif; ?>
        <p class="eyebrow mt-2"><?= $own ? 'My account' : e($holder->role_name) ?></p>
        <h2 class="mt-1 text-2xl"><?= $own ? 'My e-ID' : e($holder->fullName()) . ' · e-ID' ?></h2>
        <p class="mt-1 text-sm text-slate-600">A card with a QR code. Anyone who scans it sees on the school's website whether the ID is valid, with only the photo, name, ID number, and program or position.</p>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <?= component('status-badge', $card['valid'] ? ['status' => 'APPROVED', 'label' => 'Valid today', 'size' => 'md'] : ['status' => 'REJECTED', 'label' => 'Not valid today', 'size' => 'md']) ?>
        <?php if (!$card['valid']): ?><span class="text-sm text-slate-600"><?= $holder->isStudent() ? 'A student needs an active account and to be enrolled in the current school year.' : 'The account must be active.' ?></span><?php endif; ?>
        <a href="<?= e(url($printPath)) ?>" class="btn-primary ml-auto"><?= icon('document', 'h-5 w-5') ?> Print the card</a>
    </div>

    <?php if (!$card['hasPhoto']): ?>
        <p class="rounded-xl border border-warning-line bg-warning-bg px-4 py-3 text-sm text-warning-text" role="status">
            <?= $own ? 'There is no photo on your account yet, so the card shows your initials. Add a photo on My Account.' : 'This person has no photo yet, so the card shows initials. A photo can be added on their account.' ?>
        </p>
    <?php endif; ?>

    <div class="flex flex-wrap items-start justify-center gap-8 rounded-2xl bg-brand-pale p-6">
        <div>
            <p class="mb-2 text-center text-xs font-semibold uppercase tracking-wider text-brand-gray">Front</p>
            <?= partial('e-id-card', ['card' => $card, 'side' => 'front', 'school' => $school]) ?>
        </div>
        <div>
            <p class="mb-2 text-center text-xs font-semibold uppercase tracking-wider text-brand-gray">Back</p>
            <?= partial('e-id-card', ['card' => $card, 'side' => 'back', 'school' => $school]) ?>
        </div>
    </div>

    <section class="card p-5" aria-labelledby="scan-heading">
        <h3 id="scan-heading" class="text-base">Scans</h3>
        <p class="mt-1 text-sm text-slate-600">
            Scanned <strong><?= e((string) $card['scanCount']) ?></strong> time<?= $card['scanCount'] === 1 ? '' : 's' ?><?= $card['lastScanned'] ? ', last on ' . e(dt($card['lastScanned'])) : '' ?>.
            Only the count and the time are kept, not who scanned it.
        </p>
        <p class="mt-2 break-all text-xs text-brand-gray">The QR opens: <?= e($card['verifyUrl']) ?></p>

        <?php if ($reissuePath !== null): ?>
            <form method="POST" action="<?= e(url($reissuePath)) ?>" class="mt-4"
                  data-confirm="A new code replaces the old one, so the old card's QR stops working at once. Print and hand over the new card." data-confirm-title="Reissue this e-ID?" data-confirm-tone="danger" data-confirm-label="Reissue">
                <?= csrf_field() ?>
                <button type="submit" class="btn-secondary">Reissue for a lost card</button>
                <?php if ($card['reissueCount'] > 0): ?><span class="ml-2 text-xs text-brand-gray">Reissued <?= e((string) $card['reissueCount']) ?> time<?= $card['reissueCount'] === 1 ? '' : 's' ?> before.</span><?php endif; ?>
            </form>
        <?php elseif ($own): ?>
            <p class="mt-3 text-sm text-slate-600">Lost your card? Ask <?= $holder->isStudent() ? 'the registrar' : 'the administrator' ?> to reissue it. The old QR then stops working.</p>
        <?php endif; ?>
    </section>
</div>
