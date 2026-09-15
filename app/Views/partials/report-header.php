<?php
/**
 * Heading and tabs shared by the report pages of a role (docs/15).
 *
 * @var string $eyebrow  "Cashier reports"
 * @var array  $tabs     key => [path, label, icon]
 * @var string $active   key of the current tab
 * @var string $heading
 * @var string $subtitle
 */
?>
<div class="space-y-4">
    <div>
        <p class="eyebrow"><?= e($eyebrow) ?></p>
        <h2 class="mt-1 text-2xl"><?= e($heading) ?></h2>
        <p class="mt-1 text-sm text-slate-600"><?= e($subtitle) ?></p>
    </div>
    <nav aria-label="Reports" class="flex flex-wrap gap-2">
        <?php foreach ($tabs as $key => [$path, $label, $iconName]): ?>
            <a href="<?= e(url($path)) ?>" <?= $key === $active ? 'aria-current="page"' : '' ?>
               class="inline-flex items-center gap-2 rounded-full border px-4 py-2 text-sm font-semibold <?= $key === $active ? 'border-brand-600 bg-brand-600 text-white' : 'border-brand-100 bg-white text-brand-navy hover:border-brand-200 hover:bg-brand-50' ?>">
                <?= icon($iconName, 'h-4 w-4') ?> <?= e($label) ?>
            </a>
        <?php endforeach; ?>
    </nav>
</div>
