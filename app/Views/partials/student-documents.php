<?php
/**
 * A student's documents, grouped by kind, each opening in the preview window
 * (StudentDocuments::forStudent). Used by the student's own Documents page and by the
 * registrar's student record.
 *
 * @var array $documents  applications, receipts, statements, schedules, proofs
 * @var bool  $forStudent the student's own view (wording, schedules, proofs)
 */
use App\Models\Download;
use App\Models\EnrollmentApplication;
use App\Services\FinanceService;

$forStudent ??= false;
$row = 'flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-5 py-3';
$action = 'inline-flex items-center gap-1.5 rounded-lg border border-brand-100 bg-white px-2.5 py-1 text-sm font-semibold text-brand-600 hover:bg-brand-50';
?>
<div class="space-y-6">
    <section class="card" aria-labelledby="docs-requirements">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-100 px-5 py-4">
            <h3 id="docs-requirements" class="flex items-center gap-2 text-base"><span class="text-brand-600"><?= icon('requirements', 'h-5 w-5') ?></span> Enrollment documents</h3>
            <p class="text-sm text-brand-gray"><?= $forStudent ? 'The files you uploaded for each application.' : 'The current file of each requirement.' ?></p>
        </div>
        <?php if ($documents['applications'] === []): ?>
            <?= component('empty-state', ['title' => 'No documents uploaded', 'message' => $forStudent ? 'Files you upload for an enrollment application appear here.' : 'This student has not uploaded any document.']) ?>
        <?php else: ?>
            <?php foreach ($documents['applications'] as $application): ?>
                <div class="border-b border-brand-50 last:border-b-0">
                    <p class="flex flex-wrap items-center gap-2 bg-brand-50/60 px-5 py-2 text-xs font-semibold uppercase tracking-wide text-brand-navy">
                        <?= e($application['application_number']) ?> · <?= e($application['term']) ?>
                        <span class="font-normal normal-case tracking-normal text-brand-gray">(<?= e(strtolower(EnrollmentApplication::label($application['application_status']))) ?>)</span>
                    </p>
                    <ul class="divide-y divide-brand-50">
                        <?php foreach ($application['files'] as $file): ?>
                            <li class="<?= $row ?>">
                                <div class="min-w-0">
                                    <p class="font-semibold text-brand-navy"><?= e($file['name']) ?></p>
                                    <p class="truncate text-xs text-brand-gray"><?= e($file['original_filename']) ?> · <?= e(Download::humanSize((int) $file['file_size'])) ?> · <?= e(dt($file['uploaded_at'])) ?></p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <?= component('status-badge', ['status' => $file['status']]) ?>
                                    <?= partial('preview-link', [
                                        'path' => '/documents/' . $file['file_id'], 'mime' => $file['mime_type'], 'title' => $file['name'] . ' · ' . $file['original_filename'],
                                        'label' => 'Preview', 'srLabel' => $file['name'], 'icon' => 'document', 'group' => 'documents-' . $application['id'], 'class' => $action,
                                    ]) ?>
                                    <a href="<?= e(url('/documents/' . $file['file_id'])) ?>" class="<?= $action ?>"><?= icon('download', 'h-4 w-4') ?> <span class="sr-only sm:not-sr-only">Download</span><span class="sr-only"> <?= e($file['name']) ?></span></a>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <div class="grid gap-6 xl:grid-cols-2">
        <section class="card min-w-0" aria-labelledby="docs-receipts">
            <h3 id="docs-receipts" class="flex items-center gap-2 border-b border-brand-100 px-5 py-4 text-base"><span class="text-brand-600"><?= icon('receipt', 'h-5 w-5') ?></span> Official receipts</h3>
            <?php if ($documents['receipts'] === []): ?>
                <?= component('empty-state', ['title' => 'No receipts yet', 'message' => 'A receipt is issued for every payment the cashier records or verifies.']) ?>
            <?php else: ?>
                <ul class="divide-y divide-brand-50">
                    <?php foreach ($documents['receipts'] as $receipt): ?>
                        <li class="<?= $row ?>">
                            <div class="min-w-0">
                                <p class="font-semibold text-brand-navy"><?= e($receipt['receipt_number']) ?> · <?= e(money($receipt['amount'])) ?></p>
                                <p class="text-xs text-brand-gray"><?= e(d($receipt['paid_at'])) ?> · <?= e($receipt['payment_method']) ?> · <?= e($receipt['assessment_number']) ?></p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <?php if ($receipt['reversal_id'] !== null): ?><?= component('status-badge', ['status' => 'CANCELLED', 'label' => 'Reversed']) ?><?php endif; ?>
                                <?= partial('preview-link', ['path' => '/receipts/' . $receipt['id'], 'kind' => 'page', 'title' => 'Official receipt ' . $receipt['receipt_number'], 'label' => 'View', 'srLabel' => 'receipt ' . $receipt['receipt_number'], 'icon' => 'receipt', 'group' => 'documents-receipts', 'class' => $action]) ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="card min-w-0" aria-labelledby="docs-statements">
            <h3 id="docs-statements" class="flex items-center gap-2 border-b border-brand-100 px-5 py-4 text-base"><span class="text-brand-600"><?= icon('credit-card', 'h-5 w-5') ?></span> Statements of account</h3>
            <?php if ($documents['statements'] === []): ?>
                <?= component('empty-state', ['title' => 'No assessments yet', 'message' => 'A statement is available once fees are assessed for an approved application.']) ?>
            <?php else: ?>
                <ul class="divide-y divide-brand-50">
                    <?php foreach ($documents['statements'] as $statement): ?>
                        <li class="<?= $row ?>">
                            <div class="min-w-0">
                                <p class="font-semibold text-brand-navy"><?= e($statement['assessment_number']) ?> · <?= e($statement['term_name'] . ' ' . $statement['academic_year_code']) ?></p>
                                <p class="text-xs text-brand-gray">Balance <?= e(money($statement['balance'])) ?> of <?= e(money(FinanceService::money(max(0, FinanceService::dueCents($statement))))) ?></p>
                            </div>
                            <?= partial('preview-link', ['path' => '/statements/' . $statement['id'], 'kind' => 'page', 'title' => 'Statement of account · ' . $statement['assessment_number'], 'label' => 'View', 'srLabel' => 'statement ' . $statement['assessment_number'], 'icon' => 'document', 'group' => 'documents-statements', 'class' => $action]) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <?php if ($forStudent): ?>
            <section class="card min-w-0" aria-labelledby="docs-schedules">
                <h3 id="docs-schedules" class="flex items-center gap-2 border-b border-brand-100 px-5 py-4 text-base"><span class="text-brand-600"><?= icon('calendar', 'h-5 w-5') ?></span> Class schedules</h3>
                <?php if ($documents['schedules'] === []): ?>
                    <?= component('empty-state', ['title' => 'No schedule yet', 'message' => 'Your class schedule is available once you are officially enrolled.']) ?>
                <?php else: ?>
                    <ul class="divide-y divide-brand-50">
                        <?php foreach ($documents['schedules'] as $enrollment): ?>
                            <li class="<?= $row ?>">
                                <div class="min-w-0">
                                    <p class="font-semibold text-brand-navy"><?= e($enrollment['term_name'] . ', A.Y. ' . $enrollment['academic_year_code']) ?></p>
                                    <p class="text-xs text-brand-gray"><?= e($enrollment['program_code'] . ' · ' . $enrollment['section_code']) ?></p>
                                </div>
                                <?= partial('preview-link', ['path' => '/student/schedule/print?term_id=' . $enrollment['term_id'], 'kind' => 'page', 'title' => 'Class schedule · ' . $enrollment['term_name'] . ' ' . $enrollment['academic_year_code'], 'label' => 'View', 'srLabel' => 'schedule for ' . $enrollment['term_name'], 'icon' => 'calendar', 'group' => 'documents-schedules', 'class' => $action]) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <section class="card min-w-0" aria-labelledby="docs-proofs">
                <h3 id="docs-proofs" class="flex items-center gap-2 border-b border-brand-100 px-5 py-4 text-base"><span class="text-brand-600"><?= icon('upload', 'h-5 w-5') ?></span> Proofs of payment you sent</h3>
                <?php if ($documents['proofs'] === []): ?>
                    <?= component('empty-state', ['title' => 'No online payments', 'message' => 'Proofs you send with an online payment appear here.']) ?>
                <?php else: ?>
                    <ul class="divide-y divide-brand-50">
                        <?php foreach ($documents['proofs'] as $proof): ?>
                            <li class="<?= $row ?>">
                                <div class="min-w-0">
                                    <p class="font-semibold text-brand-navy"><?= e(money($proof['amount'])) ?> · <?= e($proof['method']) ?></p>
                                    <p class="truncate text-xs text-brand-gray">Ref. <?= e($proof['reference_number']) ?> · paid <?= e(d($proof['paid_on'])) ?></p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <?= component('status-badge', match ($proof['status']) {
                                        'APPROVED' => ['status' => 'PAID', 'label' => 'Verified'],
                                        'REJECTED' => ['status' => 'REJECTED', 'label' => 'Not verified'],
                                        default    => ['status' => 'UNDER_REVIEW', 'label' => 'Being verified'],
                                    }) ?>
                                    <?= partial('preview-link', ['path' => '/payment-proofs/' . $proof['id'], 'mime' => $proof['mime_type'], 'title' => 'Proof of payment · Ref. ' . $proof['reference_number'], 'label' => 'Preview', 'srLabel' => 'proof ' . $proof['reference_number'], 'icon' => 'document', 'group' => 'documents-proofs', 'class' => $action]) ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</div>
