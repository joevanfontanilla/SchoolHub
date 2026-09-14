<?php
/** @var array $programs */
?>
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">Academic catalog</p>
            <h2 class="mt-1 text-2xl">Programs</h2>
            <p class="mt-1 text-sm text-slate-600">Programs are deactivated rather than deleted, so past records keep their meaning.</p>
        </div>
        <a href="<?= e(url('/registrar/programs/create')) ?>" class="btn-primary">New program</a>
    </div>

    <section class="card">
        <?php if ($programs === []): ?>
            <?= component('empty-state', ['title' => 'No programs yet']) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Code</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Name</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Duration</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Subjects</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Enrolled</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Status</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($programs as $program): ?>
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3 font-display font-bold text-brand-navy"><?= e($program['code']) ?></td>
                                <td class="px-4 py-3 text-slate-700"><?= e($program['name']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-700"><?= e($program['years_duration']) ?> years</td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-700"><?= e($program['subject_count']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-700"><?= e($program['enrolled_count']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <span class="badge <?= (int) $program['is_active'] === 1 ? 'bg-success-bg text-success-text' : 'bg-slate-100 text-slate-600' ?>">
                                        <?= (int) $program['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <a href="<?= e(url('/registrar/programs/' . $program['id'] . '/edit')) ?>" class="font-semibold text-brand-600 hover:underline">Edit<span class="sr-only"> <?= e($program['code']) ?></span></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
