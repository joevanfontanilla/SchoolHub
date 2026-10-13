<?php
/**
 * Closed days, and reopening one (docs/14, Daily operations at the window).
 *
 * @var array $history
 */
use App\Services\FinanceService;
?>
<div class="mx-auto max-w-5xl space-y-6">
    <div>
        <p class="eyebrow">Monitoring</p>
        <h2 class="mt-1 text-2xl">Closed days</h2>
        <p class="mt-1 text-sm text-slate-600">Days the cashier has closed. Reopen one when something must be recorded on it; the reason is kept in the audit log and the cashier is told.</p>
    </div>

    <section class="card" aria-labelledby="closes-heading">
        <h3 id="closes-heading" class="sr-only">Closed days</h3>
        <?php if ($history === []): ?>
            <?= component('empty-state', ['title' => 'No days closed yet', 'message' => 'When the cashier closes a day it appears here.']) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                    <thead class="bg-brand-50 text-brand-navy">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Day</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Expected</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Counted</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Difference</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Closed by</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-50 bg-white">
                        <?php foreach ($history as $row): ?>
                            <?php $dayLabel = dt($row['close_date'] . ' 00:00:00', 'M j, Y'); ?>
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3 font-semibold text-brand-navy"><?= e($dayLabel) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums"><?= e(money($row['expected_cash'])) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums"><?= e(money($row['counted_cash'])) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums"><?= e(signed_money(FinanceService::signedCents($row['difference']))) ?></td>
                                <td class="px-4 py-3"><?= e($row['closed_by_name']) ?><span class="block text-xs text-brand-gray"><?= e(dt($row['closed_at'])) ?></span></td>
                                <td class="px-4 py-3">
                                    <?php if ($row['status'] === 'CLOSED'): ?>
                                        <details>
                                            <summary class="cursor-pointer text-sm font-semibold text-danger-text hover:underline">Reopen<span class="sr-only"> <?= e($dayLabel) ?></span></summary>
                                            <form method="POST" action="<?= e(url('/admin/day-closes/' . $row['id'] . '/reopen')) ?>" class="mt-2 flex flex-wrap items-end gap-2" novalidate
                                                  data-confirm="The cashier can record on this day again, then must close it with a new count." data-confirm-title="Reopen <?= e($dayLabel) ?>?" data-confirm-tone="danger" data-confirm-label="Reopen the day"
                                                  data-confirm-requires="#reopen-reason-<?= (int) $row['id'] ?>" data-confirm-requires-message="Write why the day is reopened first.">
                                                <?= csrf_field() ?>
                                                <div class="min-w-0 flex-1">
                                                    <label for="reopen-reason-<?= (int) $row['id'] ?>" class="field-label">Reason <span class="text-danger-text" aria-hidden="true">*</span></label>
                                                    <input id="reopen-reason-<?= (int) $row['id'] ?>" name="reason" class="field-input" required minlength="5" maxlength="500" placeholder="e.g. A payment was missed">
                                                </div>
                                                <button type="submit" class="btn-danger">Reopen</button>
                                            </form>
                                        </details>
                                    <?php else: ?>
                                        <?= component('status-badge', ['status' => 'PENDING', 'label' => 'Reopened']) ?>
                                        <span class="block text-xs text-brand-gray"><?= e($row['reopened_by_name']) ?>: <?= e($row['reopen_reason']) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
