<?php
/**
 * Printable document layout (docs/15): a school letterhead and the document,
 * on a white page. "Print" uses the browser's print dialog, where it can also be
 * saved as PDF. Nothing but the document itself is printed.
 *
 * @var string $content
 * @var string $title
 * @var array  $school
 * @var ?string $back where the Back link goes
 */
$schoolName = $school['school_name'] ?? 'Lyceum of the East Aurora';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Document') ?> · <?= e($schoolName) ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/app.css')) ?>">
    <link rel="icon" type="image/jpeg" href="<?= e(asset('assets/images/lyceum-logo.jpg')) ?>">
</head>
<body class="bg-brand-canvas font-sans text-slate-800 print:bg-white">
    <div class="no-print sticky top-0 z-10 border-b border-brand-100 bg-white/95 px-4 py-3 backdrop-blur">
        <div class="mx-auto flex max-w-4xl flex-wrap items-center justify-between gap-3">
            <a href="<?= e(url($back ?? '/')) ?>" class="inline-flex items-center gap-2 text-sm font-semibold text-brand-600 hover:underline"><?= icon('arrow-left', 'h-4 w-4') ?> Back</a>
            <button type="button" class="btn-primary" data-print><?= icon('download', 'h-5 w-5') ?> Print or save as PDF</button>
        </div>
    </div>

    <main class="mx-auto my-6 max-w-4xl bg-white p-6 shadow-sm ring-1 ring-brand-100 sm:p-10 print:my-0 print:max-w-none print:p-0 print:shadow-none print:ring-0">
        <header class="flex items-center gap-4 border-b-2 border-brand-navy pb-4">
            <img src="<?= e(asset('assets/images/lyceum-logo.jpg')) ?>" alt="" width="72" height="72" class="school-seal h-16 w-16">
            <div class="min-w-0 flex-1">
                <p class="font-display text-xl font-extrabold uppercase tracking-tight text-brand-navy"><?= e($schoolName) ?></p>
                <p class="text-sm text-slate-600"><?= e($school['school_address'] ?? '') ?></p>
                <p class="text-xs text-slate-500"><?= e(trim(($school['school_phone'] ?? '') . ' · ' . ($school['school_email'] ?? ''), ' ·')) ?></p>
            </div>
        </header>

        <?= $content ?>

        <footer class="mt-10 border-t border-slate-200 pt-3 text-xs text-slate-500">
            Printed <?= e(date('M j, Y g:i A')) ?> from SchoolHub.
        </footer>
    </main>

    <script src="<?= e(asset('assets/app.js')) ?>" defer></script>
</body>
</html>
