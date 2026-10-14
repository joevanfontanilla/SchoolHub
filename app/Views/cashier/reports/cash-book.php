<?php
/**
 * The cash book (docs/14): a month of Cash on Hand or of Bank and E-wallets, in the school's own layout,
 * with the entries that feed it: opening balances and bank deposits/withdrawals.
 *
 * @var array $book      CashBookService::month()
 * @var array $openings  stored opening balances by CASH / BANK
 * @var array $recent    recent deposits and withdrawals
 * @var bool  $canRecord may record opening balances and deposits/withdrawals
 */
use App\Services\CashMovementService;

$query = http_build_query(['month' => $book['month'], 'account' => $book['account']]);
$accountName = CashMovementService::ACCOUNTS[$book['account']];
$opening = $openings[$book['account']] ?? null;
?>
<div class="space-y-6">
    <?= partial('cashier/report-header', ['active' => 'book', 'heading' => 'Cash Book', 'subtitle' => 'A month of cash in and out, like the paper cash book. Computed from payments, vouchers, and bank entries, so it always agrees with the ledger.']) ?>

    <form method="GET" data-auto-submit action="<?= e(url('/cashier/reports/cash-book')) ?>" class="card flex flex-wrap items-end gap-4 p-4">
        <div>
            <label for="book-month" class="field-label">Month</label>
            <input id="book-month" type="month" name="month" value="<?= e($book['month']) ?>" max="<?= e(date('Y-m')) ?>" class="field-input">
        </div>
        <div class="min-w-[14rem]">
            <label for="book-account" class="field-label">Account</label>
            <select id="book-account" name="account" class="field-input">
                <?php foreach (CashMovementService::ACCOUNTS as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $key === $book['account'] ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-secondary"><?= icon('search', 'h-5 w-5') ?> Show</button>
        <div class="ml-auto flex flex-wrap gap-2">
            <a href="<?= e(url('/cashier/reports/cash-book/print?' . $query)) ?>" class="btn-secondary"><?= icon('document', 'h-5 w-5') ?> Print</a>
            <a href="<?= e(url('/cashier/reports/cash-book/export?' . $query)) ?>" class="btn-primary"><?= icon('download', 'h-5 w-5') ?> Export CSV</a>
        </div>
    </form>

    <section class="card p-5" aria-labelledby="sheet-heading">
        <h3 id="sheet-heading" class="mb-3 text-base"><?= e($accountName) ?>, <?= e(dt($book['from'] . ' 00:00:00', 'F Y')) ?></h3>
        <?= partial('cashier/cash-book-sheet', ['book' => $book]) ?>
    </section>

    <?php if ($canRecord): ?>
        <div class="grid gap-6 lg:grid-cols-2">
            <section id="record" class="card p-5" aria-labelledby="move-heading">
                <h3 id="move-heading" class="flex items-center gap-2 text-base"><span class="text-brand-600"><?= icon('banknotes', 'h-5 w-5') ?></span> Bank deposit or withdrawal</h3>
                <p class="mt-1 text-sm text-slate-600">Moving money between the drawer and the bank. A deposit is Cash outflow and Bank inflow; a withdrawal is the reverse. It is recorded now and cannot be edited; a mistake is fixed with the opposite entry.</p>
                <form method="POST" action="<?= e(url('/cashier/reports/cash-book/movements')) ?>" class="mt-4 grid gap-4 sm:grid-cols-2" novalidate
                      data-confirm="This is recorded now and cannot be edited. A mistake is fixed with the opposite entry." data-confirm-title="Record this entry?" data-confirm-tone="primary" data-confirm-label="Record">
                    <?= csrf_field() ?>
                    <input type="hidden" name="return_account" value="<?= e($book['account']) ?>">
                    <div>
                        <label for="field-kind" class="field-label">Type <span class="text-danger-text" aria-hidden="true">*</span><span class="sr-only">(required)</span></label>
                        <select id="field-kind" name="kind" class="field-input">
                            <option value="DEPOSIT" <?= old('kind', 'DEPOSIT') === 'DEPOSIT' ? 'selected' : '' ?>>Deposit to bank (cash to bank)</option>
                            <option value="WITHDRAWAL" <?= old('kind') === 'WITHDRAWAL' ? 'selected' : '' ?>>Withdraw from bank (bank to cash)</option>
                        </select>
                    </div>
                    <?= component('field', ['name' => 'amount', 'label' => 'Amount (₱)', 'type' => 'number', 'required' => true, 'value' => old('amount')]) ?>
                    <?= component('field', ['name' => 'reference_number', 'label' => 'Reference number', 'value' => old('reference_number'), 'help' => 'The deposit slip number.']) ?>
                    <?= component('field', ['name' => 'remarks', 'label' => 'Remarks', 'value' => old('remarks')]) ?>
                    <div class="sm:col-span-2"><button type="submit" class="btn-primary" data-busy-label="Recording…">Record</button></div>
                </form>

                <?php if ($recent !== []): ?>
                    <h4 class="mt-6 text-sm font-semibold text-brand-navy">Recent entries</h4>
                    <ul class="mt-2 divide-y divide-brand-50 text-sm">
                        <?php foreach ($recent as $row): ?>
                            <li class="flex flex-wrap justify-between gap-2 py-2">
                                <span><span class="font-semibold text-brand-navy"><?= e($row['movement_number']) ?></span> · <?= e($row['movement_type'] === 'DEPOSIT' ? 'Deposit to bank' : 'Withdrawal from bank') ?><?= $row['reference_number'] ? ' · ' . e($row['reference_number']) : '' ?> · <?= e(dt($row['entered_at'])) ?></span>
                                <span class="font-semibold tabular-nums"><?= e(money($row['amount'])) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <section id="opening" class="card p-5" aria-labelledby="opening-heading">
                <h3 id="opening-heading" class="flex items-center gap-2 text-base"><span class="text-brand-600"><?= icon('calendar-check', 'h-5 w-5') ?></span> Opening balance, <?= e($accountName) ?></h3>
                <p class="mt-1 text-sm text-slate-600">What the school held on the day it started using the system. It becomes the first Balance C/F and is posted to the ledger. Changes are audited.</p>
                <?php if ($opening !== null): ?>
                    <p class="mt-3 rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-navy">Now: <strong><?= e(money($opening['amount'])) ?></strong> as of <?= e(d($opening['entered_at'])) ?>.</p>
                <?php endif; ?>
                <form method="POST" action="<?= e(url('/cashier/reports/cash-book/opening')) ?>" class="mt-4 grid gap-4 sm:grid-cols-2" novalidate
                      data-confirm="This sets the first Balance C/F for <?= e($accountName) ?> and posts to the ledger. Any later change is audited." data-confirm-title="Save the opening balance?" data-confirm-tone="primary" data-confirm-label="Save">
                    <?= csrf_field() ?>
                    <input type="hidden" name="account" value="<?= e($book['account']) ?>">
                    <?= component('field', ['name' => 'opening_amount', 'label' => 'Amount (₱)', 'type' => 'number', 'required' => true, 'value' => old('opening_amount', $opening !== null ? $opening['amount'] : '')]) ?>
                    <?= component('field', ['name' => 'as_of', 'label' => 'As of', 'type' => 'date', 'required' => true, 'value' => old('as_of', $opening !== null ? substr((string) $opening['entered_at'], 0, 10) : date('Y-m-01'))]) ?>
                    <div class="sm:col-span-2"><button type="submit" class="btn-secondary" data-busy-label="Saving…"><?= $opening !== null ? 'Change' : 'Save' ?> opening balance</button></div>
                </form>
            </section>
        </div>
    <?php endif; ?>
</div>
