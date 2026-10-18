<?php
/**
 * The school bank account a bank payment went to (docs/14). Shown for Bank deposit and Bank transfer; the script hides it for other methods.
 *
 * @var array  $bankAccounts active accounts; nothing is shown when there are none
 * @var string $suffix       makes the field id unique when the page has two forms
 */
$suffix = $suffix ?? '';
$error = error_for('bank_account_id');
if ($bankAccounts === []) {
    return;
}
?>
<div data-bank-field>
    <label for="field-bank_account_id<?= e($suffix) ?>" class="field-label">School bank account <span class="font-normal text-brand-gray">(for bank deposits and transfers)</span></label>
    <select id="field-bank_account_id<?= e($suffix) ?>" name="bank_account_id" class="field-input <?= $error ? 'field-input-error' : '' ?>">
        <option value="">Choose the bank account…</option>
        <?php foreach ($bankAccounts as $account): ?>
            <option value="<?= e($account['id']) ?>" <?= (string) old('bank_account_id') === (string) $account['id'] ? 'selected' : '' ?>><?= e($account['bank_name'] . ' · ' . $account['account_number'] . ' (' . $account['account_name'] . ')') ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($error): ?><p class="field-error"><span><?= e($error) ?></span></p><?php endif; ?>
</div>