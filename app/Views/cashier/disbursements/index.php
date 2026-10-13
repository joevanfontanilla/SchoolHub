<?php
/**
 * Cash disbursements (docs/14): recent vouchers, with a link to record a new one and to the
 * Cash Disbursements Journal.
 *
 * @var array<int,array<string,mixed>> $vouchers
 * @var array<int,array<string,mixed>> $accounts the expense accounts a voucher can use
 */
?>
<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="eyebrow">Cashier</p>
            <h2 class="mt-1 text-2xl">Disbursements</h2>
            <p class="mt-1 text-sm text-slate-600">Money paid out by the cashier, each on a numbered voucher. Vouchers feed the Cash Disbursements Journal.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="<?= e(url('/cashier/reports/disbursements-journal')) ?>" class="btn-secondary"><?= icon('banknotes', 'h-5 w-5') ?> Disbursements Journal</a>
            <a href="<?= e(url('/cashier/disbursements/create')) ?>" class="btn-primary"><?= icon('plus', 'h-5 w-5') ?> Record a disbursement</a>
        </div>
    </div>

    <section class="card" aria-labelledby="dv-heading">
        <h3 id="dv-heading" class="border-b border-brand-100 px-5 py-4 text-base">Recent vouchers</h3>
        <?php if ($vouchers === []): ?>
            <?= component('empty-state', ['title' => 'No disbursements yet', 'message' => 'Record the first one with "Record a disbursement".']) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                    <thead class="bg-brand-50 text-brand-navy">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Date</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Voucher No.</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Payee</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Particulars</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Expense account</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Method</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Amount</th>
                            <th scope="col" class="px-4 py-3 font-semibold"><span class="sr-only">Voucher</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-50 bg-white">
                        <?php foreach ($vouchers as $row): ?>
                            <tr class="hover:bg-brand-50/40">
                                <td class="whitespace-nowrap px-4 py-3"><?= e(dt($row['disbursed_at'], 'M j, Y')) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 font-medium text-brand-navy"><?= e($row['voucher_number']) ?></td>
                                <td class="px-4 py-3"><?= e($row['payee']) ?></td>
                                <td class="px-4 py-3"><?= e($row['particulars']) ?></td>
                                <td class="px-4 py-3">
                                    <?php if ($row['expense_account_id'] !== null): ?>
                                        <?= e($row['expense_code'] . ' · ' . $row['expense_name']) ?>
                                    <?php else: ?>
                                        <form method="POST" action="<?= e(url('/cashier/disbursements/' . $row['id'] . '/category')) ?>" class="flex flex-wrap items-center gap-2"
                                              data-confirm="The voucher is charged to this account in the general ledger. It can be set only once." data-confirm-title="Categorize this voucher?" data-confirm-tone="primary" data-confirm-label="Categorize">
                                            <?= csrf_field() ?>
                                            <label for="category-<?= e($row['id']) ?>" class="sr-only">Expense account for voucher <?= e($row['voucher_number']) ?></label>
                                            <select id="category-<?= e($row['id']) ?>" name="expense_account_id" class="field-input !w-auto !py-1 text-xs" required>
                                                <option value="">Uncategorized</option>
                                                <?php foreach ($accounts as $account): ?>
                                                    <option value="<?= e($account['id']) ?>"><?= e($account['code'] . ' · ' . $account['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="text-xs font-semibold text-brand-600 hover:underline">Set</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3"><?= e($row['payment_method']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums text-brand-navy"><?= e(money($row['amount'])) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <?= partial('preview-link', ['path' => '/cashier/disbursements/' . $row['id'] . '/voucher', 'kind' => 'page', 'title' => 'Voucher ' . $row['voucher_number'], 'label' => 'Voucher', 'icon' => 'document', 'group' => 'disbursement-vouchers']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
