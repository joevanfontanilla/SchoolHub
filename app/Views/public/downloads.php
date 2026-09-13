<?php
/**
 * Public downloads (docs/06).
 *
 * Files live under storage/ and are streamed by a controller route — the stored
 * filename is never exposed as a public URL.
 */
?>
<div class="mx-auto max-w-4xl px-4 py-12 sm:px-6 lg:px-8">
    <h1 class="text-2xl font-bold text-brand-navy sm:text-3xl">Downloads</h1>
    <p class="mt-2 text-slate-600">Forms and documents published by the school.</p>

    <?php if ($downloads === []): ?>
        <div class="card mt-8">
            <?= component('empty-state', [
                'title'   => 'No documents published yet',
                'message' => 'Downloadable forms will appear here.',
            ]) ?>
        </div>
    <?php else: ?>
        <ul class="mt-8 space-y-3">
            <?php foreach ($downloads as $download): ?>
                <li class="card flex flex-wrap items-center justify-between gap-4 p-5">
                    <div class="min-w-0">
                        <h2 class="text-sm font-semibold text-brand-navy"><?= e($download['title']) ?></h2>
                        <?php if (!empty($download['description'])): ?>
                            <p class="mt-1 text-sm text-slate-600"><?= e($download['description']) ?></p>
                        <?php endif; ?>
                        <p class="mt-1.5 text-xs text-brand-gray">
                            <?= e(App\Models\Download::humanSize((int) $download['file_size'])) ?>
                            · <?= e($download['download_count']) ?> downloads
                        </p>
                    </div>
                    <a href="<?= e(url('/downloads/' . $download['id'])) ?>" class="btn-secondary shrink-0">
                        Download
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
