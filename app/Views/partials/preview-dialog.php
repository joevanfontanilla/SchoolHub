<?php
/**
 * The document preview window, once per page (docs/21). Filled and opened by app.js
 * from a link made with partials/preview-link. A native <dialog>: Escape closes it,
 * focus stays inside while it is open and returns to the link afterwards.
 */
$tool = 'inline-flex items-center gap-1.5 rounded-lg border border-brand-100 bg-white px-3 py-1.5 text-sm font-semibold text-brand-navy hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-white';
?>
<dialog class="preview-dialog" aria-labelledby="preview-title" data-preview-dialog>
    <div class="flex h-full flex-col">
        <div class="flex flex-wrap items-center gap-2 border-b border-brand-100 bg-white px-4 py-3">
            <div class="min-w-0 flex-1">
                <h2 id="preview-title" class="truncate text-base" data-preview-heading>Document</h2>
                <p class="text-xs text-brand-gray" data-preview-position hidden></p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" class="<?= $tool ?>" data-preview-prev><?= icon('arrow-left', 'h-4 w-4') ?><span>Previous</span></button>
                <button type="button" class="<?= $tool ?>" data-preview-next><span>Next</span><?= icon('arrow-right', 'h-4 w-4') ?></button>
                <button type="button" class="<?= $tool ?>" data-preview-print hidden><?= icon('document', 'h-4 w-4') ?><span>Print</span></button>
                <a class="<?= $tool ?>" data-preview-download hidden><?= icon('download', 'h-4 w-4') ?><span>Download</span></a>
                <a class="<?= $tool ?>" target="_blank" rel="noopener" data-preview-open><?= icon('arrow-right', 'h-4 w-4') ?><span>Open in new tab</span></a>
                <button type="button" class="<?= $tool ?>" data-preview-close><?= icon('x', 'h-4 w-4') ?><span>Close</span></button>
            </div>
        </div>
        <div class="relative min-h-0 flex-1 overflow-auto bg-slate-100" data-preview-body></div>
        <p class="border-t border-brand-100 bg-white px-4 py-2 text-xs text-brand-gray" data-preview-hint hidden>Not showing? Some phones cannot display PDFs inside a page: use <strong>Open in new tab</strong> or <strong>Download</strong>.</p>
    </div>
</dialog>
