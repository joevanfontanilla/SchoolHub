<?php
/**
 * Outstanding balances (docs/15): active assessments with money still owed.
 *
 * @var ?int  $termId
 * @var ?int  $programId
 * @var array $terms
 * @var array $programs
 * @var array $report  every account and the totals
 * @var array $rows    this page's accounts
 * @var int   $page
 * @var int   $pages
 */
use App\Models\Finance;
use App\Services\FinanceService;

$query = http_build_query(array_filter(['term_id' => $termId, 'program_id' => $programId]));
$enrolledOwing = count(array_filter($report['rows'], static fn ($r) => $r['enrollment_state'] === 'ENROLLED'));
$belowPolicy = count(array_filter($report['rows'], static fn ($r) => Finance::belowPolicy($r)));
$collectedPercent = $report['due'] > 0 ? $report['paid'] / $report['due'] * 100 : 0;
?>
<div class="space-y-6">
    <?= partial('cashier/report-header', ['active' => 'outstanding', 'heading' => 'Outstanding Balances', 'subtitle' => 'Students who still owe on an active assessment, largest balance first.']) ?>

    <form method="GET" data-auto-submit action="<?= e(url('/cashier/reports/outstanding')) ?>" class="card flex flex-wrap items-end gap-4 p-4">
        <div class="min-w-[14rem]">
            <label for="report-term" class="field-label">Term</label>
            <select id="report-term" name="term_id" class="field-input">
                <option value="">All terms</option>
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
        <button type="submit" class="btn-secondary"><?= icon('search', 'h-5 w-5') ?> Show</button>
        <div class="ml-auto flex flex-wrap gap-2">
            <?php if ($reminders > 0): ?>
                <button type="submit" form="remind-form" data-keep class="btn-secondary"><?= icon('bell', 'h-5 w-5') ?> Send <?= e((string) $reminders) ?> reminder<?= $reminders === 1 ? '' : 's' ?></button>
            <?php endif; ?>
            <a href="<?= e(url('/cashier/reports/outstanding/print' . ($query !== '' ? '?' . $query : ''))) ?>" class="btn-secondary"><?= icon('document', 'h-5 w-5') ?> Print</a>
            <a href="<?= e(url('/cashier/reports/outstanding/export' . ($query !== '' ? '?' . $query : ''))) ?>" class="btn-primary"><?= icon('download', 'h-5 w-5') ?> Export CSV</a>
        </div>
    </form>

    <form id="remind-form" method="POST" action="<?= e(url('/cashier/reports/outstanding/remind' . ($query !== '' ? '?' . $query : ''))) ?>"
          data-confirm="Each student with a due installment gets one notification for it. They are not reminded of the same installment twice." data-confirm-title="Send payment reminders?" data-confirm-tone="primary" data-confirm-label="Send reminders">
        <?= csrf_field() ?>
    </form>

    <div class="grid gap-4 sm:grid-cols-2 2xl:grid-cols-4">
        <?= component('stat-card', ['label' => 'Total outstanding', 'value' => money(FinanceService::money($report['balance'])), 'hint' => count($report['rows']) . ' account' . (count($report['rows']) === 1 ? '' : 's') . ' with a balance', 'icon' => 'credit-card', 'tone' => 'warning']) ?>
        <?= component('stat-card', ['label' => 'Collected so far', 'value' => money(FinanceService::money($report['paid'])), 'hint' => round($collectedPercent) . '% of ' . money(FinanceService::money($report['due'])) . ' due on these accounts', 'icon' => 'banknotes', 'tone' => 'success']) ?>
        <?= component('stat-card', ['label' => 'Enrolled, still owing', 'value' => (string) $enrolledOwing, 'hint' => 'Paying the rest within the term', 'icon' => 'calendar-check']) ?>
        <?= component('stat-card', ['label' => 'Below payment policy', 'value' => (string) $belowPolicy, 'hint' => $belowPolicy === 0 ? 'None' : 'Enrolled, but short after a reversal', 'icon' => 'alert', 'tone' => $belowPolicy > 0 ? 'danger' : 'neutral']) ?>
    </div>

    <section class="card" aria-labelledby="balances-heading">
        <h3 id="balances-heading" class="border-b border-brand-100 px-5 py-4 text-base">Accounts</h3>
        <?php if ($report['rows'] === []): ?>
            <?= component('empty-state', ['title' => 'No outstanding balances', 'message' => 'Every active assessment in this selection is fully paid.']) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                    <thead class="bg-brand-50 text-brand-navy">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Student</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Program</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Term</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Amount due</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Paid</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Balance</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Status</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Last payment</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-50 bg-white">
                        <?php foreach ($rows as $row): ?>
                            <tr class="hover:bg-brand-50/40">
                                <td class="px-4 py-3"><a href="<?= e(url('/cashier/accounts/' . $row['id'])) ?>" class="font-semibold text-brand-navy hover:underline"><?= e($row['last_name'] . ', ' . $row['first_name']) ?></a><span class="block text-xs text-brand-gray"><?= e($row['student_number'] ?? $row['application_number']) ?> · <?= e($row['assessment_number']) ?></span></td>
                                <td class="whitespace-nowrap px-4 py-3"><?= e($row['program_code']) ?><span class="block text-xs text-brand-gray"><?= e($row['year_level_name'] . ($row['section_code'] ? ' · ' . $row['section_code'] : '')) ?></span></td>
                                <td class="whitespace-nowrap px-4 py-3"><?= e($row['term_name']) ?><span class="block text-xs text-brand-gray"><?= e($row['academic_year_code']) ?></span></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums"><?= e(money(FinanceService::money(max(0, FinanceService::dueCents($row))))) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums"><?= e(money($row['total_paid'])) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums text-brand-navy"><?= e(money($row['balance'])) ?></td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <span class="flex flex-wrap gap-1">
                                        <?= component('status-badge', ['status' => $row['payment_status']]) ?>
                                        <?php if (Finance::belowPolicy($row)): ?>
                                            <?= component('status-badge', ['status' => 'REJECTED', 'label' => 'Below policy']) ?>
                                        <?php elseif ($row['enrollment_state'] === 'ENROLLED'): ?>
                                            <?= component('status-badge', ['status' => 'ENROLLED']) ?>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600"><?= e($row['last_payment_at'] ? d($row['last_payment_at']) : 'None yet') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-brand-50/60 font-semibold text-brand-navy">
                        <tr>
                            <th scope="row" colspan="3" class="px-4 py-3 text-right">Totals</th>
                            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums"><?= e(money(FinanceService::money($report['due']))) ?></td>
                            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums"><?= e(money(FinanceService::money($report['paid']))) ?></td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-display text-lg tabular-nums"><?= e(money(FinanceService::money($report['balance']))) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?= partial('pagination', ['page' => $page, 'pages' => $pages, 'total' => count($report['rows']), 'path' => '/cashier/reports/outstanding', 'query' => array_filter(['term_id' => $termId, 'program_id' => $programId]), 'noun' => 'account']) ?>
        <?php endif; ?>
    </section>
</div>
