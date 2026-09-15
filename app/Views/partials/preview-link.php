<?php
/**
 * A link that opens a document in the preview window (docs/10, docs/15).
 *
 * Without JavaScript it still works: a file opens in a new tab and a page opens normally.
 * The server authorizes every request, whatever page linked to it.
 *
 * @var string  $path     app path of the document: /documents/5, /payment-proofs/3, /receipts/9
 * @var string  $kind     'file' (an uploaded PDF or image) or 'page' (a printable page)
 * @var ?string $mime     for files: the stored MIME type
 * @var string  $title    shown at the top of the preview window
 * @var string  $label    link text (plain text, escaped here)
 * @var ?string $group    links sharing a group get Previous and Next in the window
 * @var ?string $class
 * @var ?string $icon     icon name before the label
 * @var ?string $srLabel  extra words for screen readers after the label
 */
$kind ??= 'file';
$mime ??= null;
$group ??= null;
$class ??= 'font-semibold text-brand-600 hover:underline';
$icon ??= null;
$srLabel ??= null;

$separator = str_contains($path, '?') ? '&' : '?';
if ($kind === 'page') {
    $previewKind = 'page';
    $href = url($path);
    $preview = url($path . $separator . 'embed=1');
    $download = null;
} else {
    $previewKind = str_starts_with((string) $mime, 'image/') ? 'image' : 'pdf';
    $href = url($path . $separator . 'inline=1');
    $preview = $href;
    $download = url($path);
}
?>
<a href="<?= e($href) ?>" class="<?= e($class) ?>"
   data-preview="<?= e($preview) ?>" data-preview-kind="<?= e($previewKind) ?>" data-preview-title="<?= e($title) ?>"
   <?php if ($download !== null): ?>data-preview-download="<?= e($download) ?>" target="_blank" rel="noopener"<?php endif; ?>
   <?php if ($group !== null): ?>data-preview-group="<?= e($group) ?>"<?php endif; ?>><?php if ($icon !== null): ?><?= icon($icon, 'h-4 w-4 shrink-0') ?> <?php endif; ?><?= e($label) ?><?php if ($srLabel !== null): ?><span class="sr-only"> <?= e($srLabel) ?></span><?php endif; ?></a>
