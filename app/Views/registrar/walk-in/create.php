<?php
/**
 * Walk-in registration, step 1 (docs/09): the registrar enters an applicant who came to the office.
 *
 * @var array $openTerms
 * @var array $programs
 * @var array $yearLevels
 * @var array $studentTypes
 * @var array $sexes
 * @var array $civilStatus
 * @var array $unfinished
 * @var string $find   what the registrar searched for among registered students
 * @var array $found  the students that matched
 */
use App\Models\Term;

$select = static function (string $name, string $label, array $options, bool $isAssoc = false): string {
    $error = error_for($name);
    $current = (string) old($name, '');
    $html = '<div><label for="field-' . e($name) . '" class="field-label">' . e($label)
        . ' <span class="text-danger-text" aria-hidden="true">*</span><span class="sr-only">(required)</span></label>'
        . '<select id="field-' . e($name) . '" name="' . e($name) . '" required class="field-input ' . ($error ? 'field-input-error' : '') . '"'
        . ($error ? ' aria-invalid="true" aria-describedby="field-' . e($name) . '-error"' : '') . '>'
        . '<option value="">Select…</option>';
    foreach ($options as $value => $text) {
        $value = $isAssoc ? $value : $text;
        $html .= '<option value="' . e($value) . '"' . ($current === (string) $value ? ' selected' : '') . '>' . e($text) . '</option>';
    }
    $html .= '</select>';
    if ($error) {
        $html .= '<p id="field-' . e($name) . '-error" class="field-error"><span>' . e($error) . '</span></p>';
    }
    return $html . '</div>';
};

$termOptions = [];
foreach ($openTerms as $term) {
    $termOptions[$term['id']] = Term::label($term) . ' (closes ' . dt($term['enrollment_closes_at'], 'M j') . ')';
}
$programOptions = [];
foreach ($programs as $program) {
    $programOptions[$program['id']] = $program['code'] . ' · ' . $program['name'];
}
$yearOptions = array_column($yearLevels, 'name', 'id');
$typeOptions = \App\Models\Student::TYPE_LABELS;

$guardianField = static fn (string $name) => "guardians[0][{$name}]";
$guardianValue = static fn (string $name) => (string) ((old('guardians', [[]])[0] ?? [])[$name] ?? '');
?>
<div class="mx-auto max-w-4xl space-y-6">
    <div>
        <p class="eyebrow">Registrar</p>
        <h2 class="mt-1 text-2xl">Walk-in registration</h2>
        <p class="mt-1 text-sm text-slate-600">
            Enter an applicant who came to the office. This creates their account and a draft application;
            you record the documents they hand over on the next page. Fields marked <span class="text-danger-text">*</span> are required.
        </p>
    </div>

    <section class="card p-5" aria-labelledby="find-heading">
        <h3 id="find-heading" class="text-base">Already registered? Find an existing student</h3>
        <p class="mt-1 text-sm text-slate-600">An old student keeps their account. Find them, then enter only the enrollment choices. No new account is made.</p>
        <form method="GET" role="search" aria-label="Find an existing student" action="<?= e(url('/registrar/walk-in')) ?>" class="mt-3 flex gap-2" novalidate>
            <label for="find-student" class="sr-only">Name, student number, or email</label>
            <input type="search" id="find-student" name="find" value="<?= e($find) ?>" placeholder="Name, student number, or email" class="field-input" autocomplete="off">
            <button type="submit" class="btn-secondary shrink-0">Search</button>
        </form>
        <?php if ($find !== ''): ?>
            <?php if ($found === []): ?>
                <p class="mt-3 text-sm text-slate-600">No registered student matches “<?= e($find) ?>”. Use the form below to register them.</p>
            <?php else: ?>
                <ul class="mt-3 divide-y divide-slate-100 text-sm">
                    <?php foreach ($found as $row): ?>
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                            <span><span class="font-semibold text-brand-navy"><?= e($row['last_name'] . ', ' . $row['first_name']) ?></span> <span class="text-brand-gray">· <?= e($row['student_number'] ?? 'no student number yet') ?><?= $row['program_code'] ? ' · ' . e($row['program_code']) : '' ?> · <?= e($row['email']) ?></span></span>
                            <a href="<?= e(url('/registrar/walk-in/student/' . $row['id'])) ?>" class="font-semibold text-brand-600 hover:underline">Enroll this student<span class="sr-only">: <?= e($row['first_name'] . ' ' . $row['last_name']) ?></span></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <?php if ($unfinished !== []): ?>
        <section class="card p-5" aria-labelledby="unfinished-heading">
            <h3 id="unfinished-heading" class="text-base">Walk-ins not yet submitted</h3>
            <ul class="mt-3 divide-y divide-slate-100 text-sm">
                <?php foreach ($unfinished as $row): ?>
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <span><?= e($row['last_name'] . ', ' . $row['first_name']) ?> · <?= e($row['program_code']) ?> · <span class="text-brand-gray"><?= e($row['application_number']) ?></span></span>
                        <a href="<?= e(url('/registrar/walk-in/' . $row['id'])) ?>" class="font-semibold text-brand-600 hover:underline">Continue<span class="sr-only"> <?= e($row['application_number']) ?></span></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if ($openTerms === []): ?>
        <div class="card">
            <?= component('empty-state', [
                'title'   => 'Enrollment is closed',
                'message' => 'No term is accepting applications right now. Set the enrollment window on the Academic Calendar first.',
            ]) ?>
        </div>
    <?php else: ?>
        <form method="POST" action="<?= e(url('/registrar/walk-in')) ?>" class="space-y-6" novalidate data-student-type-form>
            <?= csrf_field() ?>

            <fieldset class="card p-5 sm:p-6">
                <legend class="float-left mb-4 w-full font-display text-base font-bold text-brand-navy">Applicant</legend>
                <div class="clear-both grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <?= component('field', ['name' => 'first_name', 'label' => 'First name', 'required' => true, 'value' => old('first_name', '')]) ?>
                    <?= component('field', ['name' => 'middle_name', 'label' => 'Middle name', 'value' => old('middle_name', '')]) ?>
                    <?= component('field', ['name' => 'last_name', 'label' => 'Last name', 'required' => true, 'value' => old('last_name', '')]) ?>
                    <?= component('field', ['name' => 'suffix', 'label' => 'Suffix', 'value' => old('suffix', ''), 'help' => 'Jr., III…']) ?>
                </div>
                <?php if ($duplicate = error_for('duplicate')): ?>
                    <div class="clear-both mb-5 rounded-xl border border-warning-line bg-warning-bg px-4 py-3 text-sm text-warning-text" role="alert">
                        <p><?= e($duplicate) ?></p>
                        <label class="mt-2 flex items-start gap-2 font-medium">
                            <input type="checkbox" name="confirm_new" value="1" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-600" <?= old('confirm_new') ? 'checked' : '' ?>>
                            <span>This is a different person. Create a new account.</span>
                        </label>
                    </div>
                <?php endif; ?>
                <div class="mt-5 max-w-md">
                    <?= component('field', ['name' => 'email', 'label' => 'Email (used to sign in)', 'type' => 'email', 'required' => true, 'value' => old('email', ''),
                        'help' => 'The applicant sets their own password through a link, so use an address they can open.']) ?>
                </div>
            </fieldset>

            <fieldset class="card p-5 sm:p-6">
                <legend class="float-left mb-4 w-full font-display text-base font-bold text-brand-navy">Personal information</legend>
                <div class="clear-both grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <?= component('field', ['name' => 'birthdate', 'label' => 'Date of birth', 'type' => 'date', 'required' => true, 'value' => old('birthdate', '')]) ?>
                    <?= $select('sex', 'Sex', $sexes) ?>
                    <?= $select('civil_status', 'Civil status', $civilStatus) ?>
                    <?= component('field', ['name' => 'nationality', 'label' => 'Nationality', 'required' => true, 'value' => old('nationality', 'Filipino')]) ?>
                    <?= component('field', ['name' => 'religion', 'label' => 'Religion', 'value' => old('religion', '')]) ?>
                </div>
            </fieldset>

            <fieldset class="card p-5 sm:p-6">
                <legend class="float-left mb-4 w-full font-display text-base font-bold text-brand-navy">Contact and address</legend>
                <div class="clear-both grid gap-5 sm:grid-cols-2">
                    <?= component('field', ['name' => 'mobile', 'label' => 'Mobile number', 'type' => 'tel', 'required' => true, 'value' => old('mobile', ''), 'help' => 'Example: 09171234567']) ?>
                    <?= component('field', ['name' => 'telephone', 'label' => 'Telephone', 'type' => 'tel', 'value' => old('telephone', '')]) ?>
                    <div class="sm:col-span-2">
                        <?= component('field', ['name' => 'street', 'label' => 'House no. and street', 'value' => old('street', '')]) ?>
                    </div>
                    <?= component('field', ['name' => 'barangay', 'label' => 'Barangay', 'required' => true, 'value' => old('barangay', '')]) ?>
                    <?= component('field', ['name' => 'city_municipality', 'label' => 'City or municipality', 'required' => true, 'value' => old('city_municipality', '')]) ?>
                    <?= component('field', ['name' => 'province', 'label' => 'Province', 'required' => true, 'value' => old('province', '')]) ?>
                    <?= component('field', ['name' => 'postal_code', 'label' => 'Postal code', 'value' => old('postal_code', '')]) ?>
                </div>
            </fieldset>

            <fieldset class="card p-5 sm:p-6">
                <legend class="float-left mb-1 w-full font-display text-base font-bold text-brand-navy">Emergency contact</legend>
                <p class="clear-both mb-4 text-sm text-slate-600">A parent or guardian. More contacts can be added later from the student's profile.</p>
                <?php if ($guardianError = error_for('guardians')): ?>
                    <p class="field-error mb-4" role="alert"><span><?= e($guardianError) ?></span></p>
                <?php endif; ?>
                <input type="hidden" name="<?= e($guardianField('is_emergency_contact')) ?>" value="1">
                <div class="grid gap-5 sm:grid-cols-2">
                    <?php foreach ([['full_name', 'Full name', 'text'], ['relationship', 'Relationship', 'text'], ['mobile', 'Mobile number', 'tel'], ['email', 'Email', 'email']] as [$name, $label, $type]): ?>
                        <?php $error = error_for("guardians.0.{$name}"); ?>
                        <div>
                            <label for="guardian-0-<?= e($name) ?>" class="field-label"><?= e($label) ?><?php if ($name !== 'email'): ?> <span class="text-danger-text" aria-hidden="true">*</span><span class="sr-only">(required)</span><?php endif; ?></label>
                            <input type="<?= e($type) ?>" id="guardian-0-<?= e($name) ?>" name="<?= e($guardianField($name)) ?>" value="<?= e($guardianValue($name)) ?>"
                                   class="field-input <?= $error ? 'field-input-error' : '' ?>"
                                   <?= $error ? 'aria-invalid="true" aria-describedby="guardian-0-' . e($name) . '-error"' : '' ?>>
                            <?php if ($error): ?><p id="guardian-0-<?= e($name) ?>-error" class="field-error"><span><?= e($error) ?></span></p><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <fieldset class="card space-y-5 p-5 sm:p-6">
                <legend class="float-left mb-1 w-full font-display text-base font-bold text-brand-navy">Enrollment</legend>
                <div class="clear-both"><?= $select('term_id', 'Term', $termOptions, true) ?></div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <?= $select('student_type', 'Student type', $typeOptions, true) ?>
                    <?= $select('year_level_id', 'Year level', $yearOptions, true) ?>
                </div>
                <?= $select('program_id', 'Program', $programOptions, true) ?>
                <div class="space-y-5 rounded-xl border border-slate-200 bg-slate-50 p-4" data-transferee-fields>
                    <p class="text-sm font-medium text-slate-700">For transferees</p>
                    <?= component('field', ['name' => 'previous_school', 'label' => 'School transferring from', 'value' => old('previous_school', ''), 'help' => 'Required for transferees.']) ?>
                    <?= component('field', ['name' => 'last_school_year', 'label' => 'Last school year attended', 'value' => old('last_school_year', ''), 'help' => 'Example: 2025-2026']) ?>
                </div>
            </fieldset>

            <div class="card p-5 sm:p-6">
                <label class="flex items-start gap-3 text-sm text-slate-700">
                    <input type="checkbox" name="privacy_consent" value="1" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-600" <?= old('privacy_consent') ? 'checked' : '' ?>>
                    <span>The applicant has been told how the school uses their personal information and agrees to the data privacy notice (RA 10173). <span class="text-danger-text" aria-hidden="true">*</span><span class="sr-only">(required)</span></span>
                </label>
                <?php if ($consentError = error_for('privacy_consent')): ?>
                    <p class="field-error mt-2" role="alert"><span><?= e($consentError) ?></span></p>
                <?php endif; ?>
            </div>

            <div class="flex flex-wrap gap-3">
                <button type="submit" class="btn-primary btn-lg" data-busy-label="Creating…">Create account and draft</button>
                <a href="<?= e(url('/registrar/applications')) ?>" class="btn-secondary btn-lg">Cancel</a>
            </div>
        </form>
    <?php endif; ?>
</div>
