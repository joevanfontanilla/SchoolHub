<?php
/**
 * The result of a scan (docs/29). A valid ID shows the minimum needed to match the card to the person; an
 * unknown, disabled, or expired ID shows the same "Not valid" page with nothing else.
 *
 * @var ?array  $result EIdService::verify(), or null when the ID is not valid
 * @var ?string $code
 * @var array   $school
 */
$valid = $result !== null;
?>
<section class="bg-brand-pale" aria-labelledby="result-heading">
    <div class="mx-auto max-w-md px-4 py-12 sm:px-6">
        <div class="card overflow-hidden">
            <div class="px-6 py-5 text-center <?= $valid ? 'bg-success-bg text-success-text' : 'bg-danger-bg text-danger-text' ?>" role="status">
                <p class="flex items-center justify-center gap-2 font-display text-2xl font-extrabold" id="result-heading">
                    <?= icon($valid ? 'shield' : 'alert', 'h-7 w-7') ?> <?= $valid ? 'Valid ID' : 'Not valid' ?>
                </p>
                <p class="mt-1 text-sm"><?= $valid ? 'This is a current ' . e($school['school_name'] ?? 'school') . ' ID.' : 'This ID could not be confirmed. Do not rely on this card.' ?></p>
            </div>

            <?php if ($valid): ?>
                <div class="p-6 text-center">
                    <div class="mx-auto h-40 w-32 overflow-hidden rounded-lg bg-brand-50 ring-1 ring-slate-300">
                        <?php if ($result['hasPhoto']): ?>
                            <img src="<?= e(url('/verify/' . $code . '/photo')) ?>" alt="Photo of <?= e($result['name']) ?>" class="h-full w-full object-cover">
                        <?php else: ?>
                            <div class="flex h-full items-center justify-center font-display text-5xl font-bold text-brand-navy" aria-hidden="true"><?= e(strtoupper(mb_substr($result['name'], 0, 1))) ?></div>
                        <?php endif; ?>
                    </div>
                    <p class="mt-4 font-display text-xl font-extrabold text-brand-navy"><?= e($result['name']) ?></p>
                    <dl class="mt-4 space-y-2 text-sm">
                        <div class="flex justify-between gap-4 border-b border-brand-50 pb-2"><dt class="text-slate-500">ID number</dt><dd class="font-semibold tabular-nums"><?= e($result['idNumber']) ?></dd></div>
                        <div class="flex justify-between gap-4 border-b border-brand-50 pb-2"><dt class="text-slate-500"><?= $result['kind'] === 'student' ? 'Program' : 'Position' ?></dt><dd class="text-right font-semibold"><?= e($result['kind'] === 'student' ? trim(($result['program'] ?? '') . ($result['yearLevel'] ? ' · ' . $result['yearLevel'] : '')) : $result['role']) ?></dd></div>
                        <?php if ($result['schoolYear']): ?><div class="flex justify-between gap-4"><dt class="text-slate-500">School year</dt><dd class="font-semibold"><?= e($result['schoolYear']) ?></dd></div><?php endif; ?>
                    </dl>
                    <p class="mt-5 text-xs text-slate-500">Compare the photo with the person holding the card.</p>
                </div>
            <?php else: ?>
                <div class="p-6 text-center text-sm text-slate-600">
                    <p>The code may be wrong, the card may have been replaced, or the ID may have expired.</p>
                    <p class="mt-3">Contact the school office: <?= e($school['school_phone'] ?? '') ?></p>
                </div>
            <?php endif; ?>
        </div>
        <p class="mt-6 text-center text-sm"><a href="<?= e(url('/verify')) ?>" class="font-semibold text-brand-600 hover:underline">Check another ID</a></p>
    </div>
</section>
