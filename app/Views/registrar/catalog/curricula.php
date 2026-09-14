<?php
/** @var array $curricula */
$number = static fn ($value): string => rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');
?>
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">Academic catalog</p>
            <h2 class="mt-1 text-2xl">Curricula</h2>
            <p class="mt-1 max-w-2xl text-sm text-slate-600">
                Each program's approved subjects by year and term. A student keeps the curriculum they were admitted under;
                new students get the program's active curriculum with the latest effective school year.
            </p>
        </div>
        <a href="<?= e(url('/registrar/curricula/create')) ?>" class="btn-primary">New curriculum</a>
    </div>

    <section class="card">
        <?php if ($curricula === []): ?>
            <?= component('empty-state', ['title' => 'No curricula yet', 'message' => 'Import them with: php database/import_curricula.php']) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Curriculum</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Program</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Effective</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Subjects</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Units</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Students</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Status</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($curricula as $curriculum): ?>
                            <tr>
                                <td class="px-4 py-3">
                                    <a href="<?= e(url('/registrar/curricula/' . $curriculum['id'])) ?>" class="font-display font-bold text-brand-600 hover:underline"><?= e($curriculum['code']) ?></a>
                                    <?php if ($curriculum['notes']): ?>
                                        <p class="mt-0.5 text-xs text-brand-gray"><?= e($curriculum['notes']) ?></p>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-slate-700"><?= e($curriculum['program_code']) ?> · <?= e($curriculum['program_name']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-700">SY <?= e($curriculum['effective_school_year']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-700"><?= e($curriculum['subject_count']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-700"><?= e($number($curriculum['total_units'])) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-700"><?= e($curriculum['student_count']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <span class="badge <?= (int) $curriculum['is_active'] === 1 ? 'bg-success-bg text-success-text' : 'bg-slate-100 text-slate-600' ?>">
                                        <?= (int) $curriculum['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <a href="<?= e(url('/registrar/curricula/' . $curriculum['id'] . '/edit')) ?>" class="font-semibold text-brand-600 hover:underline">Edit<span class="sr-only"> <?= e($curriculum['code']) ?></span></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
