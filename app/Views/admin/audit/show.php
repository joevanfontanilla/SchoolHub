<?php
/**
 * One audit entry (docs/19). Read-only.
 *
 * @var array $entry
 */
$pretty = static function (?string $json): string {
    if ($json === null || $json === '') {
        return '—';
    }
    $decoded = json_decode($json, true);
    return $decoded === null ? $json : (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};
$rows = [
    'Time'        => dt($entry['created_at'], 'F j, Y g:i:s A'),
    'Who'         => ($entry['actor_name'] ?? '—') . ($entry['username'] ? ' (' . $entry['username'] . ')' : ''),
    'Role'        => $entry['actor_role'],
    'Action'      => $entry['action'],
    'Module'      => $entry['module'],
    'Record'      => $entry['target_type'] ? $entry['target_type'] . ($entry['target_id'] ? ' #' . $entry['target_id'] : '') : '—',
    'Result'      => $entry['result'],
    'IP address'  => $entry['ip_address'] ?? '—',
    'Browser'     => $entry['user_agent'] ?? '—',
];
?>
<div class="mx-auto max-w-4xl space-y-6">
    <a href="<?= e(url('/admin/audit-logs')) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; Audit Logs</a>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-2xl">Audit entry #<?= (int) $entry['id'] ?></h2>
        <?= component('status-badge', $entry['result'] === 'SUCCESS' ? ['status' => 'APPROVED', 'label' => 'Success', 'size' => 'md'] : ['status' => 'REJECTED', 'label' => 'Failure', 'size' => 'md']) ?>
    </div>

    <section class="card p-5">
        <dl class="grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
            <?php foreach ($rows as $label => $value): ?>
                <div class="flex justify-between gap-4 border-b border-brand-50 py-1.5"><dt class="shrink-0 text-slate-500"><?= e($label) ?></dt><dd class="min-w-0 break-words text-right font-medium text-brand-navy"><?= e((string) $value) ?></dd></div>
            <?php endforeach; ?>
        </dl>
        <?php if ($entry['remarks']): ?>
            <p class="mt-4 text-sm"><span class="text-slate-500">Remarks:</span> <?= e($entry['remarks']) ?></p>
        <?php endif; ?>
        <?php if ($entry['target_type'] && $entry['target_id']): ?>
            <a href="<?= e(url('/admin/audit-logs?target_type=' . rawurlencode($entry['target_type']) . '&target_id=' . (int) $entry['target_id'])) ?>" class="mt-4 inline-block text-sm font-semibold text-brand-600 hover:underline">Everything recorded about <?= e($entry['target_type'] . ' #' . $entry['target_id']) ?></a>
        <?php endif; ?>
    </section>

    <div class="grid gap-6 md:grid-cols-2">
        <section class="card p-5" aria-labelledby="old-heading">
            <h3 id="old-heading" class="mb-2 text-base">Before</h3>
            <pre class="table-scroll whitespace-pre-wrap break-words rounded-lg bg-brand-50/60 p-3 text-xs text-slate-800"><?= e($pretty($entry['old_values'])) ?></pre>
        </section>
        <section class="card p-5" aria-labelledby="new-heading">
            <h3 id="new-heading" class="mb-2 text-base">After</h3>
            <pre class="table-scroll whitespace-pre-wrap break-words rounded-lg bg-brand-50/60 p-3 text-xs text-slate-800"><?= e($pretty($entry['new_values'])) ?></pre>
        </section>
    </div>
</div>
