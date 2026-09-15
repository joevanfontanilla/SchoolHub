<?php
/**
 * Backups (docs/20). Restoring is a command-line step on the server.
 *
 * @var array $backups
 */
use App\Services\SystemHealth;

$badge = static fn (string $status) => component('status-badge', match ($status) {
    'COMPLETED' => ['status' => 'APPROVED', 'label' => 'Completed'],
    'RUNNING'   => ['status' => 'UNDER_REVIEW', 'label' => 'Running'],
    'FAILED'    => ['status' => 'REJECTED', 'label' => 'Failed'],
    default     => ['status' => 'ARCHIVED', 'label' => 'File deleted'],
});
$types = ['MANUAL' => 'Manual', 'SCHEDULED' => 'Scheduled', 'PRE_RESTORE' => 'Before a restore'];
$passwordError = error_for('password');
// Under Apache PHP_BINARY is the web server, so find the command-line php beside the PHP install.
$iniDir = php_ini_loaded_file() ? dirname((string) php_ini_loaded_file()) : '';
$php = match (true) {
    $iniDir !== '' && is_file($iniDir . DIRECTORY_SEPARATOR . 'php.exe') => $iniDir . DIRECTORY_SEPARATOR . 'php.exe',
    is_file(PHP_BINDIR . DIRECTORY_SEPARATOR . 'php.exe') => PHP_BINDIR . DIRECTORY_SEPARATOR . 'php.exe',
    is_file(PHP_BINDIR . DIRECTORY_SEPARATOR . 'php')     => PHP_BINDIR . DIRECTORY_SEPARATOR . 'php',
    default                                                => 'php',
};
?>
<div class="space-y-6">
    <div>
        <p class="eyebrow">Monitoring</p>
        <h2 class="mt-1 text-2xl">Backups</h2>
        <p class="mt-1 max-w-3xl text-sm text-slate-600">A backup is one archive with the whole database and, if enabled in System Settings, every uploaded file. Each is checksummed so damage or tampering is detected. Archives are stored outside the website and contain personal data: keep downloaded copies safe.</p>
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <section class="card min-w-0 p-5" aria-labelledby="create-heading">
            <h3 id="create-heading" class="flex items-center gap-2 text-base"><span class="text-brand-600"><?= icon('database', 'h-5 w-5') ?></span> Back up now</h3>
            <form method="POST" action="<?= e(url('/admin/backups/create')) ?>" class="mt-3 space-y-3" data-confirm="Create a backup now? This can take a moment.">
                <div>
                    <label for="backup-notes" class="field-label">Note (optional)</label>
                    <input id="backup-notes" name="notes" maxlength="255" class="field-input" placeholder="e.g. Before the start of enrollment">
                </div>
                <button type="submit" class="btn-primary w-full">Create backup</button>
                <?= csrf_field() ?>
            </form>
        </section>

        <section class="card min-w-0 p-5 xl:col-span-2" aria-labelledby="schedule-heading">
            <h3 id="schedule-heading" class="flex items-center gap-2 text-base"><span class="text-brand-600"><?= icon('clock', 'h-5 w-5') ?></span> Daily backup and restore</h3>
            <p class="mt-2 text-sm text-slate-600">In Windows Task Scheduler, create a daily task at <strong>2:00 AM</strong> that runs:</p>
            <pre class="table-scroll mt-2 rounded-lg bg-brand-50/60 p-3 text-xs text-slate-800"><?= e('"' . $php . '" "' . BASE_PATH . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'backup.php" --scheduled') ?></pre>
            <p class="mt-3 text-sm text-slate-600"><strong>Restoring</strong> replaces the entire database and all uploaded files, so it is not available here. On the server, run the command below and follow its prompts. It verifies the archive and makes a safety backup first.</p>
            <pre class="table-scroll mt-2 rounded-lg bg-brand-50/60 p-3 text-xs text-slate-800"><?= e('"' . $php . '" "' . BASE_PATH . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'restore.php" <backup file name>') ?></pre>
        </section>
    </div>

    <section class="card" aria-labelledby="history-heading">
        <h3 id="history-heading" class="border-b border-brand-100 px-5 py-4 text-base">History</h3>
        <?php if ($backups === []): ?>
            <?= component('empty-state', ['title' => 'No backups yet', 'message' => 'Create the first one above, and schedule a daily backup.']) ?>
        <?php else: ?>
            <ul class="divide-y divide-brand-50">
                <?php foreach ($backups as $backup): ?>
                    <li class="px-5 py-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="break-all font-mono text-sm font-semibold text-brand-navy"><?= e($backup['filename']) ?></p>
                                <p class="mt-0.5 text-xs text-brand-gray">
                                    <?= e($types[$backup['type']] ?? $backup['type']) ?> · <?= e(dt($backup['started_at'])) ?> · <?= e($backup['created_by_name'] ?? 'Scheduled task / command line') ?>
                                    <?php if ($backup['status'] === 'COMPLETED' || $backup['status'] === 'DELETED'): ?> · <?= e(SystemHealth::bytes((int) $backup['size_bytes'])) ?> · <?= (int) $backup['table_count'] ?> tables<?= (int) $backup['includes_uploads'] === 1 ? ' · ' . (int) $backup['upload_count'] . ' files' : ' · database only' ?><?php endif; ?>
                                </p>
                                <?php if ($backup['notes']): ?><p class="mt-1 text-sm text-slate-600"><?= e($backup['notes']) ?></p><?php endif; ?>
                                <?php if ($backup['verify_result']): ?><p class="mt-1 text-xs <?= str_starts_with($backup['verify_result'], 'OK') ? 'text-success-text' : 'text-danger-text' ?>">Verified <?= e(dt($backup['verified_at'])) ?>: <?= e($backup['verify_result']) ?></p><?php endif; ?>
                                <?php if ($backup['status'] === 'FAILED' && $backup['error']): ?><p class="mt-1 text-xs text-danger-text"><?= e(str_limit($backup['error'], 300)) ?></p><?php endif; ?>
                                <?php if ($backup['checksum']): ?><p class="mt-1 break-all font-mono text-[11px] text-brand-gray">SHA-256 <?= e($backup['checksum']) ?></p><?php endif; ?>
                            </div>
                            <?= $badge($backup['status']) ?>
                        </div>

                        <?php if ($backup['status'] === 'COMPLETED'): ?>
                            <div class="mt-3 flex flex-wrap items-start gap-3">
                                <form method="POST" action="<?= e(url('/admin/backups/' . $backup['id'] . '/verify')) ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn-secondary"><?= icon('shield', 'h-5 w-5') ?> Verify</button>
                                </form>
                                <details class="min-w-[16rem]">
                                    <summary class="btn-secondary cursor-pointer list-none [&::-webkit-details-marker]:hidden"><?= icon('download', 'h-5 w-5') ?> Download or delete</summary>
                                    <div class="mt-2 space-y-2 rounded-xl border border-brand-100 p-3">
                                        <p class="text-xs text-slate-600">Confirm with your own password.</p>
                                        <form method="POST" action="<?= e(url('/admin/backups/' . $backup['id'] . '/download')) ?>" class="flex flex-wrap items-end gap-2">
                                            <div class="min-w-0 flex-1">
                                                <label for="download-password-<?= (int) $backup['id'] ?>" class="sr-only">Your password</label>
                                                <input id="download-password-<?= (int) $backup['id'] ?>" type="password" name="password" autocomplete="current-password" required class="field-input" placeholder="Your password">
                                            </div>
                                            <button type="submit" class="btn-primary">Download</button>
                                            <?= csrf_field() ?>
                                        </form>
                                        <form method="POST" action="<?= e(url('/admin/backups/' . $backup['id'] . '/delete')) ?>" class="flex flex-wrap items-end gap-2" data-confirm="Delete this backup file? This cannot be undone.">
                                            <div class="min-w-0 flex-1">
                                                <label for="delete-password-<?= (int) $backup['id'] ?>" class="sr-only">Your password</label>
                                                <input id="delete-password-<?= (int) $backup['id'] ?>" type="password" name="password" autocomplete="current-password" required class="field-input" placeholder="Your password">
                                            </div>
                                            <button type="submit" class="btn-danger">Delete file</button>
                                            <?= csrf_field() ?>
                                        </form>
                                    </div>
                                </details>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <?php if ($passwordError): ?>
        <p class="sr-only" role="alert"><?= e($passwordError) ?></p>
    <?php endif; ?>
</div>
