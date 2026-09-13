<?php
/**
 * @var string  $title
 * @var ?string $message
 */
?>
<div class="px-6 py-12 text-center">
    <svg class="mx-auto h-10 w-10 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25M6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" />
    </svg>
    <p class="mt-3 text-sm font-medium text-brand-navy"><?= e($title) ?></p>
    <?php if (!empty($message)): ?>
        <p class="mt-1 text-sm text-brand-gray"><?= e($message) ?></p>
    <?php endif; ?>
</div>
