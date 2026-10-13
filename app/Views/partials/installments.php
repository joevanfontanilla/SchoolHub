<?php
/**
 * The exam-period installment plan (docs/14): what is due, what has been paid, and what is left,
 * for the old account, the initial payment, and Prelims, Midterm, Semi-Final, and Finals.
 *
 * @var ?array $plan FinanceService::installments(), or null before the student is enrolled
 */
use App\Services\FinanceService;

$cell = 'border border-slate-300 px-2 py-1.5';
?>
<?php if ($plan === null): ?>
    <p class="text-sm text-slate-600">The installments are set after the down payment, when the student is enrolled.</p>
<?php else: ?>
    <div class="overflow-x-auto print:overflow-visible">
        <table class="w-full border border-slate-300 text-sm">
            <caption class="sr-only">Installments</caption>
            <thead class="bg-brand-50 text-left text-brand-navy">
                <tr>
                    <th scope="col" class="<?= $cell ?>">Particulars</th>
                    <th scope="col" class="<?= $cell ?> text-right">Due</th>
                    <th scope="col" class="<?= $cell ?> text-right">Paid</th>
                    <th scope="col" class="<?= $cell ?> text-right">Balance</th>
                    <th scope="col" class="<?= $cell ?>">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($plan['rows'] as $row): ?>
                    <tr>
                        <th scope="row" class="<?= $cell ?> text-left font-medium"><?= e($row['label']) ?></th>
                        <td class="<?= $cell ?> text-right tabular-nums"><?= e(money(FinanceService::money($row['due']))) ?></td>
                        <td class="<?= $cell ?> text-right tabular-nums"><?= e(money(FinanceService::money($row['paid']))) ?></td>
                        <td class="<?= $cell ?> text-right font-semibold tabular-nums"><?= e(money(FinanceService::money($row['balance']))) ?></td>
                        <td class="<?= $cell ?> whitespace-nowrap">
                            <?php if ($row['status'] === 'PAID'): ?>
                                <span class="font-semibold text-success-text">Paid</span>
                            <?php elseif ($row['status'] === 'DUE'): ?>
                                <span class="font-semibold text-danger-text">Due now</span>
                            <?php else: ?>
                                <span class="text-brand-gray">Upcoming</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="mt-2 text-xs text-brand-gray">An installment is due once its exam period opens. This is a reminder: it does not block grades or exams.</p>
<?php endif; ?>
