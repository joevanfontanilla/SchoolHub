<?php
/**
 * My Documents (docs/08): the student's uploads, receipts, statements, schedules, and
 * proofs of payment.
 *
 * @var array $documents
 */
?>
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">Records</p>
            <h2 class="mt-1 text-2xl">My Documents</h2>
            <p class="mt-1 text-sm text-slate-600">Everything you uploaded or were issued. Open any document to preview it, then print it or save it as a PDF.</p>
        </div>
        <p class="rounded-full bg-brand-50 px-3 py-1 text-sm font-semibold text-brand-navy"><?= e(number_format($documents['count'])) ?> document<?= $documents['count'] === 1 ? '' : 's' ?></p>
    </div>

    <?= partial('student-documents', ['documents' => $documents, 'forStudent' => true]) ?>
</div>
