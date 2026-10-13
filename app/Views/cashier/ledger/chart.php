<?php
/**
 * The chart of accounts (docs/14): the accounts the general ledger posts to.
 *
 * @var array<int,array<string,mixed>> $accounts
 * @var array<string,string>           $types
 */
use App\Core\Auth;

$canManage = Auth::can('disbursements.manage');
$last = null;
$origin = static fn (array $a): string => $a['fee_key'] !== null ? 'Fee schedule' : ((int) $a['is_system'] === 1 ? 'Ledger' : 'Cashier');
?>
<div class="space-y-6">
    <?= partial('report-header', [
        'eyebrow'  => 'Cashier',
        'tabs'     => ['trial' => ['/cashier/ledger', 'Trial balance', 'banknotes'], 'chart' => ['/cashier/ledger/chart', 'Chart of accounts', 'list-check']],
        'active'   => 'chart',
        'heading'  => 'Chart of Accounts',
        'subtitle' => 'The accounts the ledger posts to. Each fee line has its own revenue account; you add the expense accounts a voucher can be charged to.',
    ]) ?>

    <?php if ($canManage): ?>
        <section id="add" class="card p-5" aria-labelledby="add-heading">
            <h3 id="add-heading" class="text-base">Add an expense account</h3>
            <form method="POST" action="<?= e(url('/cashier/ledger/chart')) ?>" class="mt-3 grid gap-4 sm:grid-cols-3" novalidate>
                <?= csrf_field() ?>
                <?= component('field', ['name' => 'code', 'label' => 'Code', 'required' => true, 'value' => old('code', ''), 'help' => 'Four digits, 5000 to 5899.']) ?>
                <div class="sm:col-span-2"><?= component('field', ['name' => 'name', 'label' => 'Name', 'required' => true, 'value' => old('name', ''), 'help' => 'Example: Internet Subscription']) ?></div>
                <div class="sm:col-span-3"><button type="submit" class="btn-primary"><?= icon('plus', 'h-5 w-5') ?> Add account</button></div>
            </form>
        </section>
    <?php endif; ?>

    <section class="card" aria-labelledby="accounts-heading">
        <h3 id="accounts-heading" class="sr-only">Accounts</h3>
        <div class="table-scroll">
            <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                <thead class="bg-brand-50 text-brand-navy">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">Code</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Account</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Kept by</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Status</th>
                        <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-50 bg-white">
                    <?php foreach ($accounts as $account): ?>
                        <?php if ($account['type'] !== $last): $last = $account['type']; ?>
                            <tr class="bg-slate-50"><th scope="colgroup" colspan="5" class="px-4 py-2 text-xs font-semibold uppercase tracking-wider text-brand-gray"><?= e($types[$account['type']] ?? $account['type']) ?></th></tr>
                        <?php endif; ?>
                        <tr>
                            <td class="whitespace-nowrap px-4 py-2.5 text-brand-gray"><?= e($account['code']) ?></td>
                            <td class="px-4 py-2.5"><a href="<?= e(url('/cashier/ledger/accounts/' . $account['id'])) ?>" class="font-semibold text-brand-navy hover:underline"><?= e($account['name']) ?></a></td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-slate-600"><?= e($origin($account)) ?></td>
                            <td class="whitespace-nowrap px-4 py-2.5"><?= component('status-badge', ['status' => (int) $account['is_active'] === 1 ? 'OPEN' : 'CLOSED', 'label' => (int) $account['is_active'] === 1 ? 'Active' : 'Off']) ?></td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-right">
                                <?php if ($canManage && (int) $account['is_system'] === 0 && $account['type'] === 'EXPENSE'): ?>
                                    <form method="POST" action="<?= e(url('/cashier/ledger/chart/' . $account['id'] . '/toggle')) ?>">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="text-sm font-semibold text-brand-600 hover:underline"><?= (int) $account['is_active'] === 1 ? 'Switch off' : 'Switch on' ?><span class="sr-only"> <?= e($account['name']) ?></span></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <p class="text-xs text-brand-gray">An account that has postings is never deleted. Switching an expense account off only stops new vouchers from choosing it.</p>
</div>
