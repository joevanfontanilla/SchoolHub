<?php
/**
 * Account list by program and year level: the spreadsheet tab as a report (docs/14, docs/15).
 *
 * @var ?int  $termId
 * @var ?int  $programId
 * @var ?int  $yearLevelId
 * @var array $terms
 * @var array $programs
 * @var array $yearLevels
 * @var array $report FinanceReport::accountList()
 */
use App\Services\FinanceService;

$query = http_build_query(array_filter(['term_id' => $termId ?? 0, 'program_id' => $programId, 'year_level_id' => $yearLevelId]));
$money = static fn (int $cents): string => money(FinanceService::money($cents));
$number = static fn ($value): string => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
$th = 'px-3 py-3 font-semibold';
?>
<div class="space-y-6">
    <?= partial('cashier/report-header', ['active' => 'accounts', 'heading' => 'Account List', 'subtitle' => 'Every account of a term, by program and year level, with the balance of each installment.']) ?>

    <form method="GET" data-auto-submit action="<?= e(url('/cashier/reports/account-list')) ?>" class="card flex flex-wrap items-end gap-4 p-4">
        <div class="min-w-[14rem]">
            <label for="report-term" class="field-label">Term</label>
            <select id="report-term" name="term_id" class="field-input">
                <option value="0">All terms</option>
                <?php foreach ($terms as $term): ?>
                    <option value="<?= e($term['id']) ?>" <?= (int) $term['id'] === $termId ? 'selected' : '' ?>><?= e($term['name'] . ', A.Y. ' . $term['academic_year_code']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="min-w-[10rem]">
            <label for="report-program" class="field-label">Program</label>
            <select id="report-program" name="program_id" class="field-input">
                <option value="">All programs</option>
                <?php foreach ($programs as $program): ?>
                    <option value="<?= e($program['id']) ?>" <?= (int) $program['id'] === $programId ? 'selected' : '' ?>><?= e($program['code']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="min-w-[10rem]">
            <label for="report-year" class="field-label">Year level</label>
            <select id="report-year" name="year_level_id" class="field-input">
                <option value="">All year levels</option>
                <?php foreach ($yearLevels as $yearLevel): ?>
                    <option value="<?= e($yearLevel['id']) ?>" <?= (int) $yearLevel['id'] === $yearLevelId ? 'selected' : '' ?>><?= e($yearLevel['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-secondary"><?= icon('search', 'h-5 w-5') ?> Show</button>
        <div class="ml-auto flex flex-wrap gap-2">
            <a href="<?= e(url('/cashier/reports/account-list/print' . ($query !== '' ? '?' . $query : ''))) ?>" class="btn-secondary"><?= icon('document', 'h-5 w-5') ?> Print</a>
            <a href="<?= e(url('/cashier/reports/account-list/export' . ($query !== '' ? '?' . $query : ''))) ?>" class="btn-primary"><?= icon('download', 'h-5 w-5') ?> Export CSV</a>
        </div>
    </form>

    <div class="grid gap-4 sm:grid-cols-2 2xl:grid-cols-4">
        <?= component('stat-card', ['label' => 'Accounts', 'value' => (string) $report['count'], 'hint' => count($report['groups']) . ' program and year group' . (count($report['groups']) === 1 ? '' : 's'), 'icon' => 'users']) ?>
        <?= component('stat-card', ['label' => 'Amount due', 'value' => $money($report['totals']['due']), 'hint' => 'After discounts and charges', 'icon' => 'receipt']) ?>
        <?= component('stat-card', ['label' => 'Collected', 'value' => $money($report['totals']['paid']), 'hint' => $report['totals']['due'] > 0 ? round($report['totals']['paid'] / $report['totals']['due'] * 100) . '% of the amount due' : '—', 'icon' => 'banknotes', 'tone' => 'success']) ?>
        <?= component('stat-card', ['label' => 'Balance', 'value' => $money($report['totals']['balance']), 'hint' => 'Still to collect', 'icon' => 'credit-card', 'tone' => $report['totals']['balance'] > 0 ? 'warning' : 'success']) ?>
    </div>

    <?php if ($report['groups'] === []): ?>
        <section class="card"><?= component('empty-state', ['title' => 'No accounts match', 'message' => 'Accounts appear here once the registrar approves an application. Try another term, program, or year level.']) ?></section>
    <?php endif; ?>

    <?php foreach ($report['groups'] as $index => $group): ?>
        <section class="card" aria-labelledby="group-<?= e($index) ?>">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-brand-100 px-5 py-4">
                <h3 id="group-<?= e($index) ?>" class="text-base"><?= e($group['program'] . ' · ' . $group['year']) ?></h3>
                <p class="text-sm text-brand-gray"><?= e(count($group['rows'])) ?> <?= count($group['rows']) === 1 ? 'account' : 'accounts' ?> · balance <strong class="text-brand-navy"><?= e($money($group['totals']['balance'])) ?></strong></p>
            </div>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                    <thead class="bg-brand-50 text-brand-navy">
                        <tr>
                            <th scope="col" class="<?= $th ?>">No.</th>
                            <th scope="col" class="<?= $th ?>">Account name</th>
                            <th scope="col" class="<?= $th ?> text-right">Units</th>
                            <th scope="col" class="<?= $th ?> text-right">Tuition</th>
                            <th scope="col" class="<?= $th ?> text-right">Misc.</th>
                            <th scope="col" class="<?= $th ?> text-right">Discount</th>
                            <th scope="col" class="<?= $th ?> text-right">Amount due</th>
                            <th scope="col" class="<?= $th ?> text-right">Paid</th>
                            <th scope="col" class="<?= $th ?> text-right">Balance</th>
                            <th scope="col" class="<?= $th ?> text-right">Prelims</th>
                            <th scope="col" class="<?= $th ?> text-right">Midterm</th>
                            <th scope="col" class="<?= $th ?> text-right">Semi-Final</th>
                            <th scope="col" class="<?= $th ?> text-right">Finals</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-50 bg-white">
                        <?php foreach ($group['rows'] as $n => $row): ?>
                            <?php $plan = $row['plan'] === null ? [] : array_column($row['plan']['rows'], null, 'key'); ?>
                            <tr class="hover:bg-brand-50/40">
                                <td class="whitespace-nowrap px-3 py-2.5 text-brand-gray"><?= e($n + 1) ?></td>
                                <td class="px-3 py-2.5">
                                    <a href="<?= e(url('/cashier/accounts/' . $row['id'])) ?>" class="font-semibold text-brand-navy hover:underline"><?= e($row['last_name'] . ', ' . $row['first_name'] . ($row['middle_name'] ? ' ' . $row['middle_name'] : '')) ?></a>
                                    <span class="block text-xs text-brand-gray"><?= e($row['student_number'] ?? $row['application_number']) ?><?= $row['section_code'] ? ' · ' . e($row['section_code']) : '' ?></span>
                                    <?php if (isset($plan['OLD']) && $plan['OLD']['balance'] > 0): ?>
                                        <span class="block text-xs font-semibold text-danger-text">Old account unpaid: <?= e($money($plan['OLD']['balance'])) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums"><?= e($number($row['units'])) ?></td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums"><?= e($money($row['tuition'])) ?></td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums"><?= e($money($row['misc'])) ?></td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums"><?= $row['discount'] > 0 ? e($money($row['discount'])) : '—' ?></td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums"><?= e($money($row['due'])) ?></td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums"><?= e($money($row['paid'])) ?></td>
                                <td class="whitespace-nowrap px-3 py-2.5 text-right font-semibold tabular-nums text-brand-navy"><?= e($money($row['balance'])) ?></td>
                                <?php foreach (['P1', 'P2', 'P3', 'P4'] as $key): ?>
                                    <?php if (!isset($plan[$key])): ?>
                                        <td class="whitespace-nowrap px-3 py-2.5 text-right text-brand-gray" title="Set when the student is enrolled">—</td>
                                    <?php elseif ($plan[$key]['status'] === 'DUE'): ?>
                                        <td class="whitespace-nowrap px-3 py-2.5 text-right font-semibold tabular-nums text-danger-text"><?= e($money($plan[$key]['balance'])) ?><span class="sr-only"> (due now)</span></td>
                                    <?php else: ?>
                                        <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums <?= $plan[$key]['status'] === 'PAID' ? 'text-success-text' : 'text-slate-600' ?>"><?= e($money($plan[$key]['balance'])) ?></td>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-brand-50/60 font-semibold text-brand-navy">
                        <tr>
                            <th scope="row" colspan="3" class="px-3 py-3 text-right">Totals</th>
                            <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums"><?= e($money($group['totals']['tuition'])) ?></td>
                            <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums"><?= e($money($group['totals']['misc'])) ?></td>
                            <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums"><?= e($money($group['totals']['discount'])) ?></td>
                            <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums"><?= e($money($group['totals']['due'])) ?></td>
                            <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums"><?= e($money($group['totals']['paid'])) ?></td>
                            <td class="whitespace-nowrap px-3 py-3 text-right font-display text-base tabular-nums"><?= e($money($group['totals']['balance'])) ?></td>
                            <td colspan="4"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>
    <?php endforeach; ?>
    <p class="text-xs text-brand-gray">Installment columns show what is still owed on each. Red means its exam period has opened and it is not fully paid; a dash means the student is not enrolled yet.</p>
</div>
