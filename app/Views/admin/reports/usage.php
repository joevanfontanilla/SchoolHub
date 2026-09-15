<?php
/**
 * System usage (docs/20): accounts, the recent terms, communication, stored records, and
 * disk space.
 *
 * @var array $report roles, terms, communication, records, storage
 */
use App\Services\FinanceService;
use App\Services\SystemHealth;

$records = $report['records'];
$storage = $report['storage'];
$uploadBytes = $storage['uploads']['bytes'];
?>
<div class="space-y-6">
    <?= partial('admin/report-header', [
        'active' => 'usage', 'heading' => 'System Usage',
        'subtitle' => 'How much SchoolHub holds and how the recent terms compare. Read now, not over a period.',
    ]) ?>

    <div class="flex flex-wrap justify-end gap-2">
        <a href="<?= e(url('/admin/reports/usage/print')) ?>" class="btn-secondary"><?= icon('document', 'h-5 w-5') ?> Print</a>
        <a href="<?= e(url('/admin/reports/usage/export')) ?>" class="btn-primary"><?= icon('download', 'h-5 w-5') ?> Export CSV</a>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 2xl:grid-cols-4">
        <?= component('stat-card', ['label' => 'Accounts', 'value' => number_format(array_sum($report['roles'])), 'hint' => number_format($report['roles']['Student'] ?? 0) . ' students', 'icon' => 'users']) ?>
        <?= component('stat-card', ['label' => 'Database size', 'value' => SystemHealth::bytes($records['database_bytes']), 'hint' => number_format($records['audit_entries']) . ' audit entries', 'icon' => 'database']) ?>
        <?= component('stat-card', ['label' => 'Uploaded files', 'value' => SystemHealth::bytes($uploadBytes), 'hint' => number_format($storage['uploads']['files']) . ' files in storage/uploads', 'icon' => 'document']) ?>
        <?= component('stat-card', ['label' => 'Backups on disk', 'value' => SystemHealth::bytes($storage['backups']['bytes']), 'hint' => number_format($storage['backups']['files']) . ' files', 'icon' => 'history']) ?>
    </div>

    <section class="card" aria-labelledby="terms-heading">
        <h3 id="terms-heading" class="border-b border-brand-100 px-5 py-4 text-base">Recent terms</h3>
        <?php if ($report['terms'] === []): ?>
            <?= component('empty-state', ['title' => 'No terms', 'message' => 'Terms appear once the academic calendar is set up.']) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                    <thead class="bg-brand-50 text-brand-navy">
                        <tr><th scope="col" class="px-4 py-3 font-semibold">Term</th><th scope="col" class="px-4 py-3 text-right font-semibold">Applications</th><th scope="col" class="px-4 py-3 text-right font-semibold">Enrolled</th><th scope="col" class="px-4 py-3 text-right font-semibold">Classes</th><th scope="col" class="px-4 py-3 text-right font-semibold">Collected</th></tr>
                    </thead>
                    <tbody class="divide-y divide-brand-50 bg-white">
                        <?php foreach ($report['terms'] as $term): ?>
                            <tr>
                                <th scope="row" class="whitespace-nowrap px-4 py-3 font-semibold text-brand-navy"><?= e($term['name']) ?><span class="block text-xs font-normal text-brand-gray">A.Y. <?= e($term['academic_year_code']) ?></span></th>
                                <td class="px-4 py-3 text-right tabular-nums"><?= e(number_format((int) $term['applications'])) ?></td>
                                <td class="px-4 py-3 text-right tabular-nums"><?= e(number_format((int) $term['enrolled'])) ?></td>
                                <td class="px-4 py-3 text-right tabular-nums"><?= e(number_format((int) $term['classes'])) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums"><?= e(money(FinanceService::money($term['collected_cents']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="border-t border-brand-100 px-5 py-3 text-xs text-brand-gray">Collected is net of reversals, by the term of the assessment.</p>
        <?php endif; ?>
    </section>

    <div class="grid gap-6 xl:grid-cols-3">
        <section class="card min-w-0 p-5" aria-labelledby="roles-heading">
            <h3 id="roles-heading" class="mb-3 text-base">Accounts by role</h3>
            <dl class="divide-y divide-brand-50 text-sm">
                <?php foreach ($report['roles'] as $role => $accounts): ?>
                    <div class="flex justify-between py-2"><dt><?= e($role) ?></dt><dd class="font-semibold tabular-nums"><?= e(number_format($accounts)) ?></dd></div>
                <?php endforeach; ?>
            </dl>
        </section>
        <section class="card min-w-0 p-5" aria-labelledby="communication-heading">
            <h3 id="communication-heading" class="mb-3 text-base">Communication</h3>
            <dl class="divide-y divide-brand-50 text-sm">
                <div class="flex justify-between py-2"><dt>Published announcements</dt><dd class="font-semibold tabular-nums"><?= e(number_format($report['communication']['announcements'])) ?></dd></div>
                <div class="flex justify-between py-2"><dt>Notifications</dt><dd class="font-semibold tabular-nums"><?= e(number_format($report['communication']['notifications'])) ?></dd></div>
                <div class="flex justify-between py-2"><dt>Still unread</dt><dd class="font-semibold tabular-nums"><?= e(number_format($report['communication']['unread'])) ?></dd></div>
            </dl>
        </section>
        <section class="card min-w-0 p-5" aria-labelledby="records-heading">
            <h3 id="records-heading" class="mb-3 text-base">Records and storage</h3>
            <dl class="divide-y divide-brand-50 text-sm">
                <div class="flex justify-between gap-3 py-2"><dt>Requirement documents</dt><dd class="text-right font-semibold tabular-nums"><?= e(number_format($records['requirement_files'])) ?> · <?= e(SystemHealth::bytes($records['requirement_bytes'])) ?></dd></div>
                <div class="flex justify-between gap-3 py-2"><dt>Payment proofs</dt><dd class="text-right font-semibold tabular-nums"><?= e(number_format($records['proofs'])) ?> · <?= e(SystemHealth::bytes($records['proof_bytes'])) ?></dd></div>
                <div class="flex justify-between gap-3 py-2"><dt>Sign-in attempts logged</dt><dd class="text-right font-semibold tabular-nums"><?= e(number_format($records['sign_in_attempts'])) ?></dd></div>
                <div class="flex justify-between gap-3 py-2"><dt>Log files</dt><dd class="text-right font-semibold tabular-nums"><?= e(SystemHealth::bytes($storage['logs']['bytes'])) ?></dd></div>
            </dl>
        </section>
    </div>
</div>
