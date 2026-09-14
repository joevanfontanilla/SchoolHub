<?php
/**
 * @var array $sections
 * @var array $filters
 * @var array $terms
 * @var array $programs
 */
use App\Models\Term;
?>
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">Academic catalog</p>
            <h2 class="mt-1 text-2xl">Sections</h2>
            <p class="mt-1 text-sm text-slate-600">Each section belongs to one program, year level, and term, and has a seat limit.</p>
        </div>
        <a href="<?= e(url('/registrar/sections/create')) ?>" class="btn-primary">New section</a>
    </div>

    <form method="GET" action="<?= e(url('/registrar/sections')) ?>" class="card grid gap-4 p-4 sm:grid-cols-2" data-auto-submit>
        <div>
            <label for="section-term" class="field-label">Term</label>
            <select id="section-term" name="term_id" class="field-input">
                <option value="">All terms</option>
                <?php foreach ($terms as $term): ?>
                    <option value="<?= e($term['id']) ?>" <?= (string) $filters['term_id'] === (string) $term['id'] ? 'selected' : '' ?>><?= e(Term::label($term)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="section-program" class="field-label">Program</label>
            <select id="section-program" name="program_id" class="field-input">
                <option value="">All programs</option>
                <?php foreach ($programs as $program): ?>
                    <option value="<?= e($program['id']) ?>" <?= (string) $filters['program_id'] === (string) $program['id'] ? 'selected' : '' ?>><?= e($program['code']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <section class="card">
        <?php if ($sections === []): ?>
            <?= component('empty-state', ['title' => 'No sections yet', 'message' => 'Create sections before confirming enrollments.']) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Section</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Program / year</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Term</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Seats</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Adviser</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Status</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($sections as $section): ?>
                            <?php
                            $pct = (int) $section['capacity'] > 0 ? min(100, round((int) $section['enrolled_count'] / (int) $section['capacity'] * 100)) : 0;
                            $full = (int) $section['enrolled_count'] >= (int) $section['capacity'];
                            ?>
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3 font-display font-bold text-brand-navy"><?= e($section['code']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-700"><?= e($section['program_code']) ?> · <?= e($section['year_level_name']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-700"><?= e($section['term_name']) ?>, <?= e($section['academic_year_code']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <span class="<?= $full ? 'font-semibold text-danger-text' : 'text-slate-700' ?>">
                                        <?= e($section['enrolled_count']) ?> / <?= e($section['capacity']) ?><?= $full ? ' · Full' : '' ?>
                                    </span>
                                    <span class="mt-1 block h-1.5 w-24 overflow-hidden rounded-full bg-slate-100" aria-hidden="true">
                                        <span class="block h-full rounded-full <?= $full ? 'bg-brand-alert' : 'bg-brand-600' ?>" style="width: <?= $pct ?>%"></span>
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-700"><?= e($section['adviser_name'] ?: '—') ?></td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <span class="badge <?= (int) $section['is_active'] === 1 ? 'bg-success-bg text-success-text' : 'bg-slate-100 text-slate-600' ?>">
                                        <?= (int) $section['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <a href="<?= e(url('/registrar/sections/' . $section['id'] . '/classes')) ?>" class="mr-3 font-semibold text-brand-600 hover:underline">Classes<span class="sr-only"> of <?= e($section['code']) ?></span></a>
                                    <a href="<?= e(url('/registrar/sections/' . $section['id'] . '/edit')) ?>" class="font-semibold text-brand-600 hover:underline">Edit<span class="sr-only"> <?= e($section['code']) ?></span></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
