<?php
/**
 * The audit log (docs/19). Read-only.
 *
 * @var array $filters
 * @var array $entries
 * @var array $modules
 * @var int   $total
 * @var int   $page
 * @var int   $pages
 */
$query = http_build_query(array_filter($filters, static fn ($v) => $v !== ''));
?>
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">Monitoring</p>
            <h2 class="mt-1 text-2xl">Audit Logs</h2>
            <p class="mt-1 text-sm text-slate-600">Every security- and record-sensitive action. Entries cannot be edited or deleted by anyone, including the Super Admin.</p>
        </div>
        <a href="<?= e(url('/admin/audit-logs/export' . ($query !== '' ? '?' . $query : ''))) ?>" class="btn-secondary"><?= icon('download', 'h-5 w-5') ?> Export CSV</a>
    </div>

    <form method="GET" action="<?= e(url('/admin/audit-logs')) ?>" class="card grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4">
        <div><label for="audit-from" class="field-label">From</label><input id="audit-from" type="date" name="from" value="<?= e($filters['from']) ?>" class="field-input"></div>
        <div><label for="audit-to" class="field-label">To</label><input id="audit-to" type="date" name="to" value="<?= e($filters['to']) ?>" class="field-input"></div>
        <div><label for="audit-actor" class="field-label">Who</label><input id="audit-actor" type="search" name="actor" value="<?= e($filters['actor']) ?>" placeholder="Name, username, email" class="field-input"></div>
        <div><label for="audit-action" class="field-label">Action starts with</label><input id="audit-action" type="search" name="action" value="<?= e($filters['action']) ?>" placeholder="e.g. finance. or login." class="field-input"></div>
        <div>
            <label for="audit-module" class="field-label">Module</label>
            <select id="audit-module" name="module" class="field-input">
                <option value="">All modules</option>
                <?php foreach ($modules as $module): ?>
                    <option value="<?= e($module) ?>" <?= $filters['module'] === $module ? 'selected' : '' ?>><?= e($module) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="audit-result" class="field-label">Result</label>
            <select id="audit-result" name="result" class="field-input">
                <option value="">Any</option>
                <option value="SUCCESS" <?= $filters['result'] === 'SUCCESS' ? 'selected' : '' ?>>Success</option>
                <option value="FAILURE" <?= $filters['result'] === 'FAILURE' ? 'selected' : '' ?>>Failure</option>
            </select>
        </div>
        <div><label for="audit-target-type" class="field-label">Record type</label><input id="audit-target-type" type="search" name="target_type" value="<?= e($filters['target_type']) ?>" placeholder="e.g. User, Payment" class="field-input"></div>
        <div><label for="audit-target-id" class="field-label">Record ID</label><input id="audit-target-id" type="number" min="1" name="target_id" value="<?= e($filters['target_id']) ?>" class="field-input"></div>
        <div class="flex gap-2 sm:col-span-2 lg:col-span-4">
            <button type="submit" class="btn-primary"><?= icon('search', 'h-5 w-5') ?> Filter</button>
            <a href="<?= e(url('/admin/audit-logs')) ?>" class="btn-secondary">Clear</a>
        </div>
    </form>

    <section class="card">
        <?php if ($entries === []): ?>
            <?= component('empty-state', ['title' => 'No entries match']) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                    <thead class="bg-brand-50 text-brand-navy">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Time</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Who</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Action</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Record</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Result</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Details</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-50 bg-white">
                        <?php foreach ($entries as $entry): ?>
                            <tr class="hover:bg-brand-50/40">
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600"><?= e(dt($entry['created_at'])) ?></td>
                                <td class="px-4 py-3"><span class="font-medium text-brand-navy"><?= e($entry['actor_name'] ?? ($entry['actor_role'] === 'Guest' ? 'Signed-out visitor' : $entry['actor_role'])) ?></span><span class="block text-xs text-brand-gray"><?= e($entry['actor_role']) ?><?= $entry['ip_address'] ? ' · ' . e($entry['ip_address']) : '' ?></span></td>
                                <td class="px-4 py-3"><code class="text-xs text-brand-navy"><?= e($entry['action']) ?></code><span class="block text-xs text-brand-gray"><?= e($entry['module']) ?></span></td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600"><?= e($entry['target_type'] ? $entry['target_type'] . ($entry['target_id'] ? ' #' . $entry['target_id'] : '') : '—') ?></td>
                                <td class="whitespace-nowrap px-4 py-3"><?= component('status-badge', $entry['result'] === 'SUCCESS' ? ['status' => 'APPROVED', 'label' => 'Success'] : ['status' => 'REJECTED', 'label' => 'Failure']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right"><a href="<?= e(url('/admin/audit-logs/' . $entry['id'])) ?>" class="font-semibold text-brand-600 hover:underline">View<span class="sr-only"> entry <?= (int) $entry['id'] ?></span></a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= partial('pagination', ['page' => $page, 'pages' => $pages, 'total' => $total, 'path' => '/admin/audit-logs', 'query' => $filters, 'noun' => 'entry']) ?>
        <?php endif; ?>
    </section>
</div>
