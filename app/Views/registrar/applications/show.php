<?php
/**
 * Registrar review of one application (docs/11 "Application Review").
 *
 * Shows everything docs/11 lists: student information, academic selection,
 * uploaded requirements, previous applications, the review timeline, remarks,
 * and the current status. Buttons mirror EnrollmentStatusService's rules; the
 * server re-checks every one.
 *
 * @var array  $application
 * @var array  $student
 * @var array  $profile
 * @var array  $guardians
 * @var array  $requirements
 * @var array  $history
 * @var array  $otherApps
 * @var array  $targets
 * @var ?string $paymentProblem
 * @var ?array $enrollment
 * @var array  $subjects    the subjects the student chose
 * @var array  $sections    sections matching the application
 * @var ?array $assessment
 */
use App\Models\EnrollmentApplication as Application;
use App\Models\Download;

$id = (int) $application['id'];
$status = $application['application_status'];
$reviewing = $status === Application::UNDER_REVIEW;
// Once enrolled, the application can no longer be cancelled (rule 12's
// precondition); don't offer a button the server will refuse.
if ($application['enrollment_state'] !== 'NOT_ENROLLED') {
    $targets = array_values(array_diff($targets, [Application::CANCELLED]));
}
$can = static fn (string $target) => in_array($target, $targets, true);

$required = array_filter($requirements, static fn ($r) => (int) $r['is_required'] === 1);
$verified = array_filter($required, static fn ($r) => $r['status'] === 'VERIFIED');
$turnedBack = array_filter($requirements, static fn ($r) => in_array($r['status'], ['REJECTED', 'CORRECTION_REQUIRED'], true));
$allVerified = count($required) > 0 && count($verified) === count($required);

$fullName = trim($student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name']);
$emergency = array_values(array_filter($guardians, static fn ($g) => (int) $g['is_emergency_contact'] === 1))[0] ?? ($guardians[0] ?? null);
$address = implode(', ', array_filter([$profile['street'] ?? null, $profile['barangay'] ?? null, $profile['city_municipality'] ?? null, $profile['province'] ?? null]));
$oldRemarksFor = (int) old('remarks_for', 0);
?>
<div class="space-y-6">
    <a href="<?= e(url('/registrar/applications')) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; Application queue</a>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="eyebrow">Application <?= e($application['application_number']) ?></p>
            <h2 class="mt-1 text-2xl"><?= e($fullName) ?></h2>
            <p class="mt-1 text-sm text-slate-600">
                <?= e($application['program_code']) ?> · <?= e($application['year_level_name']) ?> · <?= e($application['student_type']) ?>
                · <?= e($application['term_name']) ?>, A.Y. <?= e($application['academic_year_code']) ?>
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <?= component('status-badge', ['status' => $status, 'size' => 'md']) ?>
            <?= component('status-badge', ['status' => $application['enrollment_state'], 'size' => 'md']) ?>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <!-- Left: the applicant and their documents -->
        <div class="space-y-6 lg:col-span-2">
            <?php
            // The documents are the registrar's work while the application waits for or is under
            // review, so they come first then; afterwards they follow the applicant details.
            $documentsFirst = in_array($status, [Application::SUBMITTED, Application::UNDER_REVIEW], true);
            ob_start();
            ?>
            <section class="card" aria-labelledby="documents-heading">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                    <div>
                        <h2 id="documents-heading" class="text-base">Documents</h2>
                        <p class="mt-0.5 text-sm text-brand-gray"><?= e(count($verified)) ?> of <?= e(count($required)) ?> required documents verified</p>
                    </div>
                    <?php if (!$reviewing && $status === Application::SUBMITTED): ?>
                        <p class="text-sm text-slate-600">Start the review to verify documents.</p>
                    <?php endif; ?>
                </div>

                <ul class="divide-y divide-slate-100">
                    <?php foreach ($requirements as $requirement): ?>
                        <?php $rid = (int) $requirement['id']; ?>
                        <li id="requirement-<?= e($rid) ?>" class="scroll-mt-24 px-5 py-5">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h3 class="text-sm">
                                        <?= e($requirement['name']) ?>
                                        <span class="ml-1 font-sans text-xs font-medium <?= (int) $requirement['is_required'] === 1 ? 'text-danger-text' : 'text-brand-gray' ?>">
                                            <?= (int) $requirement['is_required'] === 1 ? 'Required' : 'Optional' ?>
                                        </span>
                                    </h3>
                                    <?php if (!empty($requirement['file_id'])): ?>
                                        <p class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                                            <?= partial('preview-link', [
                                                'path' => '/documents/' . $requirement['file_id'], 'mime' => $requirement['mime_type'],
                                                'title' => $requirement['name'] . ' · ' . $requirement['original_filename'], 'label' => 'Preview',
                                                'srLabel' => $requirement['name'], 'group' => 'application-documents', 'icon' => 'document',
                                                'class' => 'inline-flex items-center gap-1.5 rounded-lg border border-brand-100 bg-white px-2.5 py-1 font-semibold text-brand-600 hover:bg-brand-50',
                                            ]) ?>
                                            <span class="min-w-0 truncate text-slate-700"><?= e($requirement['original_filename']) ?></span>
                                            <span class="text-brand-gray"><?= e(Download::humanSize((int) $requirement['file_size'])) ?> · <?= e(dt($requirement['uploaded_at'])) ?></span>
                                            <a href="<?= e(url('/documents/' . $requirement['file_id'])) ?>" class="text-xs font-semibold text-brand-600 hover:underline">Download<span class="sr-only"> <?= e($requirement['name']) ?></span></a>
                                        </p>
                                    <?php else: ?>
                                        <p class="mt-1.5 text-sm text-brand-gray">Nothing uploaded.</p>
                                    <?php endif; ?>
                                    <?php if (!empty($requirement['remarks'])): ?>
                                        <p class="mt-2 rounded-lg border border-warning-line bg-warning-bg px-3 py-2 text-sm text-warning-text">
                                            <span class="font-semibold">Note to student:</span> <?= e($requirement['remarks']) ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                                <?= component('status-badge', ['status' => $requirement['status']]) ?>
                            </div>

                            <?php if ($reviewing && !empty($requirement['file_id'])): ?>
                                <form method="POST" action="<?= e(url("/registrar/requirements/{$rid}/review")) ?>" class="mt-3 space-y-3" data-allow-resubmit>
                                    <?= csrf_field() ?>
                                    <label for="remarks-<?= e($rid) ?>" class="sr-only">Note to the student about <?= e($requirement['name']) ?></label>
                                    <textarea id="remarks-<?= e($rid) ?>" name="remarks" rows="2" maxlength="1000" class="field-input"
                                              placeholder="Note to the student (required to reject or ask for a correction)"><?= $oldRemarksFor === $rid ? e(old('remarks')) : '' ?></textarea>
                                    <div class="flex flex-wrap gap-2">
                                        <?php if ($requirement['status'] !== 'VERIFIED'): ?>
                                            <button type="submit" name="decision" value="VERIFIED" class="btn border border-success-line bg-success-bg text-success-text hover:brightness-95"
                                                    data-confirm-click="Mark <?= e($requirement['name']) ?> as verified? The student sees it as accepted." data-confirm-title="Verify this document?" data-confirm-tone="success">Verify</button>
                                        <?php endif; ?>
                                        <button type="submit" name="decision" value="CORRECTION_REQUIRED" class="btn border border-warning-line bg-white text-warning-text hover:bg-warning-bg"
                                                data-confirm-click="Ask the student to correct <?= e($requirement['name']) ?>? They are notified and see your note." data-confirm-title="Ask for a correction?" data-confirm-tone="warning" data-confirm-label="Ask for correction"
                                                data-confirm-requires="#remarks-<?= e($rid) ?>" data-confirm-requires-message="Tell the student what to correct in the note box. They will see it.">Needs correction</button>
                                        <button type="submit" name="decision" value="REJECTED" class="btn border border-danger-line bg-white text-danger-text hover:bg-danger-bg"
                                                data-confirm-click="Reject <?= e($requirement['name']) ?>? The student is notified, sees your note, and must upload it again." data-confirm-title="Reject this document?" data-confirm-tone="danger" data-confirm-label="Reject document"
                                                data-confirm-requires="#remarks-<?= e($rid) ?>" data-confirm-requires-message="Tell the student why the document is rejected in the note box. They will see it.">Reject</button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php $documentsSection = (string) ob_get_clean(); ?>
            <?php if ($documentsFirst): ?><?= $documentsSection ?><?php endif; ?>
            <?php $subjectUnits = array_sum(array_map(static fn ($s) => (float) $s['units'], $subjects)); ?>
            <section class="card" aria-labelledby="subjects-heading">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                    <h2 id="subjects-heading" class="flex items-center gap-2 text-base"><span class="text-brand-600"><?= icon('book-open', 'h-5 w-5') ?></span> Subjects chosen</h2>
                    <p class="text-sm text-slate-700"><span class="font-display font-bold text-brand-navy"><?= e(count($subjects)) ?></span> subjects · <span class="font-display font-bold text-brand-navy"><?= e(rtrim(rtrim(number_format($subjectUnits, 1, '.', ''), '0'), '.')) ?></span> units</p>
                </div>
                <?php if ($subjects === []): ?>
                    <p class="px-5 py-4 text-sm text-warning-text">The student has not chosen any subjects. Return the application for correction so they can choose.</p>
                <?php else: ?>
                    <ul class="grid gap-x-6 px-5 py-3 text-sm sm:grid-cols-2">
                        <?php foreach ($subjects as $subject): ?>
                            <li class="flex justify-between gap-3 border-b border-slate-100 py-2"><span><span class="font-semibold text-brand-navy"><?= e($subject['code']) ?></span> <?= e($subject['title']) ?></span><span class="whitespace-nowrap text-slate-600"><?= e(rtrim(rtrim((string) $subject['units'], '0'), '.')) ?> u</span></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <section class="card" aria-labelledby="applicant-heading">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-5 py-4">
                    <h2 id="applicant-heading" class="text-base">Applicant</h2>
                    <a href="<?= e(url('/registrar/students/' . $student['id'])) ?>" class="text-sm font-semibold text-brand-600 hover:underline">Open student record</a>
                </div>
                <dl class="grid gap-x-6 gap-y-4 px-5 py-5 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    <?php foreach ([
                        'Student number' => $student['student_number'] ?? 'Issued on confirmation',
                        'Email'          => $student['email'],
                        'Mobile'         => $profile['mobile'] ?? '—',
                        'Date of birth'  => d($profile['birthdate'] ?? null),
                        'Sex'            => $profile['sex'] ?? '—',
                        'Civil status'   => $profile['civil_status'] ?? '—',
                        'Nationality'    => $profile['nationality'] ?? '—',
                        'Address'        => $address !== '' ? $address : '—',
                    ] as $label => $value): ?>
                        <div class="<?= $label === 'Address' ? 'sm:col-span-2' : '' ?>">
                            <dt class="text-xs font-semibold uppercase tracking-wider text-brand-gray"><?= e($label) ?></dt>
                            <dd class="mt-1 break-words text-slate-800"><?= e($value) ?></dd>
                        </div>
                    <?php endforeach; ?>
                    <div class="sm:col-span-2 lg:col-span-3">
                        <dt class="text-xs font-semibold uppercase tracking-wider text-brand-gray">Emergency contact</dt>
                        <dd class="mt-1 text-slate-800">
                            <?= $emergency !== null ? e($emergency['full_name'] . ' (' . $emergency['relationship'] . ') · ' . $emergency['mobile']) : '—' ?>
                        </dd>
                    </div>
                    <?php if (!empty($application['previous_school'])): ?>
                        <div class="sm:col-span-2 lg:col-span-3">
                            <dt class="text-xs font-semibold uppercase tracking-wider text-brand-gray">Previous school</dt>
                            <dd class="mt-1 text-slate-800">
                                <?= e($application['previous_school']) ?><?= !empty($application['last_school_year']) ? ' (' . e($application['last_school_year']) . ')' : '' ?>
                            </dd>
                        </div>
                    <?php endif; ?>
                </dl>
            </section>

            <?php if (!$documentsFirst): ?><?= $documentsSection ?><?php endif; ?>
        </div>

        <!-- Right: decisions and history -->
        <div class="space-y-6">
            <section id="decision" class="card p-5" aria-labelledby="decision-heading">
                <h2 id="decision-heading" class="text-base">Decision</h2>

                <?php if ($enrollment !== null): ?>
                    <div class="mt-3 rounded-lg border border-success-line bg-success-bg px-4 py-3 text-sm text-success-text">
                        <p class="font-semibold">Enrolled in section <?= e($enrollment['section_code']) ?></p>
                        <p class="mt-0.5">Student number <?= e($student['student_number']) ?> · <?= e(dt($enrollment['enrolled_at'])) ?></p>
                    </div>
                <?php elseif ($status === Application::APPROVED): ?>
                    <?php if ($assessment !== null): ?>
                        <dl class="mt-3 space-y-1 rounded-lg bg-slate-50 px-4 py-3 text-sm">
                            <div class="flex justify-between"><dt class="text-slate-600">Assessment <?= e($assessment['assessment_number']) ?></dt><dd class="font-semibold"><?= e(money($assessment['total_amount'])) ?></dd></div>
                            <div class="flex justify-between"><dt class="text-slate-600">Paid</dt><dd class="font-semibold"><?= e(money($assessment['total_paid'])) ?></dd></div>
                            <div class="flex justify-between"><dt class="text-slate-600">Section assigned</dt><dd class="font-semibold"><?= e($assessment['section_code'] ?? '—') ?></dd></div>
                        </dl>
                        <a href="<?= e(url('/statements/' . $assessment['id'])) ?>" class="mt-2 inline-block text-sm font-semibold text-brand-600 hover:underline">Statement of account</a>
                    <?php endif; ?>
                    <?php if ($paymentProblem !== null): ?>
                        <p class="mt-3 rounded-lg border border-warning-line bg-warning-bg px-4 py-3 text-sm text-warning-text"><?= e($paymentProblem) ?></p>
                    <?php endif; ?>
                    <p class="mt-3 text-xs text-brand-gray">The student is enrolled automatically when the cashier verifies enough payment. Confirm by hand only to use a different section.</p>
                    <a href="<?= e(url("/registrar/applications/{$id}/confirm")) ?>" class="btn-secondary mt-2 w-full">Confirm enrollment by hand</a>
                <?php endif; ?>

                <?php if ($can(Application::UNDER_REVIEW)): ?>
                    <form method="POST" action="<?= e(url("/registrar/applications/{$id}/start-review")) ?>" class="mt-3"
                          data-confirm="Start reviewing this application? The student sees that it is under review." data-confirm-tone="primary">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn-primary w-full">Start review</button>
                        <p class="mt-2 text-xs text-brand-gray">Marks the documents as under review so you can verify them.</p>
                    </form>
                <?php endif; ?>

                <?php if ($can(Application::APPROVED)): ?>
                    <div class="mt-4 rounded-lg bg-slate-50 px-4 py-3 text-sm">
                        <p class="<?= $allVerified ? 'text-success-text' : 'text-slate-700' ?>">
                            <span aria-hidden="true"><?= $allVerified ? '✓' : '•' ?></span>
                            <?= e(count($verified)) ?> of <?= e(count($required)) ?> required documents verified
                        </p>
                        <?php if ($turnedBack !== []): ?>
                            <p class="mt-1 text-warning-text"><span aria-hidden="true">!</span> <?= e(count($turnedBack)) ?> document<?= count($turnedBack) === 1 ? '' : 's' ?> turned back</p>
                        <?php endif; ?>
                    </div>
                    <form method="POST" action="<?= e(url("/registrar/applications/{$id}/approve")) ?>" class="mt-3 space-y-3"
                          data-confirm="The fee assessment is issued to the student right away, and they are notified." data-confirm-title="Approve this application?" data-confirm-tone="success" data-confirm-label="Approve application">
                        <?= csrf_field() ?>
                        <?php $sectionError = error_for('section_id'); ?>
                        <div>
                            <label for="approve-section" class="field-label">Section <span class="text-brand-alert" aria-hidden="true">*</span></label>
                            <select id="approve-section" name="section_id" class="field-input <?= $sectionError ? 'field-input-error' : '' ?>" required>
                                <option value="">Choose a section</option>
                                <?php foreach ($sections as $option): ?>
                                    <?php $full = (int) $option['enrolled_count'] >= (int) $option['capacity']; ?>
                                    <option value="<?= e($option['id']) ?>" <?= (string) old('section_id', (string) ($application['section_id'] ?? '')) === (string) $option['id'] ? 'selected' : '' ?> <?= $full ? 'disabled' : '' ?>>
                                        <?= e($option['code'] . ' · ' . $option['enrolled_count'] . '/' . $option['capacity'] . ($full ? ' (full)' : '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($sectionError): ?><p class="field-error"><span><?= e($sectionError) ?></span></p><?php endif; ?>
                            <?php if ($sections === []): ?><p class="mt-1 text-xs text-warning-text">No section exists for this program, year level, and term. Create one under Sections first.</p><?php endif; ?>
                        </div>
                        <button type="submit" class="btn w-full bg-success-text text-white hover:brightness-110" <?= $allVerified ? '' : 'aria-describedby="approve-hint"' ?>>Approve</button>
                        <?php if (!$allVerified): ?>
                            <p id="approve-hint" class="mt-2 text-xs text-brand-gray">Every required document must be verified first.</p>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>

                <?php $returnTargets = array_values(array_filter([Application::REQUIREMENTS_INCOMPLETE, Application::CORRECTION_REQUIRED, Application::REJECTED, Application::CANCELLED], $can)); ?>
                <?php if ($returnTargets !== []): ?>
                    <form method="POST" action="<?= e(url("/registrar/applications/{$id}/reject")) ?>" class="mt-5 space-y-3 border-t border-slate-200 pt-4" data-allow-resubmit>
                        <?= csrf_field() ?>
                        <?php $remarksError = error_for('remarks'); ?>
                        <label for="decision-remarks" class="field-label">Reason <span class="font-normal text-brand-gray">(shown to the student)</span></label>
                        <textarea id="decision-remarks" name="remarks" rows="3" maxlength="1000"
                                  class="field-input <?= $remarksError && $oldRemarksFor === 0 ? 'field-input-error' : '' ?>"><?= $oldRemarksFor === 0 ? e(old('remarks')) : '' ?></textarea>
                        <?php if ($remarksError && $oldRemarksFor === 0): ?>
                            <p class="field-error"><span><?= e($remarksError) ?></span></p>
                        <?php endif; ?>
                        <div class="grid gap-2">
                            <?php foreach ([
                                Application::REQUIREMENTS_INCOMPLETE => ['return-incomplete', 'Return: replace documents', 'border-warning-line text-warning-text hover:bg-warning-bg', 'The student is notified and can replace the turned-back documents, then submit again.', 'Return for new documents?', 'warning', true],
                                Application::CORRECTION_REQUIRED     => ['request-correction', 'Return: correct details', 'border-warning-line text-warning-text hover:bg-warning-bg', 'The student is notified and can correct their details, then submit again.', 'Return for corrections?', 'warning', true],
                                Application::REJECTED                => ['reject', 'Reject application', 'border-danger-line text-danger-text hover:bg-danger-bg', 'The student is notified with your reason. This cannot be undone.', 'Reject this application?', 'danger', true],
                                Application::CANCELLED               => ['cancel', 'Cancel application', 'border-slate-300 text-slate-700 hover:bg-slate-50', 'The application is closed and the student is notified. This cannot be undone.', 'Cancel this application?', 'danger', $status === Application::APPROVED],
                            ] as $target => [$action, $label, $classes, $confirm, $confirmTitle, $tone, $needsReason]): ?>
                                <?php if ($can($target)): ?>
                                    <button type="submit" formaction="<?= e(url("/registrar/applications/{$id}/{$action}")) ?>"
                                            data-confirm-click="<?= e($confirm) ?>" data-confirm-title="<?= e($confirmTitle) ?>" data-confirm-tone="<?= e($tone) ?>"
                                            <?= $needsReason ? 'data-confirm-requires="#decision-remarks" data-confirm-requires-message="Write the reason in the Reason box. The student will see it."' : '' ?>
                                            class="btn w-full border bg-white <?= $classes ?>"><?= e($label) ?></button>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </form>
                <?php endif; ?>

                <?php if ($targets === [] && $enrollment === null && $status !== Application::APPROVED): ?>
                    <p class="mt-3 text-sm text-slate-600">
                        <?= match ($status) {
                            Application::REQUIREMENTS_INCOMPLETE, Application::CORRECTION_REQUIRED => 'Waiting for the student to resubmit.',
                            Application::REJECTED  => 'This application was rejected.',
                            Application::CANCELLED => 'This application was cancelled.',
                            default                => 'No action is available.',
                        } ?>
                    </p>
                <?php endif; ?>
            </section>

            <?= partial('enrollment/timeline', ['history' => $history]) ?>

            <?php if ($otherApps !== []): ?>
                <section class="card" aria-labelledby="other-heading">
                    <h2 id="other-heading" class="border-b border-slate-200 px-5 py-4 text-base">Previous applications</h2>
                    <ul class="divide-y divide-slate-100">
                        <?php foreach ($otherApps as $other): ?>
                            <li class="flex items-center justify-between gap-3 px-5 py-3 text-sm">
                                <div>
                                    <a href="<?= e(url('/registrar/applications/' . $other['id'])) ?>" class="font-semibold text-brand-600 hover:underline"><?= e($other['application_number']) ?></a>
                                    <p class="text-xs text-brand-gray"><?= e($other['term_name']) ?>, <?= e($other['academic_year_code']) ?></p>
                                </div>
                                <?= component('status-badge', ['status' => $other['application_status']]) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>
        </div>
    </div>
</div>
