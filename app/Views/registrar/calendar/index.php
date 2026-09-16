<?php
/**
 * The academic calendar: set each semester's dates and enrollment window, and add a year.
 *
 * @var array<int,array<string,mixed>> $terms      all terms with academic_year_code, newest first
 * @var array<int,string>              $semesters  sequence => name
 */
$local = static fn (?string $value) => $value ? date('Y-m-d\TH:i', strtotime($value)) : '';

// Group the terms by academic year for display.
$byYear = [];
foreach ($terms as $term) {
    $byYear[$term['academic_year_code']][] = $term;
}
?>
<div class="mx-auto max-w-4xl space-y-6">
    <div>
        <p class="eyebrow">Academic catalog</p>
        <h2 class="mt-1 text-2xl">Academic Calendar</h2>
        <p class="mt-1 text-sm text-slate-600">
            Set each semester's start and end, and the window when students may apply. Enrollment for a
            semester opens and closes on the dates you set here.
        </p>
    </div>

    <?php foreach ($byYear as $yearCode => $yearTerms): ?>
        <section class="card" aria-label="A.Y. <?= e($yearCode) ?>">
            <h3 class="border-b border-slate-200 px-5 py-4 text-base">Academic Year <?= e($yearCode) ?></h3>
            <?php foreach ($yearTerms as $term): ?>
                <?php
                $id = (int) $term['id'];
                // Errors and old() come from a failed save; apply them only to the term that failed
                // (term_ref), so a mistake on one semester does not overwrite the others' fields.
                $failed = (string) old('term_ref') === (string) $id;
                $err = static fn (string $field) => $failed ? error_for($field) : null;
                $dateVal = static fn (string $field) => $failed ? (string) old($field) : (string) $term[$field];
                $dtVal = static fn (string $field) => $failed ? (string) old($field) : $local($term[$field]);
                ?>
                <form id="term-<?= $id ?>" method="POST" action="<?= e(url('/registrar/calendar/terms/' . $id)) ?>" class="grid gap-4 border-b border-slate-100 px-5 py-5 last:border-b-0 sm:grid-cols-2" novalidate
                      data-confirm="These dates take effect at once. If the enrollment window includes today, enrollment for this semester is open." data-confirm-title="Save <?= e($term['name']) ?> dates?" data-confirm-tone="warning" data-confirm-label="Save dates">
                    <?= csrf_field() ?>
                    <input type="hidden" name="term_ref" value="<?= $id ?>">
                    <p class="sm:col-span-2 font-display text-sm font-bold text-brand-navy"><?= e($term['name']) ?></p>

                    <?php foreach ([
                        ['start_date', 'Semester starts', 'date', $dateVal('start_date')],
                        ['end_date', 'Semester ends', 'date', $dateVal('end_date')],
                        ['enrollment_opens_at', 'Enrollment opens', 'datetime-local', $dtVal('enrollment_opens_at')],
                        ['enrollment_closes_at', 'Enrollment closes', 'datetime-local', $dtVal('enrollment_closes_at')],
                    ] as [$name, $label, $type, $value]): ?>
                        <div>
                            <label for="cal-<?= $id ?>-<?= e($name) ?>" class="field-label"><?= e($label) ?></label>
                            <input id="cal-<?= $id ?>-<?= e($name) ?>" type="<?= $type ?>" name="<?= e($name) ?>" value="<?= e($value) ?>"
                                   class="field-input <?= $err($name) ? 'field-input-error' : '' ?>" <?= $err($name) ? 'aria-invalid="true"' : '' ?>>
                            <?php if ($m = $err($name)): ?><p class="field-error"><span><?= e($m) ?></span></p><?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <div class="sm:col-span-2 flex items-center gap-3">
                        <button type="submit" class="btn-primary">Save dates</button>
                        <span class="text-xs text-brand-gray"><?= App\Models\Term::isOpen($term) ? 'Enrollment is open now.' : 'Enrollment is closed.' ?></span>
                    </div>
                </form>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>

    <details id="add-year" class="card group" <?= errors() !== [] && old('code') !== '' ? 'open' : '' ?>>
        <summary class="flex cursor-pointer items-center justify-between px-5 py-4 font-display text-base font-bold text-brand-navy">
            Add an academic year
            <span class="text-sm font-semibold text-brand-600 group-open:hidden">Show</span>
        </summary>
        <form method="POST" action="<?= e(url('/registrar/calendar/years')) ?>" class="space-y-5 border-t border-slate-200 px-5 py-5" novalidate>
            <?= csrf_field() ?>
            <div class="sm:max-w-xs">
                <?= component('field', ['name' => 'code', 'label' => 'Academic year', 'required' => true, 'value' => old('code'), 'help' => 'For example 2026-2027.']) ?>
            </div>
            <p class="text-sm text-slate-600">Fill in the semesters this year will run. Leave a semester blank to skip it (for example, no summer term).</p>

            <?php foreach ($semesters as $sequence => $name): ?>
                <?php $p = "semesters.{$sequence}."; $val = static fn (string $f) => (string) old("semesters.{$sequence}.{$f}", ''); ?>
                <fieldset class="rounded-xl border border-brand-100 p-4">
                    <legend class="px-1 font-display text-sm font-bold text-brand-navy"><?= e($name) ?></legend>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="f-<?= $sequence ?>-start" class="field-label">Starts</label>
                            <input id="f-<?= $sequence ?>-start" type="date" name="semesters[<?= $sequence ?>][start_date]" value="<?= e($val('start_date')) ?>" class="field-input <?= error_for($p . 'start_date') ? 'field-input-error' : '' ?>">
                            <?php if ($m = error_for($p . 'start_date')): ?><p class="field-error"><span><?= e($m) ?></span></p><?php endif; ?>
                        </div>
                        <div>
                            <label for="f-<?= $sequence ?>-end" class="field-label">Ends</label>
                            <input id="f-<?= $sequence ?>-end" type="date" name="semesters[<?= $sequence ?>][end_date]" value="<?= e($val('end_date')) ?>" class="field-input <?= error_for($p . 'end_date') ? 'field-input-error' : '' ?>">
                            <?php if ($m = error_for($p . 'end_date')): ?><p class="field-error"><span><?= e($m) ?></span></p><?php endif; ?>
                        </div>
                        <div>
                            <label for="f-<?= $sequence ?>-open" class="field-label">Enrollment opens</label>
                            <input id="f-<?= $sequence ?>-open" type="datetime-local" name="semesters[<?= $sequence ?>][enrollment_opens_at]" value="<?= e($val('enrollment_opens_at')) ?>" class="field-input <?= error_for($p . 'enrollment_opens_at') ? 'field-input-error' : '' ?>">
                            <?php if ($m = error_for($p . 'enrollment_opens_at')): ?><p class="field-error"><span><?= e($m) ?></span></p><?php endif; ?>
                        </div>
                        <div>
                            <label for="f-<?= $sequence ?>-close" class="field-label">Enrollment closes</label>
                            <input id="f-<?= $sequence ?>-close" type="datetime-local" name="semesters[<?= $sequence ?>][enrollment_closes_at]" value="<?= e($val('enrollment_closes_at')) ?>" class="field-input <?= error_for($p . 'enrollment_closes_at') ? 'field-input-error' : '' ?>">
                            <?php if ($m = error_for($p . 'enrollment_closes_at')): ?><p class="field-error"><span><?= e($m) ?></span></p><?php endif; ?>
                        </div>
                    </div>
                </fieldset>
            <?php endforeach; ?>

            <button type="submit" class="btn-primary">Add academic year</button>
        </form>
    </details>
</div>
