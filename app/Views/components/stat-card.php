<?php
/**
 * Dashboard summary card (docs/21).
 *
 * @var string $label
 * @var string $value
 * @var string $hint
 * @var string $tone  neutral | warning | danger | success
 */
$tone = $tone ?? 'neutral';
$accent = match ($tone) {
    'warning' => 'text-warning-text',
    'danger'  => 'text-danger-text',
    'success' => 'text-success-text',
    default   => 'text-brand-navy',
};
?>
<div class="card p-5">
    <p class="text-sm font-medium text-brand-gray"><?= e($label) ?></p>
    <p class="mt-2 text-2xl font-semibold <?= $accent ?>"><?= e($value) ?></p>
    <?php if (!empty($hint)): ?>
        <p class="mt-1 text-xs text-brand-gray"><?= e($hint) ?></p>
    <?php endif; ?>
</div>
