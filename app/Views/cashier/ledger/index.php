<?php
/**
 * The general ledger's trial balance for a date range (docs/14).
 *
 * @var string  $from
 * @var string  $to
 * @var ?string $error
 * @var array   $report LedgerService::trialBalance()
 */
use App\Services\FinanceService;
use App\Services\LedgerAccountService;

$money = static fn (int $cents): string => $cents === 0 ? '' : money(FinanceService::money($cents));
$query = http_build_query(['from' => $from, 'to' => $to]);
$totals = $report['totals'];
$th = 'px-3 py-3 font-semibold';
$last = null;
?>
<div class="space-y-6">
    <?= partial('report-header', [
        'eyebrow'  => 'Cashier',
        'tabs'     => ['trial' => ['/cashier/ledger', 'Trial balance', 'banknotes'], 'chart' => ['/cashier/ledger/chart', 'Chart of accounts', 'list-check']],
        'active'   => 'trial',
        'heading'  => 'General Ledger',
        'subtitle' => 'Every account for a period, computed from assessments, payments, and vouchers. Open an account to see its ledger.',
    ]) ?>

    <?php if ($error !== null): ?>
        <p class="rounded-xl border border-warning-line bg-warning-bg px-4 py-3 text-sm text-warning-text" role="status"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="GET" action="<?= e(url('/cashier/ledger')) ?>" class="card flex flex-wrap items-end gap-4 p-4">
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
            <a href="<?= e(url('/cashier/ledger/print?' . $query)) ?>" class="btn-secondary"><?= icon('document', 'h-5 w-5') ?> Print</a>
            <a href="<?= e(url('/cashier/ledger/export?' . $query)) ?>" class="btn-primary"><?= icon('download', 'h-5 w-5') ?> Export CSV</a>
        </div>
    </form>

    <div class="grid gap-4 sm:grid-cols-2 2xl:grid-cols-4">
        <?= component('stat-card', ['label' => 'Debits', 'value' => money(FinanceService::money($totals['debit'])), 'hint' => 'In this period', 'icon' => 'receipt']) ?>
        <?= component('stat-card', ['label' => 'Credits', 'value' => money(FinanceService::money($totals['credit'])), 'hint' => 'In this period', 'icon' => 'receipt']) ?>
        <?= component('stat-card', ['label' => 'Closing debit balances', 'value' => money(FinanceService::money($totals['closingDr'])), 'hint' => 'As of ' . dt($to . ' 00:00:00', 'M j, Y'), 'icon' => 'banknotes']) ?>
        <?= component('stat-card', ['label' => $report['balanced'] ? 'Trial balance' : 'Out of balance', 'value' => $report['balanced'] ? 'Balanced' : 'Check the books', 'hint' => $report['balanced'] ? 'Debits equal credits' : 'Debits and credits differ', 'icon' => $report['balanced'] ? 'calendar-check' : 'alert', 'tone' => $report['balanced'] ? 'success' : 'danger']) ?>
    </div>

    <section class="card" aria-labelledby="tb-heading">
        <h3 id="tb-heading" class="border-b border-brand-100 px-5 py-4 text-base">Trial balance, <?= e(dt($from . ' 00:00:00', 'M j, Y')) ?> to <?= e(dt($to . ' 00:00:00', 'M j, Y')) ?></h3>
        <div class="table-scroll">
            <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                <thead class="bg-brand-50 text-brand-navy">
                    <tr>
                        <th scope="col" class="<?= $th ?>">Code</th>
                        <th scope="col" class="<?= $th ?>">Account</th>
                        <th scope="col" class="<?= $th ?> text-right">Opening Dr</th>
                        <th scope="col" class="<?= $th ?> text-right">Opening Cr</th>
                        <th scope="col" class="<?= $th ?> text-right">Debits</th>
                        <th scope="col" class="<?= $th ?> text-right">Credits</th>
                        <th scope="col" class="<?= $th ?> text-right">Closing Dr</th>
                        <th scope="col" class="<?= $th ?> text-right">Closing Cr</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-50 bg-white">
                    <?php foreach ($report['rows'] as $row): ?>
                        <?php if ($row['type'] !== $last): $last = $row['type']; ?>
                            <tr class="bg-slate-50"><th scope="colgroup" colspan="8" class="px-3 py-2 text-xs font-semibold uppercase tracking-wider text-brand-gray"><?= e(LedgerAccountService::TYPES[$row['type']] ?? $row['type']) ?></th></tr>
                        <?php endif; ?>
                        <tr class="hover:bg-brand-50/40">
                            <td class="whitespace-nowrap px-3 py-2.5 text-brand-gray"><?= e($row['code']) ?></td>
                            <td class="px-3 py-2.5"><a href="<?= e(url('/cashier/ledger/accounts/' . $row['id'] . '?' . $query)) ?>" class="font-semibold text-brand-navy hover:underline"><?= e($row['name']) ?></a></td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums"><?= e($money(max(0, $row['opening']))) ?></td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums"><?= e($money(max(0, -$row['opening']))) ?></td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums"><?= e($money($row['debit'])) ?></td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums"><?= e($money($row['credit'])) ?></td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right font-semibold tabular-nums"><?= e($money(max(0, $row['closing']))) ?></td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right font-semibold tabular-nums"><?= e($money(max(0, -$row['closing']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="bg-brand-50/60 font-semibold text-brand-navy">
                    <tr>
                        <th scope="row" colspan="2" class="px-3 py-3 text-right">Totals</th>
                        <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums"><?= e(money(FinanceService::money($totals['openingDr']))) ?></td>
                        <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums"><?= e(money(FinanceService::money($totals['openingCr']))) ?></td>
                        <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums"><?= e(money(FinanceService::money($totals['debit']))) ?></td>
                        <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums"><?= e(money(FinanceService::money($totals['credit']))) ?></td>
                        <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums"><?= e(money(FinanceService::money($totals['closingDr']))) ?></td>
                        <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums"><?= e(money(FinanceService::money($totals['closingCr']))) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>
    <p class="text-xs text-brand-gray">Accrual basis: fees are revenue when assessed, and a payment moves money from Student Receivables to cash. The ledger is computed from the existing records, so it cannot differ from them.</p>
</div>
