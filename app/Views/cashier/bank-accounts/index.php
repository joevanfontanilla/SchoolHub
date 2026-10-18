<?php
/**
 * The school's bank accounts (docs/14).
 *
 * @var array  $accounts
 * @var string $base     this page's address, for the links
 */
$active = count(array_filter($accounts, static fn (array $a): bool => (int) $a['is_active'] === 1));
?>
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">Finance</p>
            <h2 class="mt-1 text-2xl">Payment Accounts</h2>
            <p class="mt-1 max-w-2xl text-sm text-slate-600">The school's bank accounts. Students see the active ones under Pay Online, choose the one they paid into, and you check it against the bank statement when you verify. An account that payments refer to is switched off, never deleted.</p>
        </div>
        <a href="<?= e(url($base . '/create')) ?>" class="btn-primary"><?= icon('plus', 'h-5 w-5') ?> New bank account</a>
    </div>

    <?php if ($active === 0): ?>
        <p class="flex items-start gap-2 rounded-xl border border-warning-line bg-warning-bg px-4 py-3 text-sm text-warning-text" role="note">
            <?= icon('alert', 'h-5 w-5') ?>
            <span><strong>No active bank account yet.</strong> Until you add one, students can only pay by the instructions in System Settings, and a bank payment does not name a bank.</span>
        </p>
    <?php endif; ?>

    <section class="card">
        <?php if ($accounts === []): ?>
            <?= component('empty-state', ['title' => 'No bank accounts yet', 'message' => 'Add the school\'s bank account so students can pay by deposit or transfer.']) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                    <thead class="bg-brand-50 text-brand-navy">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Bank</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Account</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Status</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-50 bg-white">
                        <?php foreach ($accounts as $account): ?>
                            <?php $on = (int) $account['is_active'] === 1; ?>
                            <tr class="<?= $on ? '' : 'text-brand-gray' ?>">
                                <td class="px-4 py-3"><span class="font-semibold <?= $on ? 'text-brand-navy' : '' ?>"><?= e($account['bank_name']) ?></span><?php if ($account['branch']): ?><span class="block text-xs text-brand-gray"><?= e($account['branch']) ?></span><?php endif; ?></td>
                                <td class="px-4 py-3"><?= e($account['account_name']) ?><span class="block font-semibold tabular-nums"><?= e($account['account_number']) ?></span><?php if ($account['note']): ?><span class="block text-xs text-brand-gray"><?= e($account['note']) ?></span><?php endif; ?></td>
                                <td class="whitespace-nowrap px-4 py-3"><?= component('status-badge', ['status' => $on ? 'OPEN' : 'CLOSED', 'label' => $on ? 'Active' : 'Off']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <a href="<?= e(url($base . '/' . $account['id'] . '/edit')) ?>" class="font-semibold text-brand-600 hover:underline">Edit<span class="sr-only"> <?= e($account['bank_name']) ?></span></a>
                                    <form method="POST" action="<?= e(url($base . '/' . $account['id'] . '/toggle')) ?>" class="ml-3 inline"
                                          data-confirm="<?= e($on ? 'Students will no longer see this account. Payments already made to it keep it.' : 'Students will see this account again under Pay Online.') ?>" data-confirm-title="<?= e($on ? 'Switch this account off?' : 'Switch this account on?') ?>" data-confirm-tone="<?= $on ? 'warning' : 'success' ?>" data-confirm-label="<?= $on ? 'Switch off' : 'Switch on' ?>">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="font-semibold <?= $on ? 'text-danger-text' : 'text-brand-600' ?> hover:underline"><?= $on ? 'Switch off' : 'Switch on' ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>