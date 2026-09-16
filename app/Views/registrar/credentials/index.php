<?php
/**
 * The registrar processes student document requests (docs/08): advance the status, note a fee,
 * or reject with a reason. The student is notified on each change.
 *
 * @var array<int,array<string,mixed>> $requests
 * @var string                         $status   current filter
 */
use App\Models\CredentialRequest;

$filters = ['OPEN' => 'To process', '' => 'All', 'RELEASED' => 'Released', 'REJECTED' => 'Rejected', 'CANCELLED' => 'Cancelled'];
$tone = [
    'REQUESTED'  => 'bg-brand-50 text-brand-700 ring-brand-200',
    'PROCESSING' => 'bg-warning-bg text-warning-text ring-warning-line',
    'READY'      => 'bg-success-bg text-success-text ring-success-line',
    'RELEASED'   => 'bg-slate-100 text-slate-600 ring-slate-200',
    'REJECTED'   => 'bg-danger-bg text-danger-text ring-danger-line',
    'CANCELLED'  => 'bg-slate-100 text-slate-500 ring-slate-200',
];
// What the registrar may move each open status to, and the button tone for each target.
$moves = [
    'REQUESTED'  => [['PROCESSING', 'Start processing', 'primary'], ['READY', 'Mark ready', 'success'], ['REJECTED', 'Reject', 'danger']],
    'PROCESSING' => [['READY', 'Mark ready', 'success'], ['REJECTED', 'Reject', 'danger']],
    'READY'      => [['RELEASED', 'Mark released', 'success'], ['REJECTED', 'Reject', 'danger']],
];
$btn = ['primary' => 'btn-primary', 'success' => 'btn-success', 'danger' => 'btn-danger'];
?>
<div class="mx-auto max-w-4xl space-y-6">
    <div>
        <p class="eyebrow">Enrollment</p>
        <h2 class="mt-1 text-2xl">Credential Requests</h2>
        <p class="mt-1 text-sm text-slate-600">Students' requests for official documents. Advancing a request notifies the student.</p>
    </div>

    <nav aria-label="Filter" class="flex flex-wrap gap-2">
        <?php foreach ($filters as $key => $label): ?>
            <a href="<?= e(url('/registrar/credentials' . ($key !== '' ? '?status=' . $key : ''))) ?>"
               class="rounded-lg px-3 py-1.5 text-sm font-semibold <?= $status === $key ? 'bg-brand-600 text-white' : 'bg-white text-brand-600 ring-1 ring-brand-200 hover:bg-brand-50' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($requests === []): ?>
        <?= component('empty-state', ['title' => 'Nothing here', 'message' => 'No document requests match this filter.']) ?>
    <?php else: ?>
        <ul class="space-y-3">
            <?php foreach ($requests as $r): ?>
                <li class="card p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-semibold text-brand-navy"><?= e(CredentialRequest::typeLabel($r['type'])) ?><?= (int) $r['copies'] > 1 ? ' · ' . e($r['copies']) . ' copies' : '' ?></p>
                            <p class="text-sm text-slate-600">
                                <a href="<?= e(url('/registrar/students/' . $r['student_id'])) ?>" class="font-semibold text-brand-600 hover:underline"><?= e($r['student_name']) ?></a>
                                · <?= e($r['student_number'] ?? 'No number') ?><?= $r['program_code'] ? ' · ' . e($r['program_code']) : '' ?>
                            </p>
                            <?php if ($r['purpose']): ?><p class="mt-1 text-sm text-slate-600">Purpose: <?= e($r['purpose']) ?></p><?php endif; ?>
                            <p class="mt-1 text-xs text-brand-gray">Requested <?= e(dt($r['requested_at'])) ?><?= $r['fee_amount'] !== null ? ' · Fee ' . e(money($r['fee_amount'])) : '' ?></p>
                            <?php if ($r['remarks']): ?><p class="mt-1 text-sm text-slate-600"><span class="font-semibold">Note:</span> <?= e($r['remarks']) ?></p><?php endif; ?>
                        </div>
                        <span class="inline-flex shrink-0 items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 <?= $tone[$r['status']] ?? '' ?>"><?= e(CredentialRequest::STATUS_LABELS[$r['status']] ?? $r['status']) ?></span>
                    </div>

                    <?php if (isset($moves[$r['status']])): $rid = (int) $r['id']; ?>
                        <form method="POST" action="<?= e(url('/registrar/credentials/' . $rid)) ?>" class="mt-4 space-y-3 border-t border-slate-100 pt-4" novalidate>
                            <?= csrf_field() ?>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label for="fee-<?= e($rid) ?>" class="field-label">Fee (optional)</label>
                                    <input id="fee-<?= e($rid) ?>" type="text" inputmode="decimal" name="fee_amount" value="<?= e($r['fee_amount'] ?? '') ?>" placeholder="e.g. 50.00" class="field-input">
                                </div>
                                <div>
                                    <label for="note-<?= e($rid) ?>" class="field-label">Note to the student</label>
                                    <input id="note-<?= e($rid) ?>" type="text" name="remarks" maxlength="255" value="" class="field-input" placeholder="Required when rejecting">
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <?php foreach ($moves[$r['status']] as [$to, $label, $t]): ?>
                                    <button type="submit" name="status" value="<?= e($to) ?>" class="<?= e($btn[$t]) ?>"
                                            data-confirm-click="<?= $to === 'REJECTED' ? 'The student is told the request was not approved, with your note.' : 'The student is notified of this update.' ?>"
                                            data-confirm-title="<?= e($label) ?>?" data-confirm-tone="<?= $t === 'primary' ? 'primary' : ($t === 'danger' ? 'danger' : 'success') ?>" data-confirm-label="<?= e($label) ?>"
                                            <?= $to === 'REJECTED' ? 'data-confirm-requires="#note-' . e($rid) . '" data-confirm-requires-message="Give the student a reason for rejecting the request."' : '' ?>><?= e($label) ?></button>
                                <?php endforeach; ?>
                            </div>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
