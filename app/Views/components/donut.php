<?php
/**
 * Ring chart for one percentage (docs/21). Pure SVG, no library. The figure is
 * also given in words through aria-label, and the caller shows it in text.
 *
 * @var float|int $percent 0–100
 * @var string    $title    large text in the middle, e.g. "2nd"
 * @var ?string   $subtitle small text under it, e.g. "Year"
 * @var string    $label    what the ring means, for screen readers
 * @var ?int      $size     pixels (default 160)
 */
$percent = max(0.0, min(100.0, (float) $percent));
$size = (int) ($size ?? 160);
$stroke = 16;
$radius = 60;
$circumference = 2 * M_PI * $radius;
$offset = $circumference * (1 - $percent / 100);
?>
<figure class="relative inline-flex shrink-0 items-center justify-center" style="width: <?= $size ?>px; height: <?= $size ?>px">
    <svg viewBox="0 0 160 160" width="<?= $size ?>" height="<?= $size ?>" role="img" aria-label="<?= e($label . ': ' . round($percent) . ' percent') ?>">
        <circle cx="80" cy="80" r="<?= $radius ?>" fill="none" stroke-width="<?= $stroke ?>" class="stroke-brand-100"/>
        <circle cx="80" cy="80" r="<?= $radius ?>" fill="none" stroke-width="<?= $stroke ?>" stroke-linecap="round"
                class="stroke-brand-600" transform="rotate(-90 80 80)"
                stroke-dasharray="<?= number_format($circumference, 2, '.', '') ?>"
                stroke-dashoffset="<?= number_format($percent >= 100 ? 0 : $offset, 2, '.', '') ?>"/>
    </svg>
    <figcaption class="absolute inset-0 flex flex-col items-center justify-center text-center" aria-hidden="true">
        <span class="font-display text-2xl font-extrabold leading-none text-brand-navy"><?= e($title) ?></span>
        <?php if (!empty($subtitle)): ?>
            <span class="mt-1 font-display text-sm font-bold text-brand-navy"><?= e($subtitle) ?></span>
        <?php endif; ?>
    </figcaption>
</figure>
