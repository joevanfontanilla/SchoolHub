<?php
/**
 * One group in the admission requirements editor (System Settings): a heading and one requirement per line.
 *
 * @var string $n        the group's number in the form (or __N__ in the template for a new one)
 * @var string $label    the legend
 * @var array  $row      title, items
 * @var bool   $template true for the blank copy that "Add another group" clones
 */
$template ??= false;
$titleError = $template ? null : error_for("groups.{$n}.title");
$itemsError = $template ? null : error_for("groups.{$n}.items");
?>
<fieldset class="rounded-xl border border-slate-200 p-4" data-requirement-group>
    <legend class="px-1 text-sm font-semibold text-brand-navy"><?= e($label) ?></legend>
    <label for="group-title-<?= e($n) ?>" class="field-label">Heading</label>
    <input id="group-title-<?= e($n) ?>" name="groups[<?= e($n) ?>][title]" type="text" maxlength="100" value="<?= e((string) ($row['title'] ?? '')) ?>" class="field-input" <?= $titleError ? 'aria-invalid="true"' : '' ?>>
    <?php if ($titleError): ?><p class="field-error mt-1"><?= e($titleError) ?></p><?php endif; ?>
    <label for="group-items-<?= e($n) ?>" class="field-label mt-3">Requirements (one per line)</label>
    <textarea id="group-items-<?= e($n) ?>" name="groups[<?= e($n) ?>][items]" rows="6" class="field-input" <?= $itemsError ? 'aria-invalid="true"' : '' ?>><?= e((string) ($row['items'] ?? '')) ?></textarea>
    <?php if ($itemsError): ?><p class="field-error mt-1"><?= e($itemsError) ?></p><?php endif; ?>
</fieldset>
