<?php
/**
 * Downloads (docs/06): the files on the public Downloads page.
 *
 * @var array $downloads
 */
use App\Models\Download;

$shown = count(array_filter($downloads, static fn (array $d): bool => (int) $d['is_public'] === 1));
?>
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">Administration</p>
            <h2 class="mt-1 text-2xl">Downloads</h2>
            <p class="mt-1 max-w-2xl text-sm text-slate-600">The forms and documents visitors can download from the public Downloads page. Hide a file to take it off the page without deleting it. The smallest order number is listed first.</p>
        </div>
        <a href="<?= e(url('/admin/downloads/create')) ?>" class="btn-primary"><?= icon('plus', 'h-5 w-5') ?> Add a file</a>
    </div>

    <section class="card">
        <?php if ($downloads === []): ?>
            <?= component('empty-state', ['title' => 'No files yet', 'message' => 'Add the enrollment form, the school calendar, or any document visitors should be able to download.']) ?>
        <?php else: ?>
            <p class="border-b border-brand-100 px-5 py-3 text-sm text-slate-600"><?= e((string) $shown) ?> of <?= e((string) count($downloads)) ?> shown on the public page.</p>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                    <thead class="bg-brand-50 text-brand-navy">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Order</th>
                            <th scope="col" class="px-4 py-3 font-semibold">File</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Downloads</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Status</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-50 bg-white">
                        <?php foreach ($downloads as $download): ?>
                            <?php $on = (int) $download['is_public'] === 1; ?>
                            <tr class="<?= $on ? '' : 'text-brand-gray' ?>">
                                <td class="px-4 py-3 tabular-nums"><?= e((string) $download['sort_order']) ?></td>
                                <td class="px-4 py-3">
                                    <span class="font-semibold <?= $on ? 'text-brand-navy' : '' ?>"><?= e($download['title']) ?></span>
                                    <span class="block text-xs text-brand-gray"><?= e($download['original_filename']) ?> · <?= e(Download::humanSize((int) $download['file_size'])) ?> · added <?= e(dt($download['created_at'])) ?><?= $download['uploaded_by_name'] ? ' by ' . e($download['uploaded_by_name']) : '' ?></span>
                                </td>
                                <td class="px-4 py-3 tabular-nums"><?= e((string) $download['download_count']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3"><?= component('status-badge', ['status' => $on ? 'OPEN' : 'CLOSED', 'label' => $on ? 'Shown' : 'Hidden']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <a href="<?= e(url('/admin/downloads/' . $download['id'] . '/file')) ?>" class="font-semibold text-brand-600 hover:underline">Open<span class="sr-only"> <?= e($download['title']) ?></span></a>
                                    <a href="<?= e(url('/admin/downloads/' . $download['id'] . '/edit')) ?>" class="ml-3 font-semibold text-brand-600 hover:underline">Edit<span class="sr-only"> <?= e($download['title']) ?></span></a>
                                    <form method="POST" action="<?= e(url('/admin/downloads/' . $download['id'] . '/toggle')) ?>" class="ml-3 inline"
                                          data-confirm="<?= e($on ? 'Visitors will no longer see this file on the Downloads page. It is not deleted.' : 'Visitors will see this file on the Downloads page.') ?>" data-confirm-title="<?= e($on ? 'Hide this file?' : 'Show this file?') ?>" data-confirm-tone="<?= $on ? 'warning' : 'success' ?>" data-confirm-label="<?= $on ? 'Hide' : 'Show' ?>">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="font-semibold text-brand-600 hover:underline"><?= $on ? 'Hide' : 'Show' ?><span class="sr-only"> <?= e($download['title']) ?></span></button>
                                    </form>
                                    <form method="POST" action="<?= e(url('/admin/downloads/' . $download['id'] . '/delete')) ?>" class="ml-3 inline"
                                          data-confirm="The file is removed from the server and the Downloads page. This cannot be undone." data-confirm-title="Delete this file?" data-confirm-tone="danger" data-confirm-label="Delete file">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="font-semibold text-danger-text hover:underline">Delete<span class="sr-only"> <?= e($download['title']) ?></span></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
