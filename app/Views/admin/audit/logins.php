<?php
/**
 * Sign-in attempts (docs/07). Read-only.
 *
 * @var array $filters
 * @var array $attempts
 * @var int   $total
 * @var int   $page
 * @var int   $pages
 */
$results = ['SUCCESS' => 'Success', 'FAILED' => 'Wrong password or unknown account', 'LOCKED_OUT' => 'Locked out', 'DISABLED' => 'Disabled account'];
?>
<div class="space-y-6">
    <div>
        <p class="eyebrow">Monitoring</p>
        <h2 class="mt-1 text-2xl">Sign-in Activity</h2>
        <p class="mt-1 text-sm text-slate-600">Every sign-in attempt, successful or not, with the address it came from.</p>
    </div>

    <form method="GET" data-auto-submit action="<?= e(url('/admin/login-activity')) ?>" class="card flex flex-wrap items-end gap-4 p-4">
        <div><label for="login-from" class="field-label">From</label><input id="login-from" type="date" name="from" value="<?= e($filters['from']) ?>" class="field-input"></div>
        <div><label for="login-to" class="field-label">To</label><input id="login-to" type="date" name="to" value="<?= e($filters['to']) ?>" class="field-input"></div>
        <div class="min-w-[12rem] flex-1"><label for="login-name" class="field-label">Username or email tried</label><input id="login-name" type="search" name="login" value="<?= e($filters['login']) ?>" class="field-input"></div>
        <div><label for="login-ip" class="field-label">IP address</label><input id="login-ip" type="search" name="ip" value="<?= e($filters['ip']) ?>" class="field-input"></div>
        <div>
            <label for="login-result" class="field-label">Result</label>
            <select id="login-result" name="result" class="field-input">
                <option value="">Any</option>
                <?php foreach ($results as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filters['result'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-primary"><?= icon('search', 'h-5 w-5') ?> Filter</button>
    </form>

    <section class="card">
        <?php if ($attempts === []): ?>
            <?= component('empty-state', ['title' => 'No sign-in attempts match']) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                    <thead class="bg-brand-50 text-brand-navy">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Time</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Tried as</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Account</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Result</th>
                            <th scope="col" class="px-4 py-3 font-semibold">IP address</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-50 bg-white">
                        <?php foreach ($attempts as $attempt): ?>
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600"><?= e(dt($attempt['attempted_at'])) ?></td>
                                <td class="px-4 py-3 font-medium text-brand-navy"><?= e($attempt['username_attempted']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3"><?= $attempt['user_id'] ? '<a class="text-brand-600 hover:underline" href="' . e(url('/admin/users/' . $attempt['user_id'])) . '">' . e($attempt['user_name'] ?? $attempt['username']) . '</a>' : '<span class="text-brand-gray">No such account</span>' ?></td>
                                <td class="whitespace-nowrap px-4 py-3"><?= component('status-badge', $attempt['result'] === 'SUCCESS' ? ['status' => 'APPROVED', 'label' => 'Success'] : ['status' => 'REJECTED', 'label' => $results[$attempt['result']] ?? $attempt['result']]) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600"><?= e($attempt['ip_address']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= partial('pagination', ['page' => $page, 'pages' => $pages, 'total' => $total, 'path' => '/admin/login-activity', 'query' => $filters, 'noun' => 'attempt']) ?>
        <?php endif; ?>
    </section>
</div>
