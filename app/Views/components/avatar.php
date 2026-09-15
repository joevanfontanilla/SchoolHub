<?php
/**
 * A round avatar: the person's photo, or a placeholder icon when there is none.
 *
 * @var ?string $src   full image URL (already cache-busted), or null for the placeholder
 * @var string  $alt   alt text for the image
 * @var string  $size  Tailwind size classes for the circle (default h-16 w-16)
 * @var string  $icon  Tailwind size classes for the placeholder icon (default h-8 w-8)
 */
$src ??= null;
$alt ??= '';
$size ??= 'h-16 w-16';
$icon ??= 'h-8 w-8';
?>
<span class="flex <?= e($size) ?> shrink-0 items-center justify-center overflow-hidden rounded-full bg-brand-50 ring-1 ring-brand-100">
    <?php if ($src): ?>
        <img src="<?= e($src) ?>" alt="<?= e($alt) ?>" class="h-full w-full object-cover">
    <?php else: ?>
        <?= icon('user', $icon . ' text-brand-300') ?>
    <?php endif; ?>
</span>
