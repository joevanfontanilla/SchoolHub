<?php
/**
 * Column chart (docs/21). Pure SVG; every bar carries its value in text, and the
 * same numbers are available to screen readers as a table.
 *
 * @var array<int,array{label:string,value:float,display?:string,sublabel?:string}> $items
 * @var string  $caption  what the chart shows
 * @var ?float  $max      top of the scale (default: the largest value)
 * @var ?string $unit     e.g. "units"
 */
$items = array_values($items);
$max = (float) ($max ?? 0) ?: max(1.0, ...array_map(static fn ($i) => (float) $i['value'], $items ?: [['value' => 1]]));
$count = max(1, count($items));
// At least six slots wide, so one or two terms still read as bars, centered.
$slots = max(6, $count);
$width = 100 * $slots;
$shift = ($slots - $count) * 50;
$chartHeight = 150;
$barWidth = 44;
?>
<figure class="w-full">
    <div class="table-scroll">
        <svg viewBox="0 0 <?= $width ?> <?= $chartHeight + 44 ?>" class="h-56 w-full min-w-[18rem]" preserveAspectRatio="xMidYMid meet" aria-hidden="true">
            <?php foreach ([0, 0.5, 1] as $grid): ?>
                <?php $y = 16 + $chartHeight * (1 - $grid); ?>
                <line x1="0" x2="<?= $width ?>" y1="<?= $y ?>" y2="<?= $y ?>" class="stroke-brand-100" stroke-width="1"/>
            <?php endforeach; ?>
            <?php foreach ($items as $index => $item): ?>
                <?php
                $height = $max > 0 ? $chartHeight * min(1, (float) $item['value'] / $max) : 0;
                $x = $shift + 100 * $index + (100 - $barWidth) / 2;
                $y = 16 + $chartHeight - $height;
                ?>
                <rect x="<?= $x ?>" y="<?= number_format($y, 2, '.', '') ?>" width="<?= $barWidth ?>" height="<?= number_format(max($height, 0), 2, '.', '') ?>" rx="6" class="fill-brand-primary"/>
                <text x="<?= $shift + 100 * $index + 50 ?>" y="<?= number_format(max(12, $y - 5), 2, '.', '') ?>" text-anchor="middle" class="fill-brand-navy font-display text-[13px] font-bold"><?= e($item['display'] ?? (string) $item['value']) ?></text>
                <text x="<?= $shift + 100 * $index + 50 ?>" y="<?= $chartHeight + 32 ?>" text-anchor="middle" class="fill-slate-600 text-[11px]"><?= e($item['label']) ?></text>
                <?php if (!empty($item['sublabel'])): ?>
                    <text x="<?= $shift + 100 * $index + 50 ?>" y="<?= $chartHeight + 44 ?>" text-anchor="middle" class="fill-slate-500 text-[10px]"><?= e($item['sublabel']) ?></text>
                <?php endif; ?>
            <?php endforeach; ?>
        </svg>
    </div>
    <figcaption class="sr-only">
        <table>
            <caption><?= e($caption) ?></caption>
            <?php foreach ($items as $item): ?>
                <tr><th scope="row"><?= e($item['label'] . (!empty($item['sublabel']) ? ' ' . $item['sublabel'] : '')) ?></th><td><?= e(($item['display'] ?? (string) $item['value']) . (!empty($unit) ? ' ' . $unit : '')) ?></td></tr>
            <?php endforeach; ?>
        </table>
    </figcaption>
</figure>
