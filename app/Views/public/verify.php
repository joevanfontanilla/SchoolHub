<?php
/**
 * Verify an ID (docs/29): a box for a code or the address from a scanned QR.
 *
 * @var ?string $notice
 */
?>
<section class="bg-brand-pale" aria-labelledby="verify-heading">
    <div class="mx-auto max-w-xl px-4 py-16 sm:px-6">
        <p class="eyebrow">Verify an ID</p>
        <h1 id="verify-heading" class="mt-2 text-3xl">Check a school ID</h1>
        <p class="mt-3 text-slate-600">Scan the QR code on the back of the card with a phone camera, and this page opens by itself. Or type the code printed in the address below the QR.</p>

        <?php if ($notice !== null): ?>
            <p class="mt-6 rounded-xl border border-warning-line bg-warning-bg px-4 py-3 text-sm text-warning-text" role="status"><?= e($notice) ?></p>
        <?php endif; ?>

        <form method="GET" role="search" aria-label="Verify an ID" action="<?= e(url('/verify')) ?>" class="mt-6 card flex flex-wrap gap-3 p-5" novalidate>
            <div class="min-w-0 flex-1">
                <label for="verify-code" class="field-label">ID code or address</label>
                <input id="verify-code" name="code" class="field-input" autocomplete="off" autofocus placeholder="Paste or type the code">
            </div>
            <div class="self-end"><button type="submit" class="btn-primary">Check ID</button></div>
        </form>
        <p class="mt-4 text-sm text-slate-500">Only the photo, name, ID number, and program or position are shown. Nothing else about the person is available here.</p>
    </div>
</section>
