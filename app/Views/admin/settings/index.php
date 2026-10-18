<?php
/**
 * System settings (docs/20), one group at a time.
 *
 * @var string $group
 * @var array  $groups  SettingsService::GROUPS
 * @var array  $values  key => stored value
 * @var bool   $hasLogo
 * @var ?string $presidentSignatureUri the President's signature as a data URI, or null
 * @var ?string $registrarSignatureUri the Registrar's signature as a data URI, or null
 */
use App\Services\SettingsService;

[$groupTitle, $groupDescription, $fields] = $groups[$group];
$failed = errors() !== [];
$value = static fn (string $key) => (string) ($failed ? old($key, $values[$key] ?? '') : ($values[$key] ?? ''));
$enumLabels = [
    'MINIMUM_DOWNPAYMENT' => 'Down payment (minimum percentage)',
    'FULL'                => 'Full payment',
    'NONE'                => 'No payment needed',
];
?>
<div class="space-y-6">
    <div>
        <p class="eyebrow">Administration</p>
        <h2 class="mt-1 text-2xl">System Settings</h2>
        <p class="mt-1 text-sm text-slate-600">Every change is recorded in the audit log with its old and new value.</p>
    </div>

    <div class="grid gap-6 lg:grid-cols-4">
        <nav aria-label="Settings groups" class="card h-max p-2 lg:sticky lg:top-24">
            <?php foreach ($groups as $key => [$title]): ?>
                <a href="<?= e(url('/admin/settings?group=' . $key)) ?>" <?= $key === $group ? 'aria-current="page"' : '' ?>
                   class="block rounded-lg px-3 py-2 text-sm font-semibold <?= $key === $group ? 'bg-brand-600 text-white' : 'text-brand-navy hover:bg-brand-50' ?>"><?= e($title) ?></a>
            <?php endforeach; ?>
        </nav>

        <form method="POST" action="<?= e(url('/admin/settings/' . $group)) ?>" <?= $group === 'school' ? 'enctype="multipart/form-data"' : '' ?> class="card space-y-5 p-5 sm:p-6 lg:col-span-3" novalidate
              data-confirm="The new settings apply across the portal right away, and the change is recorded in the audit log." data-confirm-title="Save <?= e(strtolower($groupTitle)) ?>?" data-confirm-tone="warning">
            <div>
                <h3 class="text-lg"><?= e($groupTitle) ?></h3>
                <p class="text-sm text-slate-600"><?= e($groupDescription) ?></p>
            </div>

            <?php foreach ($fields as $key => [$label, $rule, $help]): ?>
                <?php [$type, $arg] = array_pad(explode(':', $rule, 2), 2, ''); $error = error_for($key); ?>
                <?php if ($type === 'bool'): ?>
                    <label class="flex items-start gap-2 text-sm text-slate-700">
                        <input type="checkbox" name="<?= e($key) ?>" value="1" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-600" <?= in_array(strtolower($value($key)), ['1', 'true', 'on', 'yes'], true) ? 'checked' : '' ?>>
                        <span><strong><?= e($label) ?></strong><?= $help ? '<span class="block text-xs text-brand-gray">' . e($help) . '</span>' : '' ?></span>
                    </label>
                <?php elseif ($type === 'enum'): ?>
                    <div>
                        <label for="field-<?= e($key) ?>" class="field-label"><?= e($label) ?></label>
                        <select id="field-<?= e($key) ?>" name="<?= e($key) ?>" class="field-input">
                            <?php foreach (explode(',', $arg) as $option): ?>
                                <option value="<?= e($option) ?>" <?= $value($key) === $option ? 'selected' : '' ?>><?= e($enumLabels[$option] ?? $option) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($help): ?><p class="mt-1.5 text-xs text-brand-gray"><?= e($help) ?></p><?php endif; ?>
                        <?php if ($error): ?><p class="field-error"><span><?= e($error) ?></span></p><?php endif; ?>
                    </div>
                <?php elseif ($type === 'textarea'): ?>
                    <div>
                        <label for="field-<?= e($key) ?>" class="field-label"><?= e($label) ?></label>
                        <textarea id="field-<?= e($key) ?>" name="<?= e($key) ?>" rows="5" class="field-input <?= $error ? 'field-input-error' : '' ?>"><?= e($value($key)) ?></textarea>
                        <?php if ($help): ?><p class="mt-1.5 text-xs text-brand-gray"><?= e($help) ?></p><?php endif; ?>
                        <?php if ($error): ?><p class="field-error"><span><?= e($error) ?></span></p><?php endif; ?>
                    </div>
                <?php else: ?>
                    <?= component('field', ['name' => $key, 'label' => $label, 'type' => $type === 'int' ? 'number' : ($type === 'email' ? 'email' : 'text'), 'value' => $value($key), 'help' => $help]) ?>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if ($group === 'school'): ?>
                <fieldset class="rounded-xl border border-brand-100 p-4">
                    <legend class="px-1 font-display text-sm font-bold text-brand-navy">Logo</legend>
                    <div class="flex flex-wrap items-center gap-4" data-image-field>
                        <img data-image-preview-target src="<?= e(school_logo_url()) ?>" alt="Current school logo" class="h-20 w-20 rounded-full object-cover ring-2 ring-brand-100">
                        <div class="min-w-0 flex-1 space-y-2">
                            <label for="field-logo" class="field-label">Upload a new logo</label>
                            <input id="field-logo" type="file" name="logo" accept=".jpg,.jpeg,.png,image/jpeg,image/png" data-max-bytes="<?= SettingsService::LOGO_MAX_BYTES ?>"
                                   class="block w-full text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:font-semibold file:text-brand-700 hover:file:bg-brand-100" aria-describedby="field-logo-help">
                            <p id="field-logo-help" class="text-xs text-brand-gray">Square JPG or PNG, up to 1 MB. Used on every page and printout.</p>
                            <?php if ($hasLogo): ?>
                                <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="remove_logo" value="1" class="h-4 w-4 rounded border-slate-300 text-brand-600"> Go back to the built-in seal</label>
                            <?php endif; ?>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="rounded-xl border border-brand-100 p-4">
                    <legend class="px-1 font-display text-sm font-bold text-brand-navy">President's signature</legend>
                    <div class="flex flex-wrap items-center gap-4" data-image-field>
                        <?php if ($presidentSignatureUri !== null): ?>
                            <img data-image-preview-target src="<?= e($presidentSignatureUri) ?>" alt="Current signature of the President" class="h-16 max-w-[10rem] rounded bg-white object-contain ring-2 ring-brand-100">
                        <?php else: ?>
                            <img data-image-preview-target src="" alt="" class="h-16 max-w-[10rem] rounded bg-white object-contain ring-2 ring-brand-100" hidden>
                        <?php endif; ?>
                        <div class="min-w-0 flex-1 space-y-2">
                            <label for="field-president-signature" class="field-label">Upload the signature</label>
                            <input id="field-president-signature" type="file" name="president_signature" accept=".jpg,.jpeg,.png,image/jpeg,image/png" data-max-bytes="<?= SettingsService::LOGO_MAX_BYTES ?>"
                                   class="block w-full text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:font-semibold file:text-brand-700 hover:file:bg-brand-100" aria-describedby="field-president-signature-help">
                            <p id="field-president-signature-help" class="text-xs text-brand-gray">Optional. A PNG or JPG of the signature on a plain background, up to 1 MB. Printed on the back of every e-ID card; without it only the name and title print.</p>
                            <?php if ($presidentSignatureUri !== null): ?>
                                <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="remove_president_signature" value="1" class="h-4 w-4 rounded border-slate-300 text-brand-600"> Remove the signature</label>
                            <?php endif; ?>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="rounded-xl border border-brand-100 p-4">
                    <legend class="px-1 font-display text-sm font-bold text-brand-navy">Registrar's signature</legend>
                    <div class="flex flex-wrap items-center gap-4" data-image-field>
                        <?php if ($registrarSignatureUri !== null): ?>
                            <img data-image-preview-target src="<?= e($registrarSignatureUri) ?>" alt="Current signature of the Registrar" class="h-16 max-w-[10rem] rounded bg-white object-contain ring-2 ring-brand-100">
                        <?php else: ?>
                            <img data-image-preview-target src="" alt="" class="h-16 max-w-[10rem] rounded bg-white object-contain ring-2 ring-brand-100" hidden>
                        <?php endif; ?>
                        <div class="min-w-0 flex-1 space-y-2">
                            <label for="field-registrar-signature" class="field-label">Upload the signature</label>
                            <input id="field-registrar-signature" type="file" name="registrar_signature" accept=".jpg,.jpeg,.png,image/jpeg,image/png" data-max-bytes="<?= SettingsService::LOGO_MAX_BYTES ?>"
                                   class="block w-full text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:font-semibold file:text-brand-700 hover:file:bg-brand-100" aria-describedby="field-registrar-signature-help">
                            <p id="field-registrar-signature-help" class="text-xs text-brand-gray">Optional. A PNG or JPG of the signature on a plain background, up to 1 MB. Printed above the Registrar's name on the Certificate of Registration and the other forms the registrar signs; without it only the name prints.</p>
                            <?php if ($registrarSignatureUri !== null): ?>
                                <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="remove_registrar_signature" value="1" class="h-4 w-4 rounded border-slate-300 text-brand-600"> Remove the signature</label>
                            <?php endif; ?>
                        </div>
                    </div>
                </fieldset>
            <?php endif; ?>

            <div class="flex gap-3 border-t border-brand-100 pt-5">
                <button type="submit" class="btn-primary">Save <?= e(strtolower($groupTitle)) ?></button>
            </div>
            <?= csrf_field() ?>
        </form>
    </div>
</div>
