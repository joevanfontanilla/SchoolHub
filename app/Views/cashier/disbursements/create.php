<?php
/**
 * Record a disbursement (docs/14): the cashier fills a voucher for money paid out.
 *
 * @var array<int,string> $methods
 * @var array<int,array<string,mixed>> $accounts the expense accounts a voucher can use
 */
$selectedMethod = (string) old('payment_method', 'Cash');
?>
<div class="mx-auto max-w-2xl space-y-6">
    <a href="<?= e(url('/cashier/disbursements')) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; Disbursements</a>

    <div>
        <p class="eyebrow">Cashier</p>
        <h2 class="mt-1 text-2xl">Record a disbursement</h2>
        <p class="mt-1 text-sm text-slate-600">A numbered voucher is issued. Vouchers are permanent — correct a mistake with a reversing voucher, not an edit.</p>
    </div>

    <form method="POST" action="<?= e(url('/cashier/disbursements')) ?>" class="card space-y-5 p-5 sm:p-6" novalidate
          data-confirm="A permanent disbursement voucher will be recorded and added to the Cash Disbursements Journal." data-confirm-title="Record this disbursement?" data-confirm-tone="primary">
        <?= csrf_field() ?>

        <div>
            <label for="payee" class="field-label">Pay to (payee)</label>
            <input id="payee" name="payee" class="field-input <?= error_for('payee') ? 'field-input-error' : '' ?>" value="<?= e((string) old('payee', '')) ?>" autocomplete="off" required>
            <?php if (error_for('payee')): ?><p class="field-error" role="alert"><span><?= e(error_for('payee')) ?></span></p><?php endif; ?>
        </div>

        <div>
            <label for="particulars" class="field-label">Particulars (what it is for)</label>
            <input id="particulars" name="particulars" class="field-input <?= error_for('particulars') ? 'field-input-error' : '' ?>" value="<?= e((string) old('particulars', '')) ?>" autocomplete="off" required>
            <?php if (error_for('particulars')): ?><p class="field-error" role="alert"><span><?= e(error_for('particulars')) ?></span></p><?php endif; ?>
        </div>

        <div>
            <label for="expense_account_id" class="field-label">Spent on (expense account)</label>
            <select id="expense_account_id" name="expense_account_id" class="field-input <?= error_for('expense_account_id') ? 'field-input-error' : '' ?>" required>
                <option value="">Choose…</option>
                <?php foreach ($accounts as $account): ?>
                    <option value="<?= e($account['id']) ?>" <?= (string) old('expense_account_id', '') === (string) $account['id'] ? 'selected' : '' ?>><?= e($account['code'] . ' · ' . $account['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (error_for('expense_account_id')): ?><p class="field-error" role="alert"><span><?= e(error_for('expense_account_id')) ?></span></p><?php endif; ?>
            <p class="mt-1.5 text-xs text-brand-gray">Decides which account the general ledger charges. <a href="<?= e(url('/cashier/ledger/chart')) ?>" class="font-semibold text-brand-600 hover:underline">Add an account</a> if the one you need is missing.</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="amount" class="field-label">Amount (₱)</label>
                <input id="amount" name="amount" inputmode="decimal" class="field-input <?= error_for('amount') ? 'field-input-error' : '' ?>" value="<?= e((string) old('amount', '')) ?>" placeholder="0.00" autocomplete="off" required>
                <?php if (error_for('amount')): ?><p class="field-error" role="alert"><span><?= e(error_for('amount')) ?></span></p><?php endif; ?>
            </div>
            <div>
                <label for="disbursed_on" class="field-label">Date disbursed</label>
                <input id="disbursed_on" name="disbursed_on" type="date" class="field-input <?= error_for('disbursed_on') ? 'field-input-error' : '' ?>" value="<?= e((string) old('disbursed_on', date('Y-m-d'))) ?>" max="<?= e(date('Y-m-d')) ?>" required>
                <?php if (error_for('disbursed_on')): ?><p class="field-error" role="alert"><span><?= e(error_for('disbursed_on')) ?></span></p><?php endif; ?>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="payment_method" class="field-label">Payment method</label>
                <select id="payment_method" name="payment_method" class="field-input <?= error_for('payment_method') ? 'field-input-error' : '' ?>">
                    <?php foreach ($methods as $method): ?>
                        <option value="<?= e($method) ?>" <?= $method === $selectedMethod ? 'selected' : '' ?>><?= e($method) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (error_for('payment_method')): ?><p class="field-error" role="alert"><span><?= e(error_for('payment_method')) ?></span></p><?php endif; ?>
            </div>
            <div>
                <label for="reference_number" class="field-label">Reference / check no. <span class="font-normal text-brand-gray">(optional)</span></label>
                <input id="reference_number" name="reference_number" class="field-input <?= error_for('reference_number') ? 'field-input-error' : '' ?>" value="<?= e((string) old('reference_number', '')) ?>" autocomplete="off">
                <?php if (error_for('reference_number')): ?><p class="field-error" role="alert"><span><?= e(error_for('reference_number')) ?></span></p><?php endif; ?>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="btn-primary">Record voucher</button>
            <a href="<?= e(url('/cashier/disbursements')) ?>" class="btn-secondary">Cancel</a>
        </div>
    </form>
</div>
