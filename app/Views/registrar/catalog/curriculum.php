<?php
/**
 * One curriculum laid out by year and term, like the school's printed copy.
 *
 * @var array $curriculum
 * @var array $terms       Curriculum::grid()
 * @var float $totalUnits
 * @var array $deleteBlockers  what stops this curriculum being deleted
 */
$number = static fn ($value): string => rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');
?>
<div class="space-y-6">
    <a href="<?= e(url('/registrar/curricula')) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; Curricula</a>

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow"><?= e($curriculum['program_code']) ?> · SY <?= e($curriculum['effective_school_year']) ?></p>
            <h2 class="mt-1 text-2xl"><?= e($curriculum['name']) ?></h2>
            <p class="mt-1 text-sm text-slate-600">
                <?= e($curriculum['code']) ?> · <?= e($number($totalUnits)) ?> units
                <?= (int) $curriculum['is_active'] === 1 ? '' : ' · <strong>Inactive</strong>' ?>
            </p>
            <?php if ($curriculum['notes']): ?>
                <p class="mt-1 text-sm text-brand-gray"><?= e($curriculum['notes']) ?></p>
            <?php endif; ?>
        </div>
        <div class="flex flex-wrap gap-3">
            <a href="<?= e(url('/registrar/curricula/' . $curriculum['id'] . '/edit')) ?>" class="btn-secondary">Edit details</a>
            <a href="<?= e(url('/registrar/subjects/create?curriculum_id=' . $curriculum['id'])) ?>" class="btn-primary">Add subject</a>
        </div>
    </div>

    <?php if ($terms === []): ?>
        <section class="card">
            <?= component('empty-state', ['title' => 'No subjects yet', 'message' => 'Add this curriculum\'s subjects with their year level and term.']) ?>
        </section>
    <?php endif; ?>

    <?php $currentYear = null; ?>
    <?php foreach ($terms as $term): ?>
        <?php if ($term['year'] !== $currentYear): $currentYear = $term['year']; ?>
            <h3 class="rounded-lg bg-brand-navy px-4 py-2 text-center font-display text-sm font-bold uppercase tracking-wider text-white"><?= e($term['year_name']) ?></h3>
        <?php endif; ?>

        <section class="card" aria-labelledby="term-<?= e($term['year'] . '-' . $term['term']) ?>">
            <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                <h4 id="term-<?= e($term['year'] . '-' . $term['term']) ?>" class="font-display font-semibold text-brand-navy"><?= e($term['term_name']) ?></h4>
                <p class="text-sm text-slate-600"><span class="font-semibold text-brand-navy"><?= e($number($term['units'])) ?></span> units</p>
            </div>
            <div class="table-scroll">
                <table class="w-full min-w-[40rem] table-fixed divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th scope="col" class="w-36 px-4 py-2 font-display font-semibold text-brand-navy">Code</th>
                            <th scope="col" class="w-[40%] px-4 py-2 font-display font-semibold text-brand-navy">Subject title</th>
                            <th scope="col" class="w-20 px-4 py-2 font-display font-semibold text-brand-navy">Units</th>
                            <th scope="col" class="px-4 py-2 font-display font-semibold text-brand-navy">Prerequisites</th>
                            <th scope="col" class="w-16 px-4 py-2"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($term['subjects'] as $subject): ?>
                            <?php $inactive = (int) $subject['is_active'] !== 1; ?>
                            <tr class="<?= $inactive ? 'text-brand-gray' : '' ?>">
                                <td class="whitespace-nowrap px-4 py-2 font-display font-bold <?= $inactive ? '' : 'text-brand-navy' ?>"><?= e($subject['code']) ?></td>
                                <td class="px-4 py-2">
                                    <?= e($subject['title']) ?>
                                    <?php if ($inactive): ?><span class="badge ml-1 bg-slate-100 text-slate-600">Inactive</span><?php endif; ?>
                                    <?php if ((float) $subject['lab_units'] > 0): ?>
                                        <span class="ml-1 text-xs text-brand-gray">(<?= e($number((float) $subject['units'] - (float) $subject['lab_units'])) ?> lec · <?= e($number($subject['lab_units'])) ?> lab)</span>
                                    <?php endif; ?>
                                    <?php if ($subject['description']): ?>
                                        <p class="mt-0.5 text-xs text-brand-gray"><?= e($subject['description']) ?></p>
                                    <?php endif; ?>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2"><?= e($number($subject['units'])) ?></td>
                                <td class="px-4 py-2">
                                    <?php
                                    $requires = array_filter([
                                        $subject['prerequisite_codes'],
                                        $subject['min_progress_percent'] !== null ? $subject['min_progress_percent'] . '% of the curriculum passed' : null,
                                    ]);
                                    ?>
                                    <?= $requires === [] ? '<span class="text-brand-gray">—</span>' : e(implode('; ', $requires)) ?>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2 text-right">
                                    <a href="<?= e(url('/registrar/subjects/' . $subject['id'] . '/edit')) ?>" class="font-semibold text-brand-600 hover:underline">Edit<span class="sr-only"> <?= e($subject['code']) ?></span></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endforeach; ?>

    <?php $confirmError = error_for('confirm_code'); $reasonError = error_for('reason'); $subjectCount = array_sum(array_map(static fn ($t) => count($t['subjects']), $terms)); ?>
    <section class="card p-5 sm:p-6" aria-labelledby="delete-heading">
        <h3 id="delete-heading" class="text-base">Delete this curriculum</h3>
        <?php if ($deleteBlockers !== []): ?>
            <p class="mt-1 text-sm text-slate-600">It cannot be deleted while:</p>
            <ul class="mt-1 list-disc space-y-1 pl-5 text-sm text-slate-600">
                <?php foreach ($deleteBlockers as $blocker): ?><li><?= e($blocker) ?></li><?php endforeach; ?>
            </ul>
            <p class="mt-2 text-sm text-slate-600">Use <strong>Edit details</strong> to make it inactive instead. Its records stay.</p>
        <?php else: ?>
            <p class="mt-1 text-sm text-slate-600">No student follows it and none of its subjects is in use, so it can be removed for good<?= $subjectCount > 0 ? ', <strong>together with its ' . e((string) $subjectCount) . ' subject' . ($subjectCount === 1 ? '' : 's') . '</strong>' : '' ?>. This cannot be undone; the audit log keeps a record.</p>
            <form method="POST" action="<?= e(url('/registrar/curricula/' . $curriculum['id'] . '/delete')) ?>" class="mt-3 space-y-3" novalidate
                  data-confirm="<?= e($curriculum['code'] . ' is removed for good' . ($subjectCount > 0 ? ', with its ' . $subjectCount . ' subject' . ($subjectCount === 1 ? '' : 's') : '') . '. This cannot be undone.') ?>" data-confirm-title="Delete this curriculum?" data-confirm-tone="danger" data-confirm-label="Delete curriculum"
                  data-confirm-requires="#delete-reason" data-confirm-requires-message="Write the reason for deleting the curriculum first. It is recorded in the audit log.">
                <?= csrf_field() ?>
                <div>
                    <label for="delete-reason" class="field-label">Reason <span class="text-danger-text" aria-hidden="true">*</span></label>
                    <input id="delete-reason" name="reason" class="field-input <?= $reasonError ? 'field-input-error' : '' ?>" maxlength="255" placeholder="e.g. Created by mistake">
                    <?php if ($reasonError): ?><p class="field-error"><span><?= e($reasonError) ?></span></p><?php endif; ?>
                </div>
                <div>
                    <label for="delete-confirm" class="field-label">Type <strong><?= e($curriculum['code']) ?></strong> to confirm</label>
                    <input id="delete-confirm" name="confirm_code" class="field-input <?= $confirmError ? 'field-input-error' : '' ?>" autocomplete="off">
                    <?php if ($confirmError): ?><p class="field-error"><span><?= e($confirmError) ?></span></p><?php endif; ?>
                </div>
                <button type="submit" class="btn-danger">Delete curriculum</button>
            </form>
        <?php endif; ?>
    </section>
</div>
