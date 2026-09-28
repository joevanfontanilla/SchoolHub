<?php
/**
 * Manage the school academic calendar (docs/09). Add, edit, and remove entries for one
 * academic year. Registrar and Super Admin (permission calendar.manage).
 *
 * @var array<int,string> $years
 * @var string            $year
 * @var array             $groups
 * @var array<string,mixed>|null $editing
 * @var array<int,string> $categories
 */
$isEdit = $editing !== null;
$v = static fn (string $k, string $default = '') => (string) old($k, $isEdit ? (string) ($editing[$k] ?? '') : $default);
$formAction = $isEdit ? '/manage/calendar/' . (int) $editing['id'] : '/manage/calendar';
?>
<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="eyebrow">Academic calendar</p>
            <h2 class="mt-1 text-2xl">Manage the calendar</h2>
            <p class="mt-1 text-sm text-slate-600">Semester dates, examination periods, and no-class days — shown on the public site and in the portal.</p>
        </div>
        <a href="<?= e(url('/calendar')) ?>" class="btn-secondary"><?= icon('calendar', 'h-5 w-5') ?> View public calendar</a>
    </div>

    <?php if (count($years) > 1): ?>
        <form method="GET" action="<?= e(url('/manage/calendar')) ?>" class="flex flex-wrap items-end gap-3" data-auto-submit>
            <div class="min-w-[13rem]">
                <label for="pick-ay" class="field-label">Showing academic year</label>
                <select id="pick-ay" name="ay" class="field-input">
                    <?php foreach ($years as $y): ?>
                        <option value="<?= e($y) ?>" <?= $y === $year ? 'selected' : '' ?>>A.Y. <?= e($y) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <noscript><button type="submit" class="btn-secondary">Show</button></noscript>
        </form>
    <?php endif; ?>

    <!-- Add / edit form -->
    <form method="POST" action="<?= e(url($formAction)) ?>" class="card space-y-4 p-5 sm:p-6" novalidate>
        <?= csrf_field() ?>
        <h3 class="text-base"><?= $isEdit ? 'Edit entry' : 'Add an entry' ?></h3>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="academic_year" class="field-label">Academic year</label>
                <input id="academic_year" name="academic_year" class="field-input <?= error_for('academic_year') ? 'field-input-error' : '' ?>"
                       value="<?= e($v('academic_year', $year)) ?>" placeholder="2026-2027" autocomplete="off" required>
                <?php if (error_for('academic_year')): ?><p class="field-error" role="alert"><span><?= e(error_for('academic_year')) ?></span></p><?php endif; ?>
            </div>
            <div>
                <label for="category" class="field-label">Section</label>
                <input id="category" name="category" list="category-options" class="field-input <?= error_for('category') ? 'field-input-error' : '' ?>"
                       value="<?= e($v('category')) ?>" placeholder="First Semester" autocomplete="off" required>
                <datalist id="category-options">
                    <?php foreach ($categories as $c): ?><option value="<?= e($c) ?>"></option><?php endforeach; ?>
                </datalist>
                <?php if (error_for('category')): ?><p class="field-error" role="alert"><span><?= e(error_for('category')) ?></span></p><?php endif; ?>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="date_label" class="field-label">Date (as shown)</label>
                <input id="date_label" name="date_label" class="field-input <?= error_for('date_label') ? 'field-input-error' : '' ?>"
                       value="<?= e($v('date_label')) ?>" placeholder="July 15-18, 2026" autocomplete="off" required>
                <p class="mt-1 text-xs text-brand-gray">Free text, exactly as it should appear (a single day or a range).</p>
                <?php if (error_for('date_label')): ?><p class="field-error" role="alert"><span><?= e(error_for('date_label')) ?></span></p><?php endif; ?>
            </div>
            <div>
                <label for="sort_date" class="field-label">Sort date <span class="font-normal text-brand-gray">(optional)</span></label>
                <input id="sort_date" name="sort_date" type="date" class="field-input <?= error_for('sort_date') ? 'field-input-error' : '' ?>"
                       value="<?= e($v('sort_date')) ?>">
                <p class="mt-1 text-xs text-brand-gray">The first/main date, used only to order entries.</p>
                <?php if (error_for('sort_date')): ?><p class="field-error" role="alert"><span><?= e(error_for('sort_date')) ?></span></p><?php endif; ?>
            </div>
        </div>

        <div>
            <label for="activity" class="field-label">Activity</label>
            <input id="activity" name="activity" class="field-input <?= error_for('activity') ? 'field-input-error' : '' ?>"
                   value="<?= e($v('activity')) ?>" placeholder="Preliminary Examination Period" autocomplete="off" required>
            <?php if (error_for('activity')): ?><p class="field-error" role="alert"><span><?= e(error_for('activity')) ?></span></p><?php endif; ?>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="btn-primary"><?= $isEdit ? 'Save changes' : 'Add entry' ?></button>
            <?php if ($isEdit): ?>
                <a href="<?= e(url('/manage/calendar?ay=' . rawurlencode($year))) ?>" class="btn-secondary">Cancel</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Existing entries -->
    <?php if ($groups === []): ?>
        <?= component('empty-state', ['title' => 'No entries for A.Y. ' . $year, 'message' => 'Add the first calendar entry using the form above.']) ?>
    <?php else: ?>
        <?php foreach ($groups as $gi => $group): ?>
            <section class="card overflow-hidden" aria-labelledby="mg-<?= (int) $gi ?>">
                <h3 id="mg-<?= (int) $gi ?>" class="border-b border-slate-200 bg-brand-50 px-5 py-3 font-display text-base font-bold uppercase tracking-wide text-brand-navy"><?= e($group['category']) ?></h3>
                <ul class="divide-y divide-slate-100">
                    <?php foreach ($group['rows'] as $row): ?>
                        <?php $rid = (int) $row['id']; ?>
                        <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-brand-navy"><?= e($row['date_label']) ?></p>
                                <p class="text-sm text-slate-600"><?= e($row['activity']) ?></p>
                            </div>
                            <div class="flex items-center gap-2">
                                <a href="<?= e(url('/manage/calendar?ay=' . rawurlencode($year) . '&edit=' . $rid)) ?>" class="btn-secondary px-3 py-1.5 text-sm"><?= icon('pencil', 'h-4 w-4') ?> Edit</a>
                                <form method="POST" action="<?= e(url('/manage/calendar/' . $rid . '/delete')) ?>"
                                      data-confirm="This calendar entry will be removed from the public site and the portal." data-confirm-title="Remove this entry?" data-confirm-tone="danger" data-confirm-label="Remove">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="ay" value="<?= e($year) ?>">
                                    <button type="submit" class="btn-secondary px-3 py-1.5 text-sm text-danger-text">Remove</button>
                                </form>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
