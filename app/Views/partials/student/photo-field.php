<?php
/**
 * The profile-photo upload control, shared by the student's own profile and the registrar's
 * record-correction form. The form that includes it must be enctype="multipart/form-data".
 *
 * @var ?string $photoUrl  the current photo URL (cache-busted), or null when there is none
 * @var int     $maxBytes  the size limit, for the client-side hint
 */
$photoUrl ??= null;
$photoError = error_for('photo');
?>
<fieldset class="card p-5 sm:p-6">
    <legend class="float-left mb-4 w-full font-display text-base font-bold text-brand-navy">Photo</legend>
    <div class="clear-both flex flex-col gap-4 sm:flex-row sm:items-start">
        <?= component('avatar', ['src' => $photoUrl, 'alt' => 'Current profile photo', 'size' => 'h-24 w-24', 'icon' => 'h-10 w-10']) ?>
        <div class="min-w-0 flex-1">
            <label for="field-photo" class="field-label">Upload a photo</label>
            <input id="field-photo" type="file" name="photo" accept=".jpg,.jpeg,.png,image/jpeg,image/png"
                   data-max-bytes="<?= (int) $maxBytes ?>"
                   class="block w-full text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:font-semibold file:text-brand-700 hover:file:bg-brand-100 <?= $photoError ? 'rounded-lg ring-1 ring-danger-line' : '' ?>"
                   <?= $photoError ? 'aria-invalid="true" aria-describedby="field-photo-error"' : '' ?>>
            <p class="mt-1.5 text-xs text-brand-gray">Optional. A head-and-shoulders photo, JPG or PNG, up to 2 MB.</p>
            <?php if ($photoError): ?><p id="field-photo-error" class="field-error"><span><?= e($photoError) ?></span></p><?php endif; ?>
            <?php if ($photoUrl !== null): ?>
                <label class="mt-3 flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" name="remove_photo" value="1" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-600"> Remove the current photo
                </label>
            <?php endif; ?>
        </div>
    </div>
</fieldset>
