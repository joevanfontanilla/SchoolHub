<?php
/**
 * Account review (docs/07): every account by role and status, with the ones that need a
 * look flagged: never used, unused for months, locked, or a set-up link that ran out.
 *
 * @var array $filters role, status, flagged
 * @var array $roles
 * @var array $report  rows, matrix, flags, total
 * @var array $rows    this page's accounts
 * @var int   $page
 * @var int   $pages
 */
use App\Models\AdminReport;

$query = http_build_query(array_filter(['role' => $filters['role'], 'status' => $filters['status'], 'flagged' => $filters['flagged'] ? '1' : null]));
$suffix = $query !== '' ? '?' . $query : '';
$flagged = array_sum($report['flags']);
$current = ['role' => (string) $filters['role'], 'status' => (string) $filters['status']];
?>
<div class="space-y-6">
    <?= partial('admin/report-header', [
        'active' => 'accounts', 'heading' => 'Account Review',
        'subtitle' => 'Every account by role and status. Review flagged accounts regularly: disable the ones no longer needed.',
    ]) ?>

    <form method="GET" data-auto-submit action="<?= e(url('/admin/reports/accounts')) ?>" class="card flex flex-wrap items-end gap-4 p-4">
        <div class="min-w-[10rem]">
            <label for="report-role" class="field-label">Role</label>
            <select id="report-role" name="role" class="field-input">
                <option value="">All roles</option>
                <?php foreach ($roles as $role): ?>
                    <option value="<?= e($role) ?>" <?= $current['role'] === $role ? 'selected' : '' ?>><?= e($role) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="min-w-[10rem]">
            <label for="report-status" class="field-label">Status</label>
            <select id="report-status" name="status" class="field-input">
                <option value="">All statuses</option>
                <?php foreach (AdminReport::STATUSES as $status): ?>
                    <option value="<?= e($status) ?>" <?= $current['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <label class="flex items-center gap-2 pb-2 text-sm text-slate-700">
            <input type="checkbox" name="flagged" value="1" class="h-4 w-4 rounded border-brand-200 text-brand-600" <?= $filters['flagged'] ? 'checked' : '' ?>>
            Flagged only
        </label>
        <button type="submit" class="btn-secondary"><?= icon('search', 'h-5 w-5') ?> Show</button>
        <div class="ml-auto flex flex-wrap gap-2">
            <a href="<?= e(url('/admin/reports/accounts/print' . $suffix)) ?>" class="btn-secondary"><?= icon('document', 'h-5 w-5') ?> Print</a>
            <a href="<?= e(url('/admin/reports/accounts/export' . $suffix)) ?>" class="btn-primary"><?= icon('download', 'h-5 w-5') ?> Export CSV</a>
        </div>
    </form>

    <div class="grid gap-4 sm:grid-cols-2 2xl:grid-cols-4">
        <?= component('stat-card', ['label' => 'Accounts', 'value' => number_format($report['total']), 'hint' => 'Every role and status', 'icon' => 'users']) ?>
        <?= component('stat-card', ['label' => 'Flagged for review', 'value' => number_format($flagged), 'hint' => $flagged === 0 ? 'Nothing to review' : 'See the flags below', 'icon' => 'alert', 'tone' => $flagged > 0 ? 'warning' : 'neutral']) ?>
        <?= component('stat-card', ['label' => 'Locked', 'value' => number_format($report['flags'][AdminReport::FLAG_LOCKED]), 'hint' => 'Unlock from the account page', 'icon' => 'lock', 'tone' => $report['flags'][AdminReport::FLAG_LOCKED] > 0 ? 'danger' : 'neutral']) ?>
        <?= component('stat-card', ['label' => 'No sign-in for ' . AdminReport::INACTIVE_DAYS . ' days', 'value' => number_format($report['flags'][AdminReport::FLAG_INACTIVE]), 'hint' => number_format($report['flags'][AdminReport::FLAG_NEVER_SIGNED_IN]) . ' never signed in', 'icon' => 'clock']) ?>
    </div>

    <section class="card" aria-labelledby="matrix-heading">
        <h3 id="matrix-heading" class="border-b border-brand-100 px-5 py-4 text-base">By role and status</h3>
        <div class="table-scroll">
            <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                <thead class="bg-brand-50 text-brand-navy">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-semibold">Role</th>
                        <?php foreach (AdminReport::STATUSES as $status): ?>
                            <th scope="col" class="px-4 py-3 text-right font-semibold"><?= e($status) ?></th>
                        <?php endforeach; ?>
                        <th scope="col" class="px-4 py-3 text-right font-semibold">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-50 bg-white">
                    <?php foreach ($report['matrix'] as $role => $counts): ?>
                        <tr>
                            <th scope="row" class="px-4 py-3 font-semibold text-brand-navy"><?= e($role) ?></th>
                            <?php foreach (AdminReport::STATUSES as $status): ?>
                                <td class="px-4 py-3 text-right tabular-nums <?= $counts[$status] === 0 ? 'text-slate-400' : '' ?>"><?= e(number_format($counts[$status])) ?></td>
                            <?php endforeach; ?>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums"><?= e(number_format(array_sum($counts))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card" aria-labelledby="accounts-heading">
        <h3 id="accounts-heading" class="border-b border-brand-100 px-5 py-4 text-base">Accounts</h3>
        <?php if ($rows === []): ?>
            <?= component('empty-state', ['title' => 'No accounts', 'message' => 'No account matches this selection.']) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                    <thead class="bg-brand-50 text-brand-navy">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Account</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Role</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Status</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Last sign-in</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Review</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-50 bg-white">
                        <?php foreach ($rows as $row): ?>
                            <tr class="hover:bg-brand-50/40">
                                <td class="px-4 py-3"><a href="<?= e(url('/admin/users/' . $row['id'])) ?>" class="font-semibold text-brand-navy hover:underline"><?= e($row['last_name'] . ', ' . $row['first_name']) ?></a><span class="block text-xs text-brand-gray"><?= e($row['username'] . ' · ' . $row['email']) ?></span></td>
                                <td class="whitespace-nowrap px-4 py-3"><?= e($row['role_name']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3"><?= e($row['status']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600"><?= e($row['last_login_at'] ? dt($row['last_login_at']) : 'Never') ?></td>
                                <td class="px-4 py-3">
                                    <?php if ($row['flags'] === []): ?>
                                        <span class="text-brand-gray">None</span>
                                    <?php else: ?>
                                        <ul class="space-y-1">
                                            <?php foreach ($row['flags'] as $flag): ?>
                                                <li class="flex items-center gap-1.5 text-warning-text"><?= icon('alert', 'h-4 w-4 shrink-0') ?> <?= e(AdminReport::FLAG_LABELS[$flag]) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= partial('pagination', ['page' => $page, 'pages' => $pages, 'total' => count($report['rows']), 'path' => '/admin/reports/accounts', 'query' => array_filter(['role' => $filters['role'], 'status' => $filters['status'], 'flagged' => $filters['flagged'] ? '1' : null]), 'noun' => 'account']) ?>
        <?php endif; ?>
    </section>
</div>
