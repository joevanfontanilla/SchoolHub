<?php
/**
 * One student record, with the registrar's correction form (docs/04, docs/11).
 *
 * @var array $student
 * @var array $profile
 * @var array $guardians
 * @var array $applications
 * @var array $missing
 * @var array $sexes
 * @var array $civilStatus
 * @var ?array $enrollment   current enrollment
 * @var array  $classes      its classes (AcademicRecordService)
 * @var array  $notEnlisted  section classes not taken, with why
 * @var array  $addable      other classes of the term
 * @var ?float $unitLimit
 * @var array  $history
 * @var ?array $withdrawn    the latest withdrawn enrollment, when the student has no current one
 * @var ?string $balance     what the student still owes on it, in pesos
 */
$id = (int) $student['id'];
$value = static fn (string $field) => old($field, $profile[$field] ?? '');

$oldGuardians = old('guardians', null);
$guardianRows = is_array($oldGuardians) ? array_values($oldGuardians) : $guardians;
if ($guardianRows === []) {
    $guardianRows = [['is_emergency_contact' => 1]];
}

$select = static function (string $name, string $label, array $options) use ($value): string {
    $error = error_for($name);
    $current = (string) $value($name);
    $html = '<div><label for="field-' . e($name) . '" class="field-label">' . e($label) . '</label>'
        . '<select id="field-' . e($name) . '" name="' . e($name) . '" class="field-input ' . ($error ? 'field-input-error' : '') . '">'
        . '<option value="">Select…</option>';
    foreach ($options as $option) {
        $html .= '<option value="' . e($option) . '"' . ($current === $option ? ' selected' : '') . '>' . e($option) . '</option>';
    }
    return $html . '</select>' . ($error ? '<p class="field-error"><span>' . e($error) . '</span></p>' : '') . '</div>';
};
$hasErrors = errors() !== [] && error_for('override_reason') === null && error_for('drop_reason') === null;
?>
<div class="mx-auto max-w-5xl space-y-6">
    <a href="<?= e(url('/registrar/students')) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; Students</a>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-4">
            <?= component('avatar', [
                'src' => trim((string) ($profile['photo_path'] ?? '')) !== ''
                    ? url('/registrar/students/' . $student['id'] . '/photo') . '?v=' . urlencode((string) ($profile['updated_at'] ?? ''))
                    : null,
                'alt' => 'Photo of ' . trim($student['first_name'] . ' ' . $student['last_name']),
            ]) ?>
            <div>
                <p class="eyebrow"><?= e($student['student_number'] ?? 'No student number yet') ?></p>
                <h2 class="mt-1 text-2xl"><?= e(trim($student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name'])) ?></h2>
                <p class="mt-1 text-sm text-slate-600">
                    <?= e($student['email']) ?> · <?= e($withdrawn !== null ? 'Withdrawn' : $student['status']) ?>
                    <?= $student['program_code'] ? ' · ' . e($student['program_code'] . ', ' . $student['year_level_name']) : '' ?>
                </p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <?= partial('print-button') ?>
            <a href="<?= e(url('/registrar/students/' . $id . '/e-id')) ?>" class="btn-secondary"><?= icon('shield', 'h-5 w-5') ?> E-ID</a>
            <a href="<?= e(url('/registrar/students/' . $id . '/prospectus')) ?>" class="btn-secondary"><?= icon('requirements', 'h-5 w-5') ?> Curriculum Checklist</a>
            <?php if ($enrollment !== null): ?>
                <?= partial('preview-link', ['path' => '/registrar/students/' . $id . '/cor?term_id=' . $enrollment['term_id'], 'kind' => 'page', 'title' => 'Certificate of Registration', 'label' => 'Certificate of Registration', 'icon' => 'document', 'class' => 'btn-secondary']) ?>
            <?php endif; ?>
            <span class="badge <?= $missing === [] ? 'bg-success-bg text-success-text' : 'bg-warning-bg text-warning-text' ?>">
                <?= $missing === [] ? 'Profile complete' : 'Profile incomplete' ?>
            </span>
        </div>
    </div>

    <section class="card" aria-labelledby="apps-heading">
        <h2 id="apps-heading" class="border-b border-slate-200 px-5 py-4 text-base">Applications and enrollment</h2>
        <?php if ($applications === []): ?>
            <?= component('empty-state', ['title' => 'No submitted applications']) ?>
        <?php else: ?>
            <ul class="divide-y divide-slate-100">
                <?php foreach ($applications as $app): ?>
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm">
                        <div>
                            <a href="<?= e(url('/registrar/applications/' . $app['id'])) ?>" class="font-semibold text-brand-600 hover:underline"><?= e($app['application_number']) ?></a>
                            <p class="text-xs text-brand-gray">
                                <?= e($app['term_name']) ?>, <?= e($app['academic_year_code']) ?> · <?= e($app['program_code']) ?> · <?= e($app['year_level_name']) ?>
                                <?= $app['section_code'] ? ' · Section ' . e($app['section_code']) : '' ?>
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <?= component('status-badge', ['status' => $app['application_status']]) ?>
                            <?php if ($app['enrollment_state'] === 'ENROLLED'): ?><?= component('status-badge', ['status' => 'ENROLLED']) ?><?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <?php if ($student['curriculum_code']): ?>
        <p class="text-sm text-slate-600">
            Curriculum:
            <a href="<?= e(url('/registrar/curricula/' . $student['curriculum_id'])) ?>" class="font-semibold text-brand-600 hover:underline"><?= e($student['curriculum_code']) ?></a>
            · <?= e($student['curriculum_name']) ?>, effective SY <?= e($student['curriculum_school_year']) ?>
        </p>
    <?php endif; ?>

    <?php if ($enrollment !== null): ?>
        <?php $reasonError = error_for('reason'); ?>
        <section id="withdraw" class="card p-5" aria-labelledby="withdraw-heading">
            <h2 id="withdraw-heading" class="text-base">Withdraw from this term</h2>
            <p class="mt-1 text-sm text-slate-600">Drops every class and frees the seat. The record, grades, attendance, fees and payments stay. The e-ID stops being valid. You can reinstate the student while the school year is active.</p>
            <?php if ($balance !== null): ?>
                <p class="mt-2 rounded-lg bg-warning-bg px-3 py-2 text-sm text-warning-text">Balance on the account: <strong><?= e(money($balance)) ?></strong>. Withdrawing does not change fees or payments. Any refund or reduction is a separate adjustment by the cashier.</p>
            <?php endif; ?>
            <form method="POST" action="<?= e(url('/registrar/students/' . $id . '/withdraw')) ?>" class="mt-3 flex flex-wrap items-end gap-3" novalidate
                  data-confirm="Every class is dropped and the seat is freed. The student is notified. Fees and payments are not changed." data-confirm-title="Withdraw this student?" data-confirm-tone="danger" data-confirm-label="Withdraw student"
                  data-confirm-requires="#withdraw-reason" data-confirm-requires-message="Write the reason for withdrawing the student first. It is recorded in the audit log.">
                <?= csrf_field() ?>
                <div class="min-w-[16rem] flex-1">
                    <label for="withdraw-reason" class="field-label">Reason <span class="text-danger-text" aria-hidden="true">*</span></label>
                    <input id="withdraw-reason" name="reason" class="field-input <?= $reasonError ? 'field-input-error' : '' ?>" maxlength="480" placeholder="e.g. Transferred to another school">
                    <?php if ($reasonError): ?><p class="field-error"><span><?= e($reasonError) ?></span></p><?php endif; ?>
                </div>
                <button type="submit" class="btn-danger">Withdraw student</button>
            </form>
        </section>
    <?php elseif ($withdrawn !== null): ?>
        <?php $reasonError = error_for('reason'); ?>
        <section id="withdraw" class="card p-5" aria-labelledby="withdraw-heading">
            <div class="flex flex-wrap items-center gap-2">
                <h2 id="withdraw-heading" class="text-base">Withdrawn · <?= e($withdrawn['term_name']) ?>, <?= e($withdrawn['academic_year_code']) ?></h2>
                <?= component('status-badge', ['status' => 'CANCELLED', 'label' => 'Withdrawn']) ?>
            </div>
            <p class="mt-1 text-sm text-slate-600">Section <?= e($withdrawn['section_code']) ?> · withdrawn <?= e(dt($withdrawn['withdrawn_at'])) ?> by <?= e((string) $withdrawn['withdrawn_by_name']) ?>. Reason: <?= e((string) $withdrawn['withdraw_reason']) ?></p>
            <?php if ($balance !== null): ?>
                <p class="mt-2 text-sm text-slate-600">Balance on the account: <strong><?= e(money($balance)) ?></strong>.</p>
            <?php endif; ?>
            <?php if ($withdrawn['reinstate_problem'] !== null): ?>
                <p class="mt-3 text-sm text-slate-600"><?= e($withdrawn['reinstate_problem']) ?></p>
            <?php else: ?>
                <form method="POST" action="<?= e(url('/registrar/students/' . $id . '/reinstate')) ?>" class="mt-3 flex flex-wrap items-end gap-3" novalidate
                      data-confirm="The student is enrolled again and the classes dropped by the withdrawal come back." data-confirm-title="Reinstate this student?" data-confirm-tone="success" data-confirm-label="Reinstate"
                      data-confirm-requires="#reinstate-reason" data-confirm-requires-message="Write the reason for reinstating the student first.">
                    <?= csrf_field() ?>
                    <div class="min-w-[16rem] flex-1">
                        <label for="reinstate-reason" class="field-label">Reason <span class="text-danger-text" aria-hidden="true">*</span></label>
                        <input id="reinstate-reason" name="reason" class="field-input <?= $reasonError ? 'field-input-error' : '' ?>" maxlength="480" placeholder="e.g. Withdrawn by mistake">
                        <?php if ($reasonError): ?><p class="field-error"><span><?= e($reasonError) ?></span></p><?php endif; ?>
                    </div>
                    <button type="submit" class="btn-primary">Reinstate student</button>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <?php if ($enrollment !== null): ?>
        <?php
        $number = static fn ($value): string => rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');
        $overrideFailed = error_for('override_reason') !== null;
        ?>
        <section id="classes" class="card" aria-labelledby="classes-heading">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                <div>
                    <h2 id="classes-heading" class="text-base">Classes · <?= e($enrollment['term_name']) ?>, <?= e($enrollment['academic_year_code']) ?></h2>
                    <p class="text-xs text-brand-gray">Section <?= e($enrollment['section_code']) ?></p>
                </div>
                <p class="text-sm text-slate-700">
                    <span class="font-display font-bold text-brand-navy"><?= e($number($enrollment['total_units'])) ?></span> of <?= e($number($unitLimit)) ?> units
                    <?php if ((float) $enrollment['total_units'] > (float) $unitLimit): ?><span class="badge ml-1 bg-warning-bg text-warning-text">Overload</span><?php endif; ?>
                </p>
            </div>

            <?php if ($classes === []): ?>
                <?= component('empty-state', ['title' => 'Not enlisted in any class', 'message' => 'Classes are added from the section\'s class list, or below.']) ?>
            <?php else: ?>
                <ul class="divide-y divide-slate-100">
                    <?php foreach ($classes as $class): ?>
                        <?php $dropped = $class['enlistment_status'] === 'DROPPED'; ?>
                        <li class="flex flex-wrap items-start justify-between gap-3 px-5 py-3 text-sm">
                            <div class="min-w-0">
                                <p class="<?= $dropped ? 'text-brand-gray' : '' ?>">
                                    <span class="font-display font-bold <?= $dropped ? '' : 'text-brand-navy' ?>"><?= e($class['code']) ?></span>
                                    <?= e($class['title']) ?> · <?= e($number($class['units'])) ?> units
                                </p>
                                <p class="text-xs text-brand-gray">
                                    <?= e(implode(', ', array_map(static fn ($m) => substr(App\Services\ClassScheduleService::DAYS[(int) $m['day_of_week']], 0, 3) . ' ' . App\Services\ClassScheduleService::timeRange($m['start_time'], $m['end_time']), $class['meetings']))) ?>
                                    · Section <?= e($class['section_code']) ?> · <?= e($class['teacher_name'] ?? 'No teacher yet') ?>
                                </p>
                                <?php if ($class['override_reason'] && !$dropped): ?>
                                    <p class="mt-1 text-xs text-warning-text">Override: <?= e($class['override_reason']) ?></p>
                                <?php endif; ?>
                                <?php if ($dropped): ?>
                                    <p class="mt-1 text-xs text-brand-gray">Dropped <?= e(dt($class['dropped_at'])) ?> · <?= e($class['drop_reason']) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="flex items-center gap-2">
                                <?= component('status-badge', ['status' => $class['enlistment_status']]) ?>
                                <?php if (!$dropped): ?>
                                    <details class="relative">
                                        <summary class="cursor-pointer text-sm font-semibold text-danger-text hover:underline">Drop<span class="sr-only"> <?= e($class['code']) ?></span></summary>
                                        <form method="POST" action="<?= e(url('/registrar/enlistments/' . $class['enlistment_id'] . '/drop')) ?>" class="absolute right-0 z-10 mt-2 w-72 space-y-2 rounded-lg border border-slate-200 bg-white p-3 shadow-lg"
                                              data-confirm="<?= e($class['code']) ?> is dropped from this student's classes. Its grades and attendance stay on record." data-confirm-title="Drop <?= e($class['code']) ?>?" data-confirm-tone="danger"
                                              data-confirm-requires="#drop-<?= e($class['enlistment_id']) ?>" data-confirm-requires-message="Write the reason for dropping the class first.">
                                            <?= csrf_field() ?>
                                            <label for="drop-<?= e($class['enlistment_id']) ?>" class="field-label">Reason for dropping <?= e($class['code']) ?></label>
                                            <input id="drop-<?= e($class['enlistment_id']) ?>" name="drop_reason" required maxlength="500" class="field-input">
                                            <button type="submit" class="btn-danger w-full">Drop class</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($notEnlisted !== []): ?>
                <div class="border-t border-slate-200 bg-warning-bg/40 px-5 py-4">
                    <h3 class="flex items-center gap-2 text-sm text-warning-text">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
                        Section classes this student is not enlisted in
                    </h3>
                    <ul class="mt-3 space-y-3">
                        <?php foreach ($notEnlisted as $flag): ?>
                            <?php $clash = in_array('conflict', array_column($flag['problems'], 'type'), true); ?>
                            <li class="rounded-lg border border-warning-line bg-white p-3 text-sm">
                                <p><span class="font-display font-bold text-brand-navy"><?= e($flag['code']) ?></span> <?= e($flag['title']) ?><?= $flag['dropped'] ? ' <span class="text-xs text-brand-gray">(dropped)</span>' : '' ?></p>
                                <?php foreach ($flag['problems'] as $problem): ?>
                                    <p class="mt-1 text-xs text-warning-text"><?= e($problem['message']) ?></p>
                                <?php endforeach; ?>
                                <?php if ($clash): ?>
                                    <p class="mt-2 text-xs text-slate-600">A time conflict cannot be overridden. Change a class's schedule first.</p>
                                <?php else: ?>
                                    <?php if ($flag['problems'] === []): ?>
                                        <form method="POST" action="<?= e(url('/registrar/students/' . $id . '/enlistments')) ?>" class="mt-2 flex flex-wrap items-end gap-2"
                                              data-confirm="The class is added to the student's schedule and load." data-confirm-title="Enlist in <?= e($flag['code']) ?>?" data-confirm-tone="success" data-confirm-label="Enlist">
                                    <?php else: ?>
                                        <form method="POST" action="<?= e(url('/registrar/students/' . $id . '/enlistments')) ?>" class="mt-2 flex flex-wrap items-end gap-2"
                                              data-confirm="The problem listed above is set aside for this student, and your reason is recorded in the audit log." data-confirm-title="Enlist in <?= e($flag['code']) ?> with an override?" data-confirm-tone="warning" data-confirm-label="Enlist with override"
                                              data-confirm-requires="#override-<?= e($flag['class_id']) ?>" data-confirm-requires-message="Write why the problem can be set aside, for example a subject credited from a previous school.">
                                    <?php endif; ?>
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="class_id" value="<?= e($flag['class_id']) ?>">
                                        <?php if ($flag['problems'] !== []): ?>
                                            <div class="min-w-[16rem] flex-1">
                                                <label for="override-<?= e($flag['class_id']) ?>" class="field-label">Override reason</label>
                                                <?php $flagError = (string) old('class_id') === (string) $flag['class_id'] ? error_for('override_reason') : null; ?>
                                                <input id="override-<?= e($flag['class_id']) ?>" name="override_reason" maxlength="500" placeholder="e.g. Credited from previous school"
                                                       class="field-input <?= $flagError ? 'field-input-error' : '' ?>" <?= $flagError ? 'aria-invalid="true" aria-describedby="override-error-' . e($flag['class_id']) . '"' : '' ?>>
                                                <?php if ($flagError): ?><p id="override-error-<?= e($flag['class_id']) ?>" class="field-error"><span><?= e($flagError) ?></span></p><?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <button type="submit" class="btn-secondary"><?= $flag['problems'] === [] ? 'Enlist' : 'Enlist with override' ?></button>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($addable !== []): ?>
                <form method="POST" action="<?= e(url('/registrar/students/' . $id . '/enlistments')) ?>" class="space-y-3 border-t border-slate-200 px-5 py-4" novalidate
                      data-confirm="The student is enlisted in the chosen class. An override reason, if given, is recorded in the audit log." data-confirm-title="Add this class?" data-confirm-tone="primary">
                    <?= csrf_field() ?>
                    <h3 class="text-sm">Add a class from another section</h3>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="add-class" class="field-label">Class</label>
                            <select id="add-class" name="class_id" class="field-input">
                                <?php foreach ($addable as $option): ?>
                                    <option value="<?= e($option['id']) ?>" <?= (string) old('class_id') === (string) $option['id'] ? 'selected' : '' ?>>
                                        <?= e($option['code'] . ' · ' . $option['section_code'] . ' · ' . implode(', ', array_map(static fn ($m) => substr(App\Services\ClassScheduleService::DAYS[(int) $m['day_of_week']], 0, 3) . ' ' . substr($m['start_time'], 0, 5), $option['meetings']))) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?= component('field', ['name' => 'override_reason', 'label' => 'Override reason (only if needed)', 'value' => $overrideFailed ? old('override_reason') : '', 'help' => 'Required for a missing prerequisite, progress, or overload. Recorded in the audit log.']) ?>
                    </div>
                    <button type="submit" class="btn-secondary">Add class</button>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section aria-labelledby="student-documents-heading" class="space-y-3">
        <div class="flex flex-wrap items-end justify-between gap-2">
            <h2 id="student-documents-heading" class="text-lg">Documents</h2>
            <p class="text-sm text-brand-gray">Open any document to preview it. Every view of an uploaded document is recorded in the audit log.</p>
        </div>
        <?= partial('student-documents', ['documents' => $documents, 'forStudent' => false]) ?>
    </section>

    <section id="credits" class="card" aria-labelledby="credits-heading">
        <div class="flex flex-wrap items-end justify-between gap-2 border-b border-slate-200 px-5 py-4">
            <h2 id="credits-heading" class="text-base">Credited subjects</h2>
            <p class="text-sm text-brand-gray">Transfer credit, or subjects earned before this system. They count as passed.</p>
        </div>

        <?php if ($credits === []): ?>
            <p class="px-5 py-4 text-sm text-brand-gray">No credited subjects.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-brand-gray">
                            <th class="px-5 py-2 font-semibold">Subject</th>
                            <th class="px-5 py-2 font-semibold">Units</th>
                            <th class="px-5 py-2 font-semibold">Grade</th>
                            <th class="px-5 py-2 font-semibold">Source</th>
                            <th class="px-5 py-2 font-semibold">Credited by</th>
                            <?php if ($canCredit): ?><th class="px-5 py-2"><span class="sr-only">Actions</span></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($credits as $credit): ?>
                            <tr class="border-b border-slate-100 last:border-b-0 align-top">
                                <td class="px-5 py-3"><span class="font-semibold text-brand-navy"><?= e($credit['subject_code']) ?></span><span class="block text-xs text-brand-gray"><?= e($credit['subject_title']) ?></span></td>
                                <td class="px-5 py-3 tabular-nums"><?= e(rtrim(rtrim(number_format((float) $credit['units'], 1), '0'), '.')) ?></td>
                                <td class="px-5 py-3 tabular-nums"><?= e($credit['grade'] ?? '—') ?></td>
                                <td class="px-5 py-3"><?= e($credit['source'] ?? '—') ?><?php if ($credit['remarks']): ?><span class="block text-xs text-brand-gray"><?= e($credit['remarks']) ?></span><?php endif; ?></td>
                                <td class="px-5 py-3 text-xs text-brand-gray"><?= e($credit['credited_by_name']) ?><span class="block"><?= e(dt($credit['created_at'])) ?></span></td>
                                <?php if ($canCredit): ?>
                                    <td class="px-5 py-3 text-right">
                                        <form method="POST" action="<?= e(url('/registrar/students/' . $id . '/credits/' . $credit['id'] . '/delete')) ?>"
                                              data-confirm="This subject stops counting as passed for the student. This cannot be undone." data-confirm-title="Remove this credit?" data-confirm-tone="danger" data-confirm-label="Remove credit">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="text-sm font-semibold text-danger-text hover:underline">Remove</button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($canCredit): ?>
            <?php if ($creditable === []): ?>
                <p class="border-t border-slate-100 px-5 py-4 text-sm text-brand-gray">Every subject in this student's curriculum is already credited or passed.</p>
            <?php else: ?>
                <form method="POST" action="<?= e(url('/registrar/students/' . $id . '/credits')) ?>" class="grid gap-4 border-t border-slate-100 px-5 py-4 sm:grid-cols-2" novalidate
                      data-confirm="The subject is recorded as credit for this student and counts as passed." data-confirm-title="Credit this subject?" data-confirm-tone="warning" data-confirm-label="Credit subject" data-confirm-requires="#credit-subject" data-confirm-requires-message="Choose the subject to credit.">
                    <?= csrf_field() ?>
                    <div class="sm:col-span-2">
                        <label for="credit-subject" class="field-label">Subject <span class="text-danger-text" aria-hidden="true">*</span></label>
                        <select id="credit-subject" name="subject_id" class="field-input <?= error_for('subject_id') ? 'field-input-error' : '' ?>">
                            <option value="">Select a subject…</option>
                            <?php foreach ($creditable as $s): ?>
                                <option value="<?= e($s['id']) ?>" <?= (string) old('subject_id') === (string) $s['id'] ? 'selected' : '' ?>>
                                    <?= e($s['code'] . ' — ' . $s['title']) ?> (<?= e(rtrim(rtrim(number_format((float) $s['units'], 1), '0'), '.')) ?> units<?= $s['curriculum_code'] ? ', ' . e($s['curriculum_code']) : '' ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($m = error_for('subject_id')): ?><p class="field-error"><span><?= e($m) ?></span></p><?php endif; ?>
                    </div>
                    <?= component('field', ['name' => 'grade', 'label' => 'Grade (optional)', 'value' => old('grade'), 'help' => 'A grade from the Official Grading System like 1.40, if known. Crediting counts as passed regardless.']) ?>
                    <?= component('field', ['name' => 'source', 'label' => 'Source (optional)', 'value' => old('source'), 'help' => 'e.g. Transferred from ABC College.']) ?>
                    <div class="sm:col-span-2">
                        <?= component('field', ['name' => 'remarks', 'label' => 'Note (optional)', 'value' => old('remarks')]) ?>
                    </div>
                    <div class="sm:col-span-2">
                        <button type="submit" class="btn-primary">Credit subject</button>
                    </div>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <?php if ($history !== []): ?>
        <section class="card" aria-labelledby="history-heading">
            <h2 id="history-heading" class="border-b border-slate-200 px-5 py-4 text-base">Academic history</h2>
            <?php foreach ($history as $term): ?>
                <div class="border-b border-slate-100 last:border-b-0">
                    <p class="px-5 pt-4 text-sm font-semibold text-brand-navy"><?= e($term['term_name']) ?>, <?= e($term['academic_year_code']) ?> · <?= e($term['section_code']) ?> · <?= e($term['program_code']) ?></p>
                    <?php if ($term['classes'] === []): ?>
                        <p class="px-5 py-3 text-sm text-brand-gray">No classes.</p>
                    <?php else: ?>
                        <?= partial('grade-table', ['periods' => $term['periods'], 'classes' => $term['classes'], 'caption' => $term['term_name'] . ' grades']) ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <details id="edit" class="card group" <?= $hasErrors ? 'open' : '' ?>>
        <summary class="flex cursor-pointer items-center justify-between px-5 py-4 font-display text-base font-bold text-brand-navy">
            Correct this record
            <span class="text-sm font-semibold text-brand-600 group-open:hidden">Show</span>
        </summary>
        <form method="POST" action="<?= e(url("/registrar/students/{$id}")) ?>" enctype="multipart/form-data" class="space-y-6 border-t border-slate-200 px-5 py-5" novalidate
              data-confirm="The student's record changes right away, and the change is recorded in the audit log." data-confirm-title="Save these corrections?" data-confirm-tone="warning">
            <?= csrf_field() ?>
            <p class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">
                Corrections are recorded in the audit log. Unlike the student, you can correct the legal name after an application is submitted.
            </p>

            <?= partial('student/photo-field', [
                'photoUrl' => trim((string) ($profile['photo_path'] ?? '')) !== ''
                    ? url('/registrar/students/' . $id . '/photo') . '?v=' . urlencode((string) ($profile['updated_at'] ?? ''))
                    : null,
                'maxBytes' => App\Services\StudentProfileService::PHOTO_MAX_BYTES,
            ]) ?>

            <div class="grid gap-5 sm:grid-cols-3">
                <?= component('field', ['name' => 'first_name', 'label' => 'First name', 'required' => true, 'value' => old('first_name', $student['first_name'])]) ?>
                <?= component('field', ['name' => 'middle_name', 'label' => 'Middle name', 'value' => old('middle_name', $student['middle_name'] ?? '')]) ?>
                <?= component('field', ['name' => 'last_name', 'label' => 'Last name', 'required' => true, 'value' => old('last_name', $student['last_name'])]) ?>
            </div>

            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <?= component('field', ['name' => 'birthdate', 'label' => 'Date of birth', 'type' => 'date', 'required' => true, 'value' => $value('birthdate')]) ?>
                <?= $select('sex', 'Sex', $sexes) ?>
                <?= $select('civil_status', 'Civil status', $civilStatus) ?>
                <?= component('field', ['name' => 'nationality', 'label' => 'Nationality', 'required' => true, 'value' => $value('nationality')]) ?>
                <?= component('field', ['name' => 'religion', 'label' => 'Religion', 'value' => $value('religion')]) ?>
                <?= component('field', ['name' => 'mobile', 'label' => 'Mobile number', 'type' => 'tel', 'required' => true, 'value' => $value('mobile')]) ?>
                <?= component('field', ['name' => 'telephone', 'label' => 'Telephone', 'type' => 'tel', 'value' => $value('telephone')]) ?>
                <?= component('field', ['name' => 'street', 'label' => 'House no. and street', 'value' => $value('street')]) ?>
                <?= component('field', ['name' => 'barangay', 'label' => 'Barangay', 'required' => true, 'value' => $value('barangay')]) ?>
                <?= component('field', ['name' => 'city_municipality', 'label' => 'City or municipality', 'required' => true, 'value' => $value('city_municipality')]) ?>
                <?= component('field', ['name' => 'province', 'label' => 'Province', 'required' => true, 'value' => $value('province')]) ?>
                <?= component('field', ['name' => 'postal_code', 'label' => 'Postal code', 'value' => $value('postal_code')]) ?>
            </div>

            <fieldset data-repeater>
                <legend class="font-display text-sm font-bold text-brand-navy">Guardians</legend>
                <?php if ($guardianError = error_for('guardians')): ?>
                    <p class="field-error mb-3" role="alert"><span><?= e($guardianError) ?></span></p>
                <?php endif; ?>
                <div class="mt-3 space-y-4" data-repeater-list>
                    <?php foreach ($guardianRows as $index => $guardian): ?>
                        <?= partial('student/guardian-row', ['index' => $index, 'guardian' => $guardian]) ?>
                    <?php endforeach; ?>
                </div>
                <template data-repeater-template>
                    <?= partial('student/guardian-row', ['index' => '__INDEX__', 'guardian' => []]) ?>
                </template>
                <button type="button" class="btn-secondary mt-4" data-repeater-add>Add another guardian</button>
            </fieldset>

            <button type="submit" class="btn-primary">Save corrections</button>
        </form>
    </details>
</div>
