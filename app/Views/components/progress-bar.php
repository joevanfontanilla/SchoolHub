<?php
/**
 * Horizontal progress bar with its value in text (docs/21).
 *
 * @var float|int $percent 0–100
 * @var string    $label   what is measured (screen readers; visible when $showLabel)
 * @var ?bool     $showLabel
 * @var ?string   $tone    'brand' (default) | 'success' | 'warning'
 */
$percent = max(0.0, min(100.0, (float) $percent));
$fill = match ($tone ?? 'brand') {
    'success' => 'bg-success-text',
    'warning' => 'bg-brand-warning',
    default   => 'bg-brand-600',
};
$rounded = (int) round($percent);
?>
<div>
    <?php if (!empty($showLabel)): ?>
        <div class="mb-1.5 flex items-center justify-between text-sm">
            <span class="text-slate-700"><?= e($label) ?></span>
            <span class="font-display font-bold text-brand-navy"><?= $rounded ?>%</span>
        </div>
    <?php endif; ?>
    <div class="flex items-center gap-3">
        <div class="h-3 flex-1 overflow-hidden rounded-full bg-brand-100" role="progressbar"
             aria-valuenow="<?= $rounded ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?= e($label) ?>">
            <div class="h-full rounded-full <?= $fill ?>" style="width: <?= number_format($percent, 2, '.', '') ?>%"></div>
        </div>
        <?php if (empty($showLabel)): ?>
            <span class="w-10 text-right font-display text-sm font-bold text-brand-navy"><?= $rounded ?>%</span>
        <?php endif; ?>
    </div>
</div>
