<?php
/**
 * Add or edit one file on the public Downloads page (docs/06).
 *
 * @var ?array $download
 */
use App\Models\Download;
use App\Services\DownloadService;

$editing = $download !== null;
$val = static fn (string $field, string $default = '') => old($field, $download[$field] ?? $default);
$isPublic = errors() !== [] ? (bool) old('is_public', false) : (!$editing || (int) $download['is_public'] === 1);
$types = strtoupper(implode(', ', array_keys(DownloadService::TYPES)));
?>
<div class="mx-auto max-w-2xl space-y-6">
    <a href="<?= e(url('/admin/downloads')) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; Downloads</a>
    <div>
        <h2 class="text-2xl"><?= $editing ? 'Edit ' . e($download['title']) : 'Add a file' ?></h2>
        <p class="mt-1 text-sm text-slate-600">What visitors see on the public Downloads page.</p>
    </div>
    <form method="POST" action="<?= e(url($editing ? '/admin/downloads/' . $download['id'] : '/admin/downloads')) ?>" enctype="multipart/form-data" class="card space-y-5 p-5 sm:p-6" novalidate>
        <?= csrf_field() ?>
        <?= component('field', ['name' => 'title', 'label' => 'Title', 'required' => true, 'value' => $val('title'), 'help' => 'e.g. Enrollment form']) ?>
        <?= component('field', ['name' => 'description', 'label' => 'Description', 'value' => $val('description'), 'help' => 'Optional, one short line.']) ?>
        <?= component('field', ['name' => 'sort_order', 'label' => 'Order', 'type' => 'number', 'value' => $val('sort_order', '0'), 'help' => 'The smallest number is listed first.']) ?>

        <div>
            <label for="file" class="field-label"><?= $editing ? 'Replace the file' : 'File' ?><?= $editing ? '' : ' <span class="text-danger-text" aria-hidden="true">*</span>' ?></label>
            <input id="file" type="file" name="file" class="field-input" <?= error_for('file') ? 'aria-invalid="true"' : '' ?>>
            <p class="mt-1 text-xs text-brand-gray"><?= e($types) ?>, up to <?= e((string) (DownloadService::MAX_BYTES / 1048576)) ?> MB. The file's contents are checked, not just its name.</p>
            <?php if ($editing): ?><p class="mt-1 text-xs text-brand-gray">Now: <?= e($download['original_filename']) ?> (<?= e(Download::humanSize((int) $download['file_size'])) ?>). Leave empty to keep it.</p><?php endif; ?>
            <?php if (error_for('file')): ?><p class="field-error mt-1"><?= e(error_for('file')) ?></p><?php endif; ?>
        </div>

        <label class="flex items-start gap-2 text-sm text-slate-700">
            <input type="checkbox" name="is_public" value="1" class="mt-1 h-4 w-4 rounded border-slate-300 text-brand-600" <?= $isPublic ? 'checked' : '' ?>>
            <span>Show this file on the public Downloads page.</span>
        </label>

        <div class="flex gap-3">
            <button type="submit" class="btn-primary"><?= $editing ? 'Save' : 'Add the file' ?></button>
            <a href="<?= e(url('/admin/downloads')) ?>" class="btn-secondary">Cancel</a>
        </div>
    </form>
</div>
