<?php
/**
 * Student records list (docs/11).
 *
 * @var string $q
 * @var string $status
 * @var array  $result
 */
?>
<div class="space-y-6">
    <div>
        <p class="eyebrow">Registrar</p>
        <h2 class="mt-1 text-2xl">Students</h2>
        <p class="mt-1 text-sm text-slate-600">Everyone with a student account, from first application to enrollment.</p>
    </div>

    <form method="GET" action="<?= e(url('/registrar/students')) ?>" class="card grid gap-4 p-4 sm:grid-cols-3" data-auto-submit>
        <div class="sm:col-span-2">
            <label for="student-q" class="field-label">Search</label>
            <div class="flex gap-2">
                <input type="search" id="student-q" name="q" value="<?= e($q) ?>" placeholder="Name, email, or student number" class="field-input">
                <button type="submit" class="btn-secondary shrink-0">Search</button>
            </div>
        </div>
        <div>
            <label for="student-status" class="field-label">Status</label>
            <select id="student-status" name="status" class="field-input">
                <?php foreach (['' => 'All students', 'Applicant' => 'Applicants', 'Enrolled' => 'Enrolled', 'Inactive' => 'Inactive', 'Graduated' => 'Graduated'] as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <section class="card">
        <?php if ($result['rows'] === []): ?>
            <?= component('empty-state', ['title' => 'No students match', 'message' => 'Try a different name or status.']) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Name</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Student number</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Program</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Status</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Applications</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($result['rows'] as $row): ?>
                            <tr class="hover:bg-brand-50/50">
                                <td class="px-4 py-3">
                                    <a href="<?= e(url('/registrar/students/' . $row['id'])) ?>" class="font-semibold text-brand-600 hover:underline">
                                        <?= e($row['last_name'] . ', ' . $row['first_name']) ?>
                                    </a>
                                    <p class="text-xs text-brand-gray"><?= e($row['email']) ?></p>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-700"><?= e($row['student_number'] ?? '—') ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-700">
                                    <?= $row['program_code'] ? e($row['program_code'] . ' · ' . $row['year_level_name']) : '—' ?>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <span class="badge <?= $row['status'] === 'Enrolled' ? 'bg-success-bg text-success-text' : 'bg-slate-100 text-slate-700' ?>"><?= e($row['status']) ?></span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-700"><?= e($row['application_count']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?= partial('pagination', [
            'page' => $result['page'], 'pages' => $result['pages'], 'total' => $result['total'],
            'path' => '/registrar/students', 'query' => ['q' => $q, 'status' => $status], 'noun' => 'student',
        ]) ?>
    </section>
</div>
