<?php
/**
 * No e-ID yet (docs/29).
 *
 * @var App\Models\User $holder
 * @var bool            $own
 * @var string          $reason
 * @var ?string         $backPath
 */
?>
<div class="mx-auto max-w-2xl space-y-4">
    <?php if (!$own && $backPath !== null): ?><a href="<?= e(url($backPath)) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; Back</a><?php endif; ?>
    <p class="eyebrow"><?= $own ? 'My account' : e($holder->role_name) ?></p>
    <h2 class="text-2xl"><?= $own ? 'My e-ID' : e($holder->fullName()) . ' · e-ID' ?></h2>
    <?= component('empty-state', ['title' => 'No e-ID yet', 'message' => $reason]) ?>
</div>
