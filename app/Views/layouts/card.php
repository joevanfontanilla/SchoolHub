<?php
/**
 * Card printout layout (docs/29): bare pages, one card side per CR80 page, with a toolbar that is not printed.
 *
 * @var string $content
 * @var string $title
 * @var ?string $back
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e($title ?? 'E-ID') ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/app.css')) ?>">
    <style>
        @page { size: 54mm 85.6mm; margin: 0; }
        @media print {
            html, body { margin: 0; padding: 0; background: #fff !important; }
            .card-page { page-break-after: always; break-after: page; margin: 0 !important; padding: 0 !important; }
            .card-page:last-child { page-break-after: auto; break-after: auto; }
            .no-print, .screen-only { display: none !important; }
        }
        .card-page svg { display: block; width: 100%; height: 100%; }
    </style>
</head>
<body class="print-doc bg-brand-canvas font-sans text-slate-800">
    <div class="no-print sticky top-0 z-10 border-b border-brand-100 bg-white/95 px-4 py-3 backdrop-blur">
        <div class="mx-auto flex max-w-4xl flex-wrap items-center justify-between gap-3">
            <a href="<?= e(url($back ?? '/')) ?>" class="inline-flex items-center gap-2 text-sm font-semibold text-brand-600 hover:underline"><?= icon('arrow-left', 'h-4 w-4') ?> Back</a>
            <button type="button" class="btn-primary" data-print><?= icon('download', 'h-5 w-5') ?> Print the card (front, then back)</button>
        </div>
    </div>

    <main class="mx-auto flex max-w-4xl flex-wrap justify-center gap-8 p-6 print:block print:max-w-none print:p-0">
        <?= $content ?>
    </main>

    <script src="<?= e(asset('assets/app.js')) ?>" defer></script>
</body>
</html>
