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
$hasErrors = errors() !== [];
?>
<div class="mx-auto max-w-5xl space-y-6">
    <a href="<?= e(url('/registrar/students')) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; Students</a>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="eyebrow"><?= e($student['student_number'] ?? 'No student number yet') ?></p>
            <h2 class="mt-1 text-2xl"><?= e(trim($student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name'])) ?></h2>
            <p class="mt-1 text-sm text-slate-600">
                <?= e($student['email']) ?> · <?= e($student['status']) ?>
                <?= $student['program_code'] ? ' · ' . e($student['program_code'] . ', ' . $student['year_level_name']) : '' ?>
            </p>
        </div>
        <span class="badge <?= $missing === [] ? 'bg-success-bg text-success-text' : 'bg-warning-bg text-warning-text' ?>">
            <?= $missing === [] ? 'Profile complete' : 'Profile incomplete' ?>
        </span>
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

    <details id="edit" class="card group" <?= $hasErrors ? 'open' : '' ?>>
        <summary class="flex cursor-pointer items-center justify-between px-5 py-4 font-display text-base font-bold text-brand-navy">
            Correct this record
            <span class="text-sm font-semibold text-brand-600 group-open:hidden">Show</span>
        </summary>
        <form method="POST" action="<?= e(url("/registrar/students/{$id}")) ?>" class="space-y-6 border-t border-slate-200 px-5 py-5" novalidate
              data-confirm="Save these corrections to the student's record? The change is recorded in the audit log.">
            <?= csrf_field() ?>
            <p class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">
                Corrections are recorded in the audit log. Unlike the student, you can correct the legal name after an application is submitted.
            </p>

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
