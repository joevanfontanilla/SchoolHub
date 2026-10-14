<?php
/**
 * Payment breakdown (docs/14): where the payments of a period went, by fee line, method, and day.
 *
 * @var string  $from
 * @var string  $to
 * @var ?string $error
 * @var ?int    $programId
 * @var ?int    $yearLevelId
 * @var array   $programs
 * @var array   $yearLevels
 * @var array   $report  PaymentBreakdownService::report()
 */
use App\Services\FinanceService;

$query = http_build_query(array_filter(['from' => $from, 'to' => $to, 'program_id' => $programId, 'year_level_id' => $yearLevelId]));
$money = static fn (int $cents): string => ($cents < 0 ? '-' : '') . money(FinanceService::money(abs($cents)));
$th = 'px-4 py-3 font-semibold';
?>
<div class="space-y-6">
    <?= partial('cashier/report-header', ['active' => 'breakdown', 'heading' => 'Payment Breakdown', 'subtitle' => 'Where the payments of a period went: by fee line, by payment method, and by day.']) ?>

    <form method="GET" data-auto-submit action="<?= e(url('/cashier/reports/payment-breakdown')) ?>" class="card flex flex-wrap items-end gap-4 p-4">
        <div>
            <label for="pb-from" class="field-label">From</label>
            <input id="pb-from" type="date" name="from" value="<?= e($from) ?>" max="<?= e(date('Y-m-d')) ?>" class="field-input">
        </div>
        <div>
            <label for="pb-to" class="field-label">To</label>
            <input id="pb-to" type="date" name="to" value="<?= e($to) ?>" class="field-input">
        </div>
        <div class="min-w-[10rem]">
            <label for="pb-program" class="field-label">Program</label>
            <select id="pb-program" name="program_id" class="field-input">
                <option value="">All programs</option>
                <?php foreach ($programs as $program): ?>
                    <option value="<?= e($program['id']) ?>" <?= (int) $program['id'] === $programId ? 'selected' : '' ?>><?= e($program['code']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="min-w-[10rem]">
            <label for="pb-year" class="field-label">Year level</label>
            <select id="pb-year" name="year_level_id" class="field-input">
                <option value="">All year levels</option>
                <?php foreach ($yearLevels as $yearLevel): ?>
                    <option value="<?= e($yearLevel['id']) ?>" <?= (int) $yearLevel['id'] === $yearLevelId ? 'selected' : '' ?>><?= e($yearLevel['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-secondary"><?= icon('search', 'h-5 w-5') ?> Show</button>
        <div class="ml-auto flex flex-wrap gap-2">
            <a href="<?= e(url('/cashier/reports/payment-breakdown/print?' . $query)) ?>" class="btn-secondary"><?= icon('document', 'h-5 w-5') ?> Print</a>
            <a href="<?= e(url('/cashier/reports/payment-breakdown/export?' . $query)) ?>" class="btn-primary"><?= icon('download', 'h-5 w-5') ?> Export CSV</a>
        </div>
    </form>

    <?php if ($error !== null): ?>
        <p class="flex items-center gap-2 rounded-xl border border-warning-line bg-warning-bg px-4 py-3 text-sm text-warning-text" role="status"><?= icon('alert', 'h-5 w-5 shrink-0') ?> <?= e($error) ?></p>
    <?php endif; ?>

    <div class="grid gap-4 sm:grid-cols-3">
        <?= component('stat-card', ['label' => 'Collected', 'value' => $money($report['collected']), 'hint' => 'Net of reversals', 'icon' => 'banknotes', 'tone' => 'success']) ?>
        <?= component('stat-card', ['label' => 'Accounts that paid', 'value' => (string) $report['accounts'], 'hint' => 'In this period', 'icon' => 'users']) ?>
        <?= component('stat-card', ['label' => 'Fee lines', 'value' => (string) count($report['lines']), 'hint' => 'With a charge or a payment', 'icon' => 'receipt']) ?>
    </div>

    <section class="card" aria-labelledby="lines-heading">
        <h3 id="lines-heading" class="border-b border-brand-100 px-5 py-4 text-base">By fee line</h3>
        <?php if ($report['lines'] === []): ?>
            <?= component('empty-state', ['title' => 'No payments in this period', 'message' => 'Change the dates or filters to see where payments went.']) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                    <caption class="sr-only">Payments collected in the period, by fee line</caption>
                    <thead class="bg-brand-50 text-brand-navy">
                        <tr>
                            <th scope="col" class="<?= $th ?>">Fee line</th>
                            <th scope="col" class="<?= $th ?> text-right">Assessed</th>
                            <th scope="col" class="<?= $th ?> text-right">Collected</th>
                            <th scope="col" class="<?= $th ?> text-right">Share</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-50 bg-white">
                        <?php foreach ($report['lines'] as $row): ?>
                            <tr class="hover:bg-brand-50/40">
                                <td class="px-4 py-3 font-medium text-brand-navy"><?= e($row['label']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums"><?= e($money($row['assessed'])) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums <?= $row['collected'] < 0 ? 'text-danger-text' : '' ?>"><?= e($money($row['collected'])) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-slate-600"><?= e(number_format($row['share'], 1)) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($report['discounts'] !== 0): ?>
                            <tr>
                                <td class="px-4 py-3 text-slate-600">Discounts, scholarships, and corrections</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-slate-600"><?= e($money($report['discounts'])) ?></td>
                                <td class="px-4 py-3"></td><td class="px-4 py-3"></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="bg-brand-50/60 font-semibold text-brand-navy">
                        <tr>
                            <th scope="row" class="px-4 py-3 text-right">Total</th>
                            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums"><?= e($money($report['assessed'])) ?></td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-display text-base tabular-nums"><?= e($money($report['collected'])) ?></td>
                            <td class="px-4 py-3 text-right">100%</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="border-t border-brand-100 px-5 py-3 text-xs text-brand-gray">Assessed is what the accounts that paid in this period were charged. A payment is split by the rule in the finance guide: the old account first, then across the fee lines in proportion to what each was charged. This is a display of where money went; it does not change any balance.</p>
        <?php endif; ?>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="card" aria-labelledby="methods-heading">
            <h3 id="methods-heading" class="border-b border-brand-100 px-5 py-4 text-base">By payment method</h3>
            <?php if ($report['methods'] === []): ?>
                <p class="px-5 py-4 text-sm text-brand-gray">Nothing collected.</p>
            <?php else: ?>
                <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                    <caption class="sr-only">Collected by payment method</caption>
                    <thead class="bg-brand-50 text-brand-navy"><tr><th scope="col" class="<?= $th ?>">Method</th><th scope="col" class="<?= $th ?> text-right">Payments</th><th scope="col" class="<?= $th ?> text-right">Collected</th></tr></thead>
                    <tbody class="divide-y divide-brand-50 bg-white">
                        <?php foreach ($report['methods'] as $method => $row): ?>
                            <tr><td class="px-4 py-3"><?= e($method) ?></td><td class="px-4 py-3 text-right tabular-nums"><?= e((string) $row['count']) ?></td><td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums"><?= e($money($row['cents'])) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="card" aria-labelledby="days-heading">
            <h3 id="days-heading" class="border-b border-brand-100 px-5 py-4 text-base">By day</h3>
            <?php if ($report['days'] === []): ?>
                <p class="px-5 py-4 text-sm text-brand-gray">Nothing collected.</p>
            <?php else: ?>
                <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                    <caption class="sr-only">Collected by day</caption>
                    <thead class="bg-brand-50 text-brand-navy"><tr><th scope="col" class="<?= $th ?>">Day</th><th scope="col" class="<?= $th ?> text-right">Payments</th><th scope="col" class="<?= $th ?> text-right">Collected</th></tr></thead>
                    <tbody class="divide-y divide-brand-50 bg-white">
                        <?php foreach ($report['days'] as $day => $row): ?>
                            <tr><td class="whitespace-nowrap px-4 py-3"><?= e(dt($day . ' 00:00:00', 'M j, Y')) ?></td><td class="px-4 py-3 text-right tabular-nums"><?= e((string) $row['count']) ?></td><td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums <?= $row['cents'] < 0 ? 'text-danger-text' : '' ?>"><?= e($money($row['cents'])) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </div>
</div>
