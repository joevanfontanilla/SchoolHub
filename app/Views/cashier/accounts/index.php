<?php
/**
 * Student accounts (docs/14).
 *
 * @var string $q
 * @var string $balance
 * @var array  $accounts
 */
?>
<div class="space-y-6">
    <div>
        <p class="eyebrow">Cashier</p>
        <h2 class="mt-1 text-2xl">Student Accounts</h2>
        <p class="mt-1 text-sm text-slate-600">Every issued assessment with its payments and balance. Open one to record a payment made at the window.</p>
    </div>

    <form method="GET" action="<?= e(url('/cashier/accounts')) ?>" class="card flex flex-wrap items-end gap-4 p-4">
        <div class="min-w-[16rem] flex-1">
            <label for="accounts-q" class="field-label">Search</label>
            <input id="accounts-q" type="search" name="q" value="<?= e($q) ?>" placeholder="Name, student no., application or assessment no." class="field-input">
        </div>
        <div class="min-w-[10rem]">
            <label for="accounts-balance" class="field-label">Balance</label>
            <select id="accounts-balance" name="balance" class="field-input">
                <option value="">All</option>
                <option value="open" <?= $balance === 'open' ? 'selected' : '' ?>>With a balance</option>
                <option value="paid" <?= $balance === 'paid' ? 'selected' : '' ?>>Fully paid</option>
            </select>
        </div>
        <button type="submit" class="btn-secondary"><?= icon('search', 'h-5 w-5') ?> Search</button>
    </form>

    <section class="card">
        <?php if ($accounts === []): ?>
            <?= component('empty-state', ['title' => 'No accounts found']) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                    <thead class="bg-brand-50 text-brand-navy">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Student</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Term</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Amount due</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Paid</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Balance</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Status</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-50 bg-white">
                        <?php foreach ($accounts as $row): ?>
                            <tr class="hover:bg-brand-50/40">
                                <td class="px-4 py-3"><span class="font-semibold text-brand-navy"><?= e($row['last_name'] . ', ' . $row['first_name']) ?></span><span class="block text-xs text-brand-gray"><?= e($row['student_number'] ?? $row['application_number']) ?> · <?= e($row['program_code']) ?></span></td>
                                <td class="whitespace-nowrap px-4 py-3"><?= e($row['term_name']) ?><span class="block text-xs text-brand-gray"><?= e($row['assessment_number']) ?></span></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums"><?= e(money(\App\Services\FinanceService::money(max(0, \App\Services\FinanceService::dueCents($row))))) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums"><?= e(money($row['total_paid'])) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums text-brand-navy"><?= e(money($row['balance'])) ?></td>
                                <td class="whitespace-nowrap px-4 py-3"><span class="flex flex-wrap gap-1"><?= component('status-badge', ['status' => $row['payment_status']]) ?><?php if ($row['enrollment_state'] === 'ENROLLED'): ?><?= component('status-badge', ['status' => 'ENROLLED']) ?><?php endif; ?></span></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right"><a href="<?= e(url('/cashier/accounts/' . $row['id'])) ?>" class="font-semibold text-brand-600 hover:underline">Open<span class="sr-only"> account of <?= e($row['first_name'] . ' ' . $row['last_name']) ?></span></a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
