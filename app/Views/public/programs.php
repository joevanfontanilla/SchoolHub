<?php
/** Public programs listing (docs/06). */
?>
<div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
    <h1 class="text-2xl font-bold text-brand-navy sm:text-3xl">Programs</h1>
    <p class="mt-2 max-w-2xl text-slate-600">
        Undergraduate programs currently offered. Each runs on a two-semester academic year.
    </p>

    <?php if ($programs === []): ?>
        <div class="card mt-8">
            <?= component('empty-state', ['title' => 'No programs published yet']) ?>
        </div>
    <?php else: ?>
        <div class="mt-8 grid gap-6 md:grid-cols-2">
            <?php foreach ($programs as $program): ?>
                <article class="card p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wider text-brand-600"><?= e($program['code']) ?></p>
                            <h2 class="mt-1.5 text-lg font-semibold text-brand-navy"><?= e($program['name']) ?></h2>
                        </div>
                        <span class="badge bg-slate-100 text-slate-700"><?= e($program['degree_level']) ?></span>
                    </div>
                    <p class="mt-3 text-sm text-slate-600"><?= e($program['description']) ?></p>
                    <p class="mt-4 text-sm text-brand-gray"><?= e($program['years_duration']) ?> years · 2 semesters per year</p>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
