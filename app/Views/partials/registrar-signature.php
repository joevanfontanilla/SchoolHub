<?php
/**
 * The Registrar's signature block at the foot of a form the registrar signs (docs/15): the school Registrar's name from System Settings, printed on
 * the signature line, with the signature image above it when one is uploaded. Without a name it is the blank line it used to be.
 *
 * @var string $label what is printed under the name, "Registrar" by default
 */
use App\Models\SystemSetting;
use App\Services\SettingsService;

$label = $label ?? 'Registrar';
$name = trim((string) SystemSetting::get('registrar_name', ''));
$signature = SettingsService::registrarSignatureUri();
?>
<div class="text-center">
    <?php if ($signature !== null): ?>
        <img src="<?= e($signature) ?>" alt="Signature of <?= e($name !== '' ? $name : 'the Registrar') ?>" class="mx-auto h-10 max-w-[10rem] object-contain">
    <?php else: ?>
        <div class="h-10"></div>
    <?php endif; ?>
    <p class="border-t border-slate-400 pt-1 font-semibold text-slate-800"><?php if ($name !== ''): ?><?= e($name) ?><?php else: ?>&nbsp;<?php endif; ?></p>
    <p class="text-xs text-slate-600"><?= e($label) ?></p>
</div>