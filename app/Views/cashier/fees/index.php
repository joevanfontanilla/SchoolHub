<?php
/**
 * The fee schedule (docs/14).
 *
 * @var array $fees
 * @var array $bases
 */
$sample = array_filter($fees, static fn ($f) => str_starts_with((string) $f['description'], 'Sample amount') && (int) $f['is_active'] === 1);
$fromLedger = array_filter($fees, static fn ($f) => str_starts_with((string) $f['description'], 'From the A.Y.') && (int) $f['is_active'] === 1);
?>
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">Cashier</p>
            <h2 class="mt-1 text-2xl">Fee Schedule</h2>
            <p class="mt-1 max-w-2xl text-sm text-slate-600">Used to compute each student's assessment when the registrar approves their application. Changes apply to assessments issued afterwards; issued ones keep their amounts.</p>
        </div>
        <a href="<?= e(url('/cashier/fees/create')) ?>" class="btn-primary"><?= icon('plus', 'h-5 w-5') ?> New fee</a>
    </div>

    <?php if ($sample !== []): ?>
        <p class="flex items-start gap-2 rounded-xl border border-warning-line bg-warning-bg px-4 py-3 text-sm text-warning-text" role="note">
            <?= icon('alert', 'h-5 w-5') ?>
            <span><strong><?= count($sample) ?> fee<?= count($sample) === 1 ? ' still has' : 's still have' ?> sample amounts.</strong> Replace them with the school's actual fees before students are assessed.</span>
        </p>
    <?php endif; ?>

    <?php if ($fromLedger !== []): ?>
        <p class="flex items-start gap-2 rounded-xl border border-warning-line bg-warning-bg px-4 py-3 text-sm text-warning-text" role="note">
            <?= icon('alert', 'h-5 w-5') ?>
            <span><strong><?= count($fromLedger) ?> fee lines are defaults taken from the Public Administration 3rd-year ledger.</strong> Check them against the school's fee schedule, and limit Laboratory, Computer Lab, and NSTP to the programs and years that pay them. Editing a line clears this notice for it.</span>
        </p>
    <?php endif; ?>

    <section class="card">
        <div class="table-scroll">
            <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                <thead class="bg-brand-50 text-brand-navy">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">Fee</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Charged</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Applies to</th>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Amount</th>
                        <th scope="col" class="px-4 py-3 font-semibold">Status</th>
                        <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-50 bg-white">
                    <?php foreach ($fees as $fee): ?>
                        <tr>
                            <td class="px-4 py-3"><span class="font-semibold text-brand-navy"><?= e($fee['name']) ?></span><span class="block text-xs text-brand-gray"><?= e($fee['code']) ?><?= $fee['description'] ? ' · ' . e($fee['description']) : '' ?></span></td>
                            <td class="whitespace-nowrap px-4 py-3"><?= e($bases[$fee['basis']] ?? $fee['basis']) ?></td>
                            <td class="whitespace-nowrap px-4 py-3"><?= e($fee['program_code'] === null && $fee['year_level_name'] === null ? 'Everyone' : trim(($fee['program_code'] ?? 'All programs') . ' · ' . ($fee['year_level_name'] ?? 'all years'))) ?></td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums"><?= e(money($fee['amount'])) ?></td>
                            <td class="whitespace-nowrap px-4 py-3"><?= component('status-badge', ['status' => (int) $fee['is_active'] === 1 ? 'OPEN' : 'CLOSED', 'label' => (int) $fee['is_active'] === 1 ? 'Active' : 'Inactive']) ?></td>
                            <td class="whitespace-nowrap px-4 py-3 text-right"><a href="<?= e(url('/cashier/fees/' . $fee['id'] . '/edit')) ?>" class="font-semibold text-brand-600 hover:underline">Edit<span class="sr-only"> <?= e($fee['name']) ?></span></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
