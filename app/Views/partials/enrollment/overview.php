<?php
/**
 * The application at a glance: the three independent status axes side by side
 * (docs/26), with the details they belong to.
 *
 * @var array $application
 */
use App\Models\EnrollmentApplication as Application;

$returned = in_array($application['application_status'], [Application::REQUIREMENTS_INCOMPLETE, Application::CORRECTION_REQUIRED, Application::REJECTED], true);
?>
<section class="card" aria-labelledby="overview-heading">
    <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
        <div>
            <p class="eyebrow">Application</p>
            <h2 id="overview-heading" class="mt-1 text-xl"><?= e($application['application_number']) ?></h2>
            <p class="mt-1 text-sm text-slate-600">
                <?= e($application['term_name']) ?>, A.Y. <?= e($application['academic_year_code']) ?>
            </p>
        </div>
        <?= component('status-badge', ['status' => $application['application_status'], 'size' => 'md']) ?>
    </div>

    <?php if ($returned && !empty($application['remarks'])): ?>
        <div class="border-b border-warning-line bg-warning-bg px-5 py-3 text-sm text-warning-text" role="note">
            <span class="font-semibold">From the registrar:</span> <?= e($application['remarks']) ?>
        </div>
    <?php endif; ?>

    <dl class="grid gap-x-6 gap-y-4 px-5 py-5 sm:grid-cols-2 lg:grid-cols-3">
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wider text-brand-gray">Program</dt>
            <dd class="mt-1 text-sm text-slate-800"><?= e($application['program_code']) ?> · <?= e($application['program_name']) ?></dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wider text-brand-gray">Year level</dt>
            <dd class="mt-1 text-sm text-slate-800"><?= e($application['year_level_name']) ?></dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wider text-brand-gray">Student type</dt>
            <dd class="mt-1 text-sm text-slate-800"><?= e($application['student_type']) ?></dd>
        </div>
        <?php if (!empty($application['previous_school'])): ?>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wider text-brand-gray">Previous school</dt>
                <dd class="mt-1 text-sm text-slate-800">
                    <?= e($application['previous_school']) ?>
                    <?= !empty($application['last_school_year']) ? '(' . e($application['last_school_year']) . ')' : '' ?>
                </dd>
            </div>
        <?php endif; ?>
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wider text-brand-gray">Payment</dt>
            <dd class="mt-1"><?= component('status-badge', ['status' => $application['payment_status']]) ?></dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wider text-brand-gray">Enrollment</dt>
            <dd class="mt-1"><?= component('status-badge', ['status' => $application['enrollment_state']]) ?></dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wider text-brand-gray">Submitted</dt>
            <dd class="mt-1 text-sm text-slate-800"><?= e(dt($application['submitted_at'])) ?></dd>
        </div>
    </dl>
</section>
