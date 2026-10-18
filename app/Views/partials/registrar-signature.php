<?php
/**
 * The Registrar's signature block at the foot of a form the registrar signs (docs/15): the name of the Registrar who prints it (or, for a student's own copy,
 * the one who enrolled them, or the school Registrar in System Settings), with their signature image above when there is one. Without a name it is the blank line it was.
 *
 * @var string $label        what is printed under the name, "Registrar" by default
 * @var ?int   $enrollmentId the enrollment the form is about, when it is about one (a student's own copy then names the registrar who enrolled them)
 */
use App\Services\RegistrarSignerService;

$label = $label ?? 'Registrar';
$signer = RegistrarSignerService::resolve(auth_user(), $enrollmentId ?? null);
$name = $signer['name'];
$signature = $signer['signatureUri'];
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