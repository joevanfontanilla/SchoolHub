<?php
/**
 * The subjects a student takes this term (docs/09). Only the program's
 * curriculum subjects for the year level and term applied for are listed.
 *
 * @var array  $application
 * @var array  $options    SubjectSelectionService::options()
 * @var array  $chosenIds
 * @var float  $unitLimit
 * @var ?array $curriculum
 * @var bool   $canEdit
 */
$id = (int) $application['id'];
$number = static fn ($value): string => rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');
$failed = error_for('subjects') !== null;
$checked = $failed ? array_map('intval', (array) old('subjects', [])) : $chosenIds;
$chosenUnits = array_sum(array_map(static fn ($o) => in_array((int) $o['id'], $checked, true) ? (float) $o['units'] : 0.0, $options));
$chosenRows = array_values(array_filter($options, static fn ($o) => in_array((int) $o['id'], $chosenIds, true)));
$errors = errors()['subjects'] ?? [];
?>
<section id="subjects" class="card" aria-labelledby="subjects-heading">
    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-100 px-5 py-4">
        <div>
            <h2 id="subjects-heading" class="flex items-center gap-2 text-base"><span class="text-brand-600"><?= icon('book-open', 'h-5 w-5') ?></span> Subjects this term</h2>
            <p class="mt-0.5 text-xs text-brand-gray">
                <?= $curriculum !== null ? e($curriculum['code']) . ' · ' : '' ?><?= e($application['program_code'] ?? '') ?> · <?= e($application['year_level_name'] ?? '') ?> · <?= e($application['term_name'] ?? '') ?>
            </p>
        </div>
        <p class="text-sm text-slate-700" aria-live="polite">
            <span class="font-display text-lg font-bold text-brand-navy" data-units-total><?= e($number($chosenUnits)) ?></span>
            of <?= e($number($unitLimit)) ?> units
        </p>
    </div>

    <?php if ($options === []): ?>
        <?= component('empty-state', [
            'title'   => 'No subjects are offered for this program, year level, and term yet',
            'message' => 'The registrar\'s office sets up each program\'s curriculum. Contact them if this looks wrong.',
        ]) ?>
    <?php elseif ($canEdit): ?>
        <form method="POST" action="<?= e(url("/student/enrollment/{$id}/subjects")) ?>" class="px-5 py-4" data-units-form novalidate>
            <?= csrf_field() ?>
            <p class="text-sm text-slate-600">
                Your regular load is already checked. Untick a subject you will not take this term.
                Subjects with a prerequisite you have not passed yet are shown but cannot be chosen.
            </p>
            <?php if ($errors !== []): ?>
                <ul class="mt-3 space-y-1 rounded-lg border border-danger-line bg-danger-bg px-4 py-3 text-sm text-danger-text" role="alert">
                    <?php foreach ($errors as $message): ?><li><?= e($message) ?></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <fieldset class="mt-4">
                <legend class="sr-only">Subjects</legend>
                <ul class="divide-y divide-brand-50 overflow-hidden rounded-xl border border-brand-100">
                    <?php foreach ($options as $option): ?>
                        <?php $selectable = (bool) $option['selectable']; ?>
                        <li>
                            <label class="flex items-start gap-3 px-4 py-3 <?= $selectable ? 'cursor-pointer hover:bg-brand-50/60' : 'cursor-not-allowed bg-slate-50' ?>">
                                <input type="checkbox" name="subjects[]" value="<?= e($option['id']) ?>" data-units="<?= e($option['units']) ?>"
                                       class="mt-1 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-600"
                                       <?= $selectable && in_array((int) $option['id'], $checked, true) ? 'checked' : '' ?>
                                       <?= $selectable ? '' : 'disabled aria-describedby="locked-' . e($option['id']) . '"' ?>>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm <?= $selectable ? 'text-slate-800' : 'text-slate-500' ?>">
                                        <span class="font-display font-bold <?= $selectable ? 'text-brand-navy' : '' ?>"><?= e($option['code']) ?></span>
                                        <?= e($option['title']) ?>
                                    </span>
                                    <?php if ($option['prerequisite_codes']): ?>
                                        <span class="block text-xs text-brand-gray">Prerequisite: <?= e($option['prerequisite_codes']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!$selectable): ?>
                                        <span id="locked-<?= e($option['id']) ?>" class="mt-0.5 flex items-center gap-1 text-xs font-medium text-warning-text"><?= icon('lock', 'h-3.5 w-3.5') ?> <?= e($option['locked_reason']) ?></span>
                                    <?php endif; ?>
                                </span>
                                <span class="whitespace-nowrap text-sm font-semibold text-slate-700"><?= e($number($option['units'])) ?> u</span>
                            </label>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </fieldset>
            <div class="mt-4 flex flex-wrap items-center gap-3">
                <button type="submit" class="btn-secondary">Save subjects</button>
                <p class="text-xs text-brand-gray">The registrar reviews your subjects with your application. Tuition is assessed on the subjects approved.</p>
            </div>
        </form>
    <?php else: ?>
        <?php if ($chosenRows === [] && $chosenIds === []): ?>
            <p class="px-5 py-4 text-sm text-brand-gray">No subjects were chosen.</p>
        <?php else: ?>
            <ul class="divide-y divide-brand-50">
                <?php foreach ($chosenIds !== [] ? \App\Services\SubjectSelectionService::chosen($id) : [] as $subject): ?>
                    <li class="flex items-center justify-between gap-3 px-5 py-2.5 text-sm">
                        <span><span class="font-display font-bold text-brand-navy"><?= e($subject['code']) ?></span> <?= e($subject['title']) ?></span>
                        <span class="whitespace-nowrap font-semibold text-slate-700"><?= e($number($subject['units'])) ?> u</span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="border-t border-brand-100 px-5 py-3 text-xs text-brand-gray">Subjects can no longer be changed at this stage.</p>
        <?php endif; ?>
    <?php endif; ?>
</section>
