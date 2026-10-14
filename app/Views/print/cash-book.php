<?php
/**
 * Cash book printout (docs/14): the sheet on one page.
 *
 * @var array $book CashBookService::month()
 */
?>
<section class="mt-6 space-y-4">
    <div>
        <h1 class="font-display text-2xl font-extrabold uppercase tracking-wide text-brand-navy">Cash Book</h1>
        <p class="mt-1 text-sm text-slate-600"><?= e($book['account'] === 'BANK' ? 'Bank and E-wallets' : 'Cash on Hand') ?> · for the month of <?= e(dt($book['from'] . ' 00:00:00', 'F Y')) ?></p>
    </div>
    <?= partial('cashier/cash-book-sheet', ['book' => $book]) ?>

    <?= partial('cashier/prepared-by', ['user' => $user]) ?>
</section>
