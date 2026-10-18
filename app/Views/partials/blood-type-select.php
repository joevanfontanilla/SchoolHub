<?php
/**
 * An optional blood type, printed on the e-ID card (docs/29). Shared by the profile, the registrar's record, and the walk-in form.
 *
 * @var string $current the chosen value
 */
use App\Services\StudentProfileService;

$error = error_for('blood_type');
?>
<div>
    <label for="field-blood_type" class="field-label">Blood type <span class="font-normal text-brand-gray">(optional, printed on your ID)</span></label>
    <select id="field-blood_type" name="blood_type" class="field-input <?= $error ? 'field-input-error' : '' ?>">
        <option value="">Not stated</option>
        <?php foreach (StudentProfileService::BLOOD_TYPES as $type): ?>
            <option value="<?= e($type) ?>" <?= (string) $current === $type ? 'selected' : '' ?>><?= e($type) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($error): ?><p class="field-error"><span><?= e($error) ?></span></p><?php endif; ?>
</div>