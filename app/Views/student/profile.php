<?php
/**
 * Student profile (docs/08).
 *
 * @var App\Models\User $user
 * @var array $profile
 * @var array $guardians
 * @var bool  $nameLocked
 * @var array $missing
 * @var array $sexes
 * @var array $civilStatus
 */
$value = static fn (string $field) => old($field, $profile[$field] ?? '');

$oldGuardians = old('guardians', null);
$guardianRows = is_array($oldGuardians) ? array_values($oldGuardians) : $guardians;
if ($guardianRows === []) {
    $guardianRows = [['full_name' => '', 'relationship' => '', 'mobile' => '', 'email' => '', 'address' => '', 'is_emergency_contact' => 1]];
}

/** Select control sharing the field component's look and error wiring. */
$select = static function (string $name, string $label, array $options, bool $required = true) use ($value): string {
    $error = error_for($name);
    $current = (string) $value($name);
    $html = '<div><label for="field-' . e($name) . '" class="field-label">' . e($label)
        . ($required ? ' <span class="text-danger-text" aria-hidden="true">*</span><span class="sr-only">(required)</span>' : '')
        . '</label><select id="field-' . e($name) . '" name="' . e($name) . '" class="field-input ' . ($error ? 'field-input-error' : '') . '"'
        . ($required ? ' required' : '') . ($error ? ' aria-invalid="true" aria-describedby="field-' . e($name) . '-error"' : '') . '>'
        . '<option value="">Select…</option>';
    foreach ($options as $option) {
        $html .= '<option value="' . e($option) . '"' . ($current === $option ? ' selected' : '') . '>' . e($option) . '</option>';
    }
    $html .= '</select>';
    if ($error) {
        $html .= '<p id="field-' . e($name) . '-error" class="field-error"><span>' . e($error) . '</span></p>';
    }
    return $html . '</div>';
};
?>
<div class="mx-auto max-w-4xl space-y-6">
    <div>
        <p class="eyebrow">Student</p>
        <h2 class="mt-1 text-2xl">My profile</h2>
        <p class="mt-1 text-sm text-slate-600">
            The registrar reviews these details with your application. Fields marked <span class="text-danger-text">*</span> are required.
        </p>
    </div>

    <?php if ($missing !== []): ?>
        <div class="rounded-xl border border-warning-line bg-warning-bg px-5 py-4 text-sm text-warning-text" role="note">
            <p class="font-semibold">Your profile is not complete yet.</p>
            <p class="mt-1">Still needed before you can submit an application: <?= e(implode(', ', $missing)) ?>.</p>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?= e(url('/student/profile')) ?>" enctype="multipart/form-data" class="space-y-6" novalidate>
        <?= csrf_field() ?>

        <?= partial('student/photo-field', [
            'photoUrl' => trim((string) ($profile['photo_path'] ?? '')) !== ''
                ? url('/student/photo') . '?v=' . urlencode((string) ($profile['updated_at'] ?? ''))
                : null,
            'maxBytes' => App\Services\StudentProfileService::PHOTO_MAX_BYTES,
        ]) ?>

        <fieldset class="card p-5 sm:p-6">
            <legend class="float-left mb-4 w-full font-display text-base font-bold text-brand-navy">Name</legend>
            <?php if ($nameLocked): ?>
                <p class="mb-4 clear-both rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">
                    Your name is locked because an application is with the registrar. Ask the registrar's office to correct it.
                </p>
                <p class="clear-both text-base font-medium text-slate-800"><?= e($user->fullName()) ?></p>
            <?php else: ?>
                <div class="clear-both grid gap-5 sm:grid-cols-3">
                    <?= component('field', ['name' => 'first_name', 'label' => 'First name', 'required' => true, 'value' => old('first_name', $user->first_name), 'autocomplete' => 'given-name']) ?>
                    <?= component('field', ['name' => 'middle_name', 'label' => 'Middle name', 'value' => old('middle_name', $user->middle_name ?? ''), 'autocomplete' => 'additional-name']) ?>
                    <?= component('field', ['name' => 'last_name', 'label' => 'Last name', 'required' => true, 'value' => old('last_name', $user->last_name), 'autocomplete' => 'family-name']) ?>
                </div>
            <?php endif; ?>
        </fieldset>

        <fieldset class="card p-5 sm:p-6">
            <legend class="float-left mb-4 w-full font-display text-base font-bold text-brand-navy">Personal information</legend>
            <div class="clear-both grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <?= component('field', ['name' => 'birthdate', 'label' => 'Date of birth', 'type' => 'date', 'required' => true, 'value' => $value('birthdate'), 'autocomplete' => 'bday']) ?>
                <?= $select('sex', 'Sex', $sexes) ?>
                <?= $select('civil_status', 'Civil status', $civilStatus) ?>
                <?= component('field', ['name' => 'nationality', 'label' => 'Nationality', 'required' => true, 'value' => $value('nationality') ?: 'Filipino']) ?>
                <?= component('field', ['name' => 'religion', 'label' => 'Religion', 'value' => $value('religion')]) ?>
            </div>
            <p data-civil-note="Married" class="mt-3 rounded-lg border border-warning-line bg-warning-bg px-4 py-3 text-sm text-warning-text" role="note" <?= (string) old('civil_status', $profile['civil_status'] ?? '') === 'Married' ? '' : 'hidden' ?>>You will need to upload your <strong>marriage certificate</strong> (PSA) with your enrollment application.</p>
        </fieldset>

        <fieldset class="card p-5 sm:p-6">
            <legend class="float-left mb-4 w-full font-display text-base font-bold text-brand-navy">Contact and address</legend>
            <div class="clear-both grid gap-5 sm:grid-cols-2">
                <?= component('field', ['name' => 'mobile', 'label' => 'Mobile number', 'type' => 'tel', 'required' => true, 'value' => $value('mobile'), 'autocomplete' => 'tel', 'help' => 'Example: 09171234567']) ?>
                <?= component('field', ['name' => 'telephone', 'label' => 'Telephone', 'type' => 'tel', 'value' => $value('telephone')]) ?>
                <div class="sm:col-span-2">
                    <?= component('field', ['name' => 'street', 'label' => 'House no. and street', 'value' => $value('street'), 'autocomplete' => 'address-line1']) ?>
                </div>
                <?= component('field', ['name' => 'barangay', 'label' => 'Barangay', 'required' => true, 'value' => $value('barangay')]) ?>
                <?= component('field', ['name' => 'city_municipality', 'label' => 'City or municipality', 'required' => true, 'value' => $value('city_municipality'), 'autocomplete' => 'address-level2']) ?>
                <?= component('field', ['name' => 'province', 'label' => 'Province', 'required' => true, 'value' => $value('province'), 'autocomplete' => 'address-level1']) ?>
                <?= component('field', ['name' => 'postal_code', 'label' => 'Postal code', 'value' => $value('postal_code'), 'autocomplete' => 'postal-code']) ?>
            </div>
        </fieldset>

        <fieldset class="card p-5 sm:p-6" data-repeater>
            <legend class="float-left w-full font-display text-base font-bold text-brand-navy">Guardians and emergency contact</legend>
            <p class="clear-both mb-4 pt-1 text-sm text-slate-600">Add at least one, and mark who to contact in an emergency.</p>

            <?php if ($guardianError = error_for('guardians')): ?>
                <p class="field-error mb-4" role="alert"><span><?= e($guardianError) ?></span></p>
            <?php endif; ?>

            <div class="space-y-4" data-repeater-list>
                <?php foreach ($guardianRows as $index => $guardian): ?>
                    <?= partial('student/guardian-row', ['index' => $index, 'guardian' => $guardian]) ?>
                <?php endforeach; ?>
            </div>

            <template data-repeater-template>
                <?= partial('student/guardian-row', ['index' => '__INDEX__', 'guardian' => []]) ?>
            </template>

            <button type="button" class="btn-secondary mt-4" data-repeater-add>Add another guardian</button>
        </fieldset>

        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" class="btn-primary btn-lg">Save profile</button>
            <a href="<?= e(url('/student/dashboard')) ?>" class="btn-secondary btn-lg">Cancel</a>
        </div>
    </form>
</div>
