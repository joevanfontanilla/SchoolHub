<?php
/**
 * A student requests official documents and tracks each request (docs/08).
 *
 * @var array<int,array<string,mixed>> $requests
 * @var array<string,string>           $types    code => label
 */
use App\Models\CredentialRequest;

$tone = [
    'REQUESTED'  => 'bg-brand-50 text-brand-700 ring-brand-200',
    'PROCESSING' => 'bg-warning-bg text-warning-text ring-warning-line',
    'READY'      => 'bg-success-bg text-success-text ring-success-line',
    'RELEASED'   => 'bg-slate-100 text-slate-600 ring-slate-200',
    'REJECTED'   => 'bg-danger-bg text-danger-text ring-danger-line',
    'CANCELLED'  => 'bg-slate-100 text-slate-500 ring-slate-200',
];
?>
<div class="mx-auto max-w-3xl space-y-6">
    <div>
        <p class="eyebrow">Student</p>
        <h2 class="mt-1 text-2xl">Document Requests</h2>
        <p class="mt-1 text-sm text-slate-600">Ask the registrar for an official document. You'll be notified as it is processed and when it is ready.</p>
    </div>

    <section class="card p-5 sm:p-6" aria-labelledby="request-heading">
        <h3 id="request-heading" class="text-base">Request a document</h3>
        <form method="POST" action="<?= e(url('/student/credentials')) ?>" class="mt-4 grid gap-4 sm:grid-cols-2" novalidate
              data-confirm="The registrar receives your request and will process it." data-confirm-title="Send this request?" data-confirm-tone="primary" data-confirm-label="Send request" data-confirm-requires="#field-type" data-confirm-requires-message="Choose which document you need.">
            <?= csrf_field() ?>
            <div class="sm:col-span-2">
                <label for="field-type" class="field-label">Document <span class="text-danger-text" aria-hidden="true">*</span></label>
                <select id="field-type" name="type" class="field-input <?= error_for('type') ? 'field-input-error' : '' ?>">
                    <option value="">Select a document…</option>
                    <?php foreach ($types as $code => $label): ?>
                        <option value="<?= e($code) ?>" <?= (string) old('type') === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($m = error_for('type')): ?><p class="field-error"><span><?= e($m) ?></span></p><?php endif; ?>
            </div>
            <?= component('field', ['name' => 'purpose', 'label' => 'Purpose (optional)', 'value' => old('purpose'), 'help' => 'e.g. for a scholarship, transfer, or employment.']) ?>
            <?= component('field', ['name' => 'copies', 'label' => 'Copies', 'type' => 'number', 'value' => old('copies', '1'), 'help' => 'Between 1 and 10.']) ?>
            <div class="sm:col-span-2">
                <button type="submit" class="btn-primary"><?= icon('send', 'h-5 w-5') ?> Send request</button>
            </div>
        </form>
    </section>

    <section aria-labelledby="mine-heading" class="space-y-3">
        <h3 id="mine-heading" class="text-base font-display font-bold text-brand-navy">Your requests</h3>
        <?php if ($requests === []): ?>
            <?= component('empty-state', ['title' => 'No requests yet', 'message' => 'Documents you request will appear here with their status.']) ?>
        <?php else: ?>
            <ul class="space-y-3">
                <?php foreach ($requests as $r): ?>
                    <li class="card p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-brand-navy"><?= e(CredentialRequest::typeLabel($r['type'])) ?><?= (int) $r['copies'] > 1 ? ' · ' . e($r['copies']) . ' copies' : '' ?></p>
                                <?php if ($r['purpose']): ?><p class="text-sm text-slate-600"><?= e($r['purpose']) ?></p><?php endif; ?>
                                <p class="mt-1 text-xs text-brand-gray">Requested <?= e(dt($r['requested_at'])) ?></p>
                                <?php if ($r['status'] === 'READY' && $r['fee_amount'] !== null): ?>
                                    <p class="mt-1 text-sm text-success-text">Fee: <?= e(money($r['fee_amount'])) ?> — pay at the cashier, then claim it at the registrar.</p>
                                <?php endif; ?>
                                <?php if ($r['remarks'] && in_array($r['status'], ['REJECTED', 'READY', 'RELEASED'], true)): ?>
                                    <p class="mt-1 text-sm text-slate-600"><span class="font-semibold">Note:</span> <?= e($r['remarks']) ?></p>
                                <?php endif; ?>
                            </div>
                            <span class="inline-flex shrink-0 items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 <?= $tone[$r['status']] ?? 'bg-slate-100 text-slate-600 ring-slate-200' ?>">
                                <?= e(CredentialRequest::STATUS_LABELS[$r['status']] ?? $r['status']) ?>
                            </span>
                        </div>
                        <?php if ($r['status'] === 'REQUESTED'): ?>
                            <form method="POST" action="<?= e(url('/student/credentials/' . $r['id'] . '/cancel')) ?>" class="mt-3"
                                  data-confirm="This withdraws your request. You can send a new one later." data-confirm-title="Cancel this request?" data-confirm-tone="danger" data-confirm-label="Cancel request">
                                <?= csrf_field() ?>
                                <button type="submit" class="text-sm font-semibold text-danger-text hover:underline">Cancel request</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
