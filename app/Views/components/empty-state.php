<?php
/**
 * @var string  $title
 * @var ?string $message
 * @var ?string $icon    icon name, an inbox by default
 */
?>
<div class="px-6 py-12 text-center">
    <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-600"><?= icon($icon ?? 'inbox', 'h-7 w-7') ?></span>
    <p class="mt-3 text-sm font-medium text-brand-navy"><?= e($title) ?></p>
    <?php if (!empty($message)): ?>
        <p class="mt-1 text-sm text-brand-gray"><?= e($message) ?></p>
    <?php endif; ?>
</div>
