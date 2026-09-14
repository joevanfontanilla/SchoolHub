<?php
/**
 * @var array    $subjects
 * @var array    $programs
 * @var array    $curricula
 * @var int|null $programId
 * @var int|null $curriculumId
 */
?>
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">Academic catalog</p>
            <h2 class="mt-1 text-2xl">Subjects</h2>
            <p class="mt-1 text-sm text-slate-600">Every curriculum's subjects, with units and prerequisites. General subjects belong to no curriculum.</p>
        </div>
        <a href="<?= e(url('/registrar/subjects/create')) ?>" class="btn-primary">New subject</a>
    </div>

    <form method="GET" action="<?= e(url('/registrar/subjects')) ?>" class="card flex flex-wrap items-end gap-4 p-4" data-auto-submit>
        <div class="min-w-[12rem]">
            <label for="subject-program" class="field-label">Program</label>
            <select id="subject-program" name="program_id" class="field-input">
                <option value="">All programs</option>
                <?php foreach ($programs as $program): ?>
                    <option value="<?= e($program['id']) ?>" <?= (int) $programId === (int) $program['id'] ? 'selected' : '' ?>><?= e($program['code']) ?> (and general subjects)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="min-w-[12rem]">
            <label for="subject-curriculum" class="field-label">Curriculum</label>
            <select id="subject-curriculum" name="curriculum_id" class="field-input">
                <option value="">Any curriculum</option>
                <?php foreach ($curricula as $option): ?>
                    <option value="<?= e($option['id']) ?>" <?= (int) $curriculumId === (int) $option['id'] ? 'selected' : '' ?>><?= e($option['code']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <noscript><button type="submit" class="btn-secondary">Filter</button></noscript>
    </form>

    <section class="card">
        <?php if ($subjects === []): ?>
            <?= component('empty-state', ['title' => 'No subjects yet', 'message' => 'Add the subjects of each program\'s curriculum.']) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Code</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Title</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Units</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Curriculum / year</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Prerequisites</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Status</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($subjects as $subject): ?>
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3 font-display font-bold text-brand-navy"><?= e($subject['code']) ?></td>
                                <td class="px-4 py-3 text-slate-700"><?= e($subject['title']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-700"><?= e(rtrim(rtrim((string) $subject['units'], '0'), '.')) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-700">
                                    <?= $subject['curriculum_code'] ? e($subject['curriculum_code']) : 'General' ?>
                                    <?= $subject['year_level_name'] ? ' · ' . e($subject['year_level_name']) : '' ?>
                                    <?= $subject['term_sequence'] ? ' · ' . e(['1' => '1st sem', '2' => '2nd sem', '3' => 'Summer'][(string) $subject['term_sequence']] ?? '') : '' ?>
                                </td>
                                <td class="px-4 py-3 text-slate-700"><?= e($subject['prerequisite_codes'] ?: '—') ?></td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <span class="badge <?= (int) $subject['is_active'] === 1 ? 'bg-success-bg text-success-text' : 'bg-slate-100 text-slate-600' ?>">
                                        <?= (int) $subject['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <a href="<?= e(url('/registrar/subjects/' . $subject['id'] . '/edit')) ?>" class="font-semibold text-brand-600 hover:underline">Edit<span class="sr-only"> <?= e($subject['code']) ?></span></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
