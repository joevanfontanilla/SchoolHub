<?php
/**
 * Online payments waiting for the cashier, and ones already checked (docs/14).
 *
 * @var string $status
 * @var array  $submissions
 * @var int    $pending
 */
$tabs = ['PENDING' => 'To verify', 'APPROVED' => 'Verified', 'REJECTED' => 'Rejected'];
?>
<div class="space-y-6">
    <div>
        <p class="eyebrow">Cashier</p>
        <h2 class="mt-1 text-2xl">Online Payments</h2>
        <p class="mt-1 text-sm text-slate-600">Check each payment against your GCash, Maya, or bank records before verifying it. Verifying issues the official receipt and can enroll the student.</p>
    </div>

    <nav aria-label="Payment status" class="flex flex-wrap gap-2">
        <?php foreach ($tabs as $value => $label): ?>
            <a href="<?= e(url('/cashier/payments?status=' . $value)) ?>"
               class="inline-flex items-center gap-2 rounded-xl border px-4 py-2 text-sm font-semibold <?= $status === $value ? 'border-brand-600 bg-brand-600 text-white' : 'border-brand-100 bg-white text-slate-700 hover:border-brand-sky' ?>"
               <?= $status === $value ? 'aria-current="page"' : '' ?>>
                <?= e($label) ?>
                <?php if ($value === 'PENDING' && $pending > 0): ?><span class="rounded-full bg-brand-alert px-2 text-xs text-white"><?= $pending ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <section class="card">
        <?php if ($submissions === []): ?>
            <?= component('empty-state', ['title' => $status === 'PENDING' ? 'No payments to verify' : 'Nothing here yet']) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                    <thead class="bg-brand-50 text-brand-navy">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Student</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Amount</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Method and reference</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Paid on</th>
                            <th scope="col" class="px-4 py-3 font-semibold"><?= $status === 'PENDING' ? 'Sent' : 'Checked' ?></th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-50 bg-white">
                        <?php foreach ($submissions as $row): ?>
                            <tr class="hover:bg-brand-50/40">
                                <td class="px-4 py-3">
                                    <span class="font-semibold text-brand-navy"><?= e($row['last_name'] . ', ' . $row['first_name']) ?></span>
                                    <span class="block text-xs text-brand-gray"><?= e($row['student_number'] ?? $row['application_number']) ?> · <?= e($row['assessment_number']) ?></span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 font-display font-bold text-brand-navy"><?= e(money($row['amount'])) ?></td>
                                <td class="px-4 py-3"><?= e(method_text($row, 'method')) ?><span class="block text-xs text-brand-gray">Ref. <?= e($row['reference_number']) ?></span></td>
                                <td class="whitespace-nowrap px-4 py-3"><?= e(d($row['paid_on'])) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600"><?= e(dt($status === 'PENDING' ? $row['submitted_at'] : $row['reviewed_at'])) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <a href="<?= e(url('/cashier/payments/' . $row['id'])) ?>" class="<?= $status === 'PENDING' ? 'btn-primary' : 'font-semibold text-brand-600 hover:underline' ?>"><?= $status === 'PENDING' ? 'Verify' : 'View' ?><span class="sr-only"> payment from <?= e($row['first_name'] . ' ' . $row['last_name']) ?></span></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
