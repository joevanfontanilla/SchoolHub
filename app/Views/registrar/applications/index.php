<?php
/**
 * Registrar application queue (docs/11).
 *
 * @var array $filters
 * @var array $result  rows, total, page, pages
 * @var array $counts
 * @var array $terms
 * @var array $programs
 */
use App\Models\Term;

$statusOptions = [
    'action'                  => 'Needs action (' . ($counts['submitted'] + $counts['under_review']) . ')',
    'SUBMITTED'               => 'Submitted, not yet opened (' . $counts['submitted'] . ')',
    'UNDER_REVIEW'            => 'Under review (' . $counts['under_review'] . ')',
    'REQUIREMENTS_INCOMPLETE' => 'Returned: documents',
    'CORRECTION_REQUIRED'     => 'Returned: correction',
    'awaiting_confirmation'   => 'Approved, awaiting confirmation (' . $counts['awaiting_confirmation'] . ')',
    'enrolled'                => 'Enrolled (' . $counts['enrolled'] . ')',
    'REJECTED'                => 'Rejected',
    'CANCELLED'               => 'Cancelled',
    'all'                     => 'All applications',
];
?>
<div class="space-y-6">
    <div>
        <p class="eyebrow">Registrar</p>
        <h2 class="mt-1 text-2xl">Applications</h2>
        <p class="mt-1 text-sm text-slate-600">Oldest submissions first, so nobody waits longest.</p>
    </div>

    <form method="GET" action="<?= e(url('/registrar/applications')) ?>" class="card grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4" data-auto-submit>
        <div>
            <label for="filter-status" class="field-label">Status</label>
            <select id="filter-status" name="status" class="field-input">
                <?php foreach ($statusOptions as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="filter-term" class="field-label">Term</label>
            <select id="filter-term" name="term_id" class="field-input">
                <option value="">All terms</option>
                <?php foreach ($terms as $term): ?>
                    <option value="<?= e($term['id']) ?>" <?= (string) $filters['term_id'] === (string) $term['id'] ? 'selected' : '' ?>><?= e(Term::label($term)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="filter-program" class="field-label">Program</label>
            <select id="filter-program" name="program_id" class="field-input">
                <option value="">All programs</option>
                <?php foreach ($programs as $program): ?>
                    <option value="<?= e($program['id']) ?>" <?= (string) $filters['program_id'] === (string) $program['id'] ? 'selected' : '' ?>><?= e($program['code']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="filter-q" class="field-label">Search</label>
            <div class="flex gap-2">
                <input type="search" id="filter-q" name="q" value="<?= e($filters['q']) ?>" placeholder="Name, email, or APP number" class="field-input">
                <button type="submit" class="btn-secondary shrink-0">Search</button>
            </div>
        </div>
    </form>

    <section class="card" aria-labelledby="queue-heading">
        <h2 id="queue-heading" class="sr-only">Results</h2>
        <?php if ($result['rows'] === []): ?>
            <?= component('empty-state', [
                'title'   => $filters['status'] === 'action' ? 'Nothing needs action right now' : 'No applications match these filters',
                'message' => $filters['status'] === 'action' ? 'New submissions will appear here.' : 'Try a different status, term, or search.',
            ]) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Application</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Applicant</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Program</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Documents</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Submitted</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($result['rows'] as $row): ?>
                            <tr class="hover:bg-brand-50/50">
                                <td class="whitespace-nowrap px-4 py-3">
                                    <a href="<?= e(url('/registrar/applications/' . $row['id'])) ?>" class="font-display font-bold text-brand-600 hover:underline">
                                        <?= e($row['application_number']) ?>
                                    </a>
                                    <p class="text-xs text-brand-gray"><?= e($row['term_name']) ?>, <?= e($row['academic_year_code']) ?></p>
                                </td>
                                <td class="px-4 py-3">
                                    <p class="font-medium text-slate-800"><?= e($row['last_name'] . ', ' . $row['first_name']) ?></p>
                                    <p class="text-xs text-brand-gray"><?= e($row['email']) ?></p>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-700">
                                    <?= e($row['program_code']) ?> · <?= e($row['year_level_name']) ?>
                                    <p class="text-xs text-brand-gray"><?= e($row['student_type']) ?></p>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-700">
                                    <?= e($row['verified_count']) ?> / <?= e($row['required_count']) ?> verified
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-700"><?= e(dt($row['submitted_at'], 'M j, g:i A')) ?></td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <?= component('status-badge', ['status' => $row['application_status']]) ?>
                                    <?php if ($row['enrollment_state'] === 'ENROLLED'): ?>
                                        <div class="mt-1"><?= component('status-badge', ['status' => 'ENROLLED']) ?></div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?= partial('pagination', [
            'page' => $result['page'], 'pages' => $result['pages'], 'total' => $result['total'],
            'path' => '/registrar/applications', 'query' => $filters, 'noun' => 'application',
        ]) ?>
    </section>
</div>
