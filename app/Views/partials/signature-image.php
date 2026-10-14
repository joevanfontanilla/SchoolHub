<?php
/**
 * A person's e-signature above their printed name (docs/08), embedded in the printout. Prints nothing when
 * they have none, leaving the blank line as before.
 *
 * @var ?int    $userId
 * @var ?string $name   for the image's alt text
 */
use App\Services\SignatureService;

$uri = SignatureService::dataUri($userId ?? null);
?>
<?php if ($uri !== null): ?>
    <img src="<?= e($uri) ?>" alt="Signature of <?= e($name ?? 'the signer') ?>" class="mx-auto -mb-1 h-14 max-w-[12rem] object-contain">
<?php endif; ?>