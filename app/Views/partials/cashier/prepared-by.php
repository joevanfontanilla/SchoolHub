<?php
/**
 * The signature block that ends a cashier printout (docs/14): who prepared it, and a line for the
 * person who approves it.
 *
 * @var \App\Models\User $user the signed-in cashier
 */
?>
<div class="mt-12 grid gap-8 sm:grid-cols-2 print:grid-cols-2">
    <div class="text-center text-sm">
        <?= partial('signature-image', ['userId' => $user->id, 'name' => $user->fullName()]) ?>
        <p class="border-b border-slate-400 pb-1 font-semibold"><?= e($user->fullName()) ?></p>
        <p class="mt-1 text-slate-500">Prepared by (Cashier)</p>
    </div>
    <div class="text-center text-sm">
        <p class="border-b border-slate-400 pb-1">&nbsp;</p>
        <p class="mt-1 text-slate-500">Approved by</p>
    </div>
</div>
