<?php
/**
 * Add or edit one bank account (docs/14).
 *
 * @var ?array $account
 * @var string $base
 */
$editing = $account !== null;
$val = static fn (string $field, string $default = '') => old($field, $account[$field] ?? $default);
?>
<div class="mx-auto max-w-2xl space-y-6">
    <a href="<?= e(url($base)) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; Payment Accounts</a>
    <div>
        <h2 class="text-2xl"><?= $editing ? 'Edit ' . e($account['bank_name']) : 'New bank account' ?></h2>
        <p class="mt-1 text-sm text-slate-600">What students see when they pay by bank. Check the account number twice: a wrong one sends their money elsewhere.</p>
    </div>
    <form method="POST" action="<?= e(url($editing ? $base . '/' . $account['id'] . '/edit' : $base . '/create')) ?>" class="card space-y-5 p-5 sm:p-6" novalidate>
        <?= csrf_field() ?>
        <div class="grid gap-5 sm:grid-cols-2">
            <?= component('field', ['name' => 'bank_name', 'label' => 'Bank', 'required' => true, 'value' => $val('bank_name'), 'help' => 'e.g. Landbank of the Philippines']) ?>
            <?= component('field', ['name' => 'branch', 'label' => 'Branch', 'value' => $val('branch'), 'help' => 'Optional, e.g. Baler branch']) ?>
        </div>
        <?= component('field', ['name' => 'account_name', 'label' => 'Account name', 'required' => true, 'value' => $val('account_name'), 'help' => 'Exactly as the bank has it.']) ?>
        <?= component('field', ['name' => 'account_number', 'label' => 'Account number', 'required' => true, 'value' => $val('account_number'), 'help' => 'Digits only, with spaces or dashes if the bank prints them.']) ?>
        <?= component('field', ['name' => 'note', 'label' => 'Note for students', 'value' => $val('note'), 'help' => 'Optional, e.g. "Write the student\'s name on the deposit slip."']) ?>
        <?= component('field', ['name' => 'sort_order', 'label' => 'Order', 'type' => 'number', 'value' => $val('sort_order', '0'), 'help' => 'Smaller numbers are listed first.']) ?>
        <div class="flex gap-3">
            <button type="submit" class="btn-primary"><?= $editing ? 'Save bank account' : 'Add bank account' ?></button>
            <a href="<?= e(url($base)) ?>" class="btn-secondary">Cancel</a>
        </div>
    </form>
</div>