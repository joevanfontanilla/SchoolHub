<?php
/**
 * Dashboard summary card with an icon tile (docs/21). The tone colors the tile
 * and value; the value and hint always say the same thing in words.
 *
 * @var string  $label
 * @var string  $value
 * @var string  $hint
 * @var string  $tone  neutral | warning | danger | success
 * @var ?string $icon  icon name (app/Helpers/icons.php)
 * @var ?string $href  app path the card opens: the list behind the number
 */
$href ??= null;
$tone = $tone ?? 'neutral';
[$accent, $tile] = match ($tone) {
    'warning' => ['text-warning-text', 'bg-warning-bg text-warning-text'],
    'danger'  => ['text-danger-text', 'bg-danger-bg text-danger-text'],
    'success' => ['text-success-text', 'bg-success-bg text-success-text'],
    default   => ['text-brand-navy', 'bg-brand-50 text-brand-600'],
};
?>
<?php if ($href !== null): ?>
<a href="<?= e(url($href)) ?>" class="card group relative flex items-start gap-4 p-5 pr-9 transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">
<?php else: ?>
<div class="card flex items-start gap-4 p-5">
<?php endif; ?>
    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl <?= $tile ?>"><?= icon($icon ?? 'chart-bar', 'h-6 w-6') ?></span>
    <div class="min-w-0 flex-1">
        <p class="text-sm font-medium text-brand-gray"><?= e($label) ?></p>
        <p class="mt-1 font-display <?= mb_strlen((string) $value) > 9 ? 'text-xl' : 'text-2xl' ?> font-bold leading-tight <?= $accent ?>"><?= e($value) ?></p>
        <?php if (!empty($hint)): ?>
            <p class="mt-0.5 text-xs text-brand-gray"><?= e($hint) ?></p>
        <?php endif; ?>
    </div>
<?php if ($href !== null): ?>
    <span class="absolute right-3 top-4 text-brand-200 transition group-hover:translate-x-0.5 group-hover:text-brand-600"><?= icon('chevron-right', 'h-5 w-5') ?></span>
</a>
<?php else: ?>
</div>
<?php endif; ?>
