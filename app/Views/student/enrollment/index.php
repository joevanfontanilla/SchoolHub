<?php
/**
 * The student's applications (docs/09).
 *
 * @var array $applications newest first
 * @var array $openTerms
 */
use App\Models\Term;
?>
<div class="mx-auto max-w-5xl space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">Student</p>
            <h2 class="mt-1 text-2xl">Enrollment</h2>
            <p class="mt-1 text-sm text-slate-600">Your applications for each term, current and past.</p>
        </div>
        <?php if ($openTerms !== []): ?>
            <a href="<?= e(url('/student/enrollment/apply')) ?>" class="btn-primary">Apply for enrollment</a>
        <?php endif; ?>
    </div>

    <?php if ($openTerms !== []): ?>
        <div class="rounded-xl border border-brand-200 bg-brand-50 px-5 py-4 text-sm text-brand-navy">
            <?php foreach ($openTerms as $term): ?>
                <p>
                    <span class="font-semibold">Enrollment is open</span> for <?= e(Term::label($term)) ?>
                    until <?= e(dt($term['enrollment_closes_at'], 'F j, Y')) ?>.
                </p>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="rounded-xl border border-slate-200 bg-slate-50 px-5 py-4 text-sm text-slate-600">
            Enrollment is not open for any term right now. Check the announcements for the next schedule.
        </div>
    <?php endif; ?>

    <section class="card" aria-labelledby="applications-heading">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 id="applications-heading" class="text-base">My applications</h2>
        </div>

        <?php if ($applications === []): ?>
            <?= component('empty-state', [
                'title'   => 'No applications yet',
                'message' => $openTerms !== [] ? 'Start one with the "Apply for enrollment" button.' : 'You can apply once enrollment opens.',
            ]) ?>
        <?php else: ?>
            <ul class="divide-y divide-slate-100">
                <?php foreach ($applications as $application): ?>
                    <li>
                        <a href="<?= e(url('/student/enrollment/' . $application['id'])) ?>"
                           class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 transition hover:bg-brand-50/60">
                            <div class="min-w-0">
                                <p class="font-display text-sm font-bold text-brand-navy"><?= e($application['application_number']) ?></p>
                                <p class="mt-0.5 text-sm text-slate-600">
                                    <?= e($application['term_name']) ?>, A.Y. <?= e($application['academic_year_code']) ?>
                                    · <?= e($application['program_code']) ?> · <?= e($application['year_level_name']) ?>
                                </p>
                                <p class="mt-0.5 text-xs text-brand-gray">Last updated <?= e(dt($application['updated_at'])) ?></p>
                            </div>
                            <?= component('status-badge', ['status' => $application['application_status']]) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
