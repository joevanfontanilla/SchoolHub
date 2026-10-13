<?php
/**
 * One account's ledger for a date range (docs/14).
 *
 * @var string  $from
 * @var string  $to
 * @var ?string $error
 * @var array   $ledger LedgerService::accountLedger()
 */
use App\Services\FinanceService;
use App\Services\LedgerAccountService;
use App\Services\LedgerService;

$account = $ledger['account'];
$query = http_build_query(['from' => $from, 'to' => $to]);
$cents = static fn (int $c): string => $c === 0 ? '' : money(FinanceService::money($c));
$balance = static fn (int $net): string => money(FinanceService::money(abs($net))) . ' ' . LedgerService::side($net);
$th = 'px-3 py-3 font-semibold';
?>
<div class="space-y-6">
    <a href="<?= e(url('/cashier/ledger?' . $query)) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; General ledger</a>

    <div>
        <p class="eyebrow"><?= e(LedgerAccountService::TYPES[$account['type']] ?? $account['type']) ?></p>
        <h2 class="mt-1 text-2xl"><?= e($account['code'] . ' · ' . $account['name']) ?></h2>
        <p class="mt-1 text-sm text-slate-600"><?= e(dt($from . ' 00:00:00', 'M j, Y')) ?> to <?= e(dt($to . ' 00:00:00', 'M j, Y')) ?></p>
    </div>

    <?php if ($error !== null): ?>
        <p class="rounded-xl border border-warning-line bg-warning-bg px-4 py-3 text-sm text-warning-text" role="status"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="GET" action="<?= e(url('/cashier/ledger/accounts/' . $account['id'])) ?>" class="card flex flex-wrap items-end gap-4 p-4">
        <div>
            <label for="ledger-from" class="field-label">From</label>
            <input id="ledger-from" type="date" name="from" value="<?= e($from) ?>" class="field-input">
        </div>
        <div>
            <label for="ledger-to" class="field-label">To</label>
            <input id="ledger-to" type="date" name="to" value="<?= e($to) ?>" class="field-input">
        </div>
        <button type="submit" class="btn-secondary"><?= icon('search', 'h-5 w-5') ?> Show</button>
        <div class="ml-auto flex flex-wrap gap-2">
            <a href="<?= e(url('/cashier/ledger/accounts/' . $account['id'] . '/print?' . $query)) ?>" class="btn-secondary"><?= icon('document', 'h-5 w-5') ?> Print</a>
            <a href="<?= e(url('/cashier/ledger/accounts/' . $account['id'] . '/export?' . $query)) ?>" class="btn-primary"><?= icon('download', 'h-5 w-5') ?> Export CSV</a>
        </div>
    </form>

    <section class="card" aria-labelledby="ledger-heading">
        <h3 id="ledger-heading" class="sr-only">Postings</h3>
        <div class="table-scroll">
            <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                <thead class="bg-brand-50 text-brand-navy">
                    <tr>
                        <th scope="col" class="<?= $th ?>">Date</th>
                        <th scope="col" class="<?= $th ?>">Ref</th>
                        <th scope="col" class="<?= $th ?>">Particulars</th>
                        <th scope="col" class="<?= $th ?> text-right">Debit</th>
                        <th scope="col" class="<?= $th ?> text-right">Credit</th>
                        <th scope="col" class="<?= $th ?> text-right">Balance</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-50 bg-white">
                    <tr class="bg-slate-50">
                        <td class="px-3 py-2.5"></td><td class="px-3 py-2.5"></td>
                        <td class="px-3 py-2.5 font-semibold text-brand-navy">Opening balance</td>
                        <td class="px-3 py-2.5"></td><td class="px-3 py-2.5"></td>
                        <td class="whitespace-nowrap px-3 py-2.5 text-right font-semibold tabular-nums"><?= e($balance($ledger['opening'])) ?></td>
                    </tr>
                    <?php foreach ($ledger['rows'] as $row): ?>
                        <tr class="hover:bg-brand-50/40">
                            <td class="whitespace-nowrap px-3 py-2.5"><?= e(d($row['at'])) ?></td>
                            <td class="whitespace-nowrap px-3 py-2.5 font-semibold text-brand-navy"><?= e($row['ref']) ?></td>
                            <td class="px-3 py-2.5"><?= e($row['particulars']) ?></td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums"><?= e($cents($row['debit'])) ?></td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums"><?= e($cents($row['credit'])) ?></td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums"><?= e($balance($row['balance'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($ledger['rows'] === []): ?>
                        <tr><td colspan="6" class="px-3 py-6 text-center text-brand-gray">Nothing was posted to this account in this period.</td></tr>
                    <?php endif; ?>
                </tbody>
                <tfoot class="bg-brand-50/60 font-semibold text-brand-navy">
                    <tr>
                        <th scope="row" colspan="3" class="px-3 py-3 text-right">Closing balance</th>
                        <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums"><?= e(money(FinanceService::money($ledger['debit']))) ?></td>
                        <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums"><?= e(money(FinanceService::money($ledger['credit']))) ?></td>
                        <td class="whitespace-nowrap px-3 py-3 text-right font-display text-base tabular-nums"><?= e($balance($ledger['closing'])) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>
</div>
