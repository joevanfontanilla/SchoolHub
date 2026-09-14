<?php
/**
 * The documents of one application, each with its status, the registrar's
 * remarks, the current file, and an upload control while uploads are unlocked.
 *
 * Rendered as a list of cards rather than a table so it stays readable on a
 * phone, where most students will upload from.
 *
 * @var array $application
 * @var array $requirements
 * @var bool  $canUpload
 * @var string $returnTo 'application' or 'requirements'
 */
use App\Models\EnrollmentApplication as Application;
use App\Models\RequirementType;

$returnTo ??= 'application';
$requiredTotal = count(array_filter($requirements, static fn ($r) => (int) $r['is_required'] === 1));
$requiredDone  = count(array_filter($requirements, static fn ($r) => (int) $r['is_required'] === 1
    && in_array($r['status'], ['SUBMITTED', 'UNDER_REVIEW', 'VERIFIED'], true)));
?>
<section class="card" aria-labelledby="requirements-heading">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
        <div>
            <h2 id="requirements-heading" class="text-base">Requirements</h2>
            <p class="mt-0.5 text-sm text-brand-gray">
                <?= e($requiredDone) ?> of <?= e($requiredTotal) ?> required documents uploaded
            </p>
        </div>
        <div class="h-2 w-40 overflow-hidden rounded-full bg-slate-100" role="progressbar"
             aria-label="Required documents uploaded" aria-valuemin="0" aria-valuemax="<?= e($requiredTotal) ?>" aria-valuenow="<?= e($requiredDone) ?>">
            <div class="h-full rounded-full bg-brand-600" style="width: <?= $requiredTotal > 0 ? round($requiredDone / $requiredTotal * 100) : 0 ?>%"></div>
        </div>
    </div>

    <?php if (!$canUpload): ?>
        <p class="border-b border-slate-200 bg-slate-50 px-5 py-3 text-sm text-slate-600">
            Documents are locked while your application is <?= e(strtolower(Application::label($application['application_status']))) ?>.
        </p>
    <?php endif; ?>

    <ul class="divide-y divide-slate-100">
        <?php foreach ($requirements as $requirement): ?>
            <?php
            $extensions = RequirementType::extensions($requirement);
            $accept = implode(',', array_map(static fn ($ext) => '.' . $ext, $extensions));
            $maxBytes = (int) $requirement['max_file_size_kb'] * 1024;
            $showRemarks = in_array($requirement['status'], ['REJECTED', 'CORRECTION_REQUIRED'], true) && !empty($requirement['remarks']);
            $uploadAllowed = $canUpload && $requirement['status'] !== 'VERIFIED';
            $inputId = 'document-' . $requirement['id'];
            ?>
            <li id="requirement-<?= e($requirement['id']) ?>" class="scroll-mt-24 px-5 py-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="text-sm">
                            <?= e($requirement['name']) ?>
                            <?php if ((int) $requirement['is_required'] === 1): ?>
                                <span class="ml-1 font-sans text-xs font-medium text-danger-text">Required</span>
                            <?php else: ?>
                                <span class="ml-1 font-sans text-xs font-medium text-brand-gray">Optional</span>
                            <?php endif; ?>
                        </h3>
                        <?php if (!empty($requirement['description'])): ?>
                            <p class="mt-1 text-sm text-brand-gray"><?= e($requirement['description']) ?></p>
                        <?php endif; ?>
                    </div>
                    <?= component('status-badge', ['status' => $requirement['status']]) ?>
                </div>

                <?php if ($showRemarks): ?>
                    <p class="mt-3 rounded-lg border border-warning-line bg-warning-bg px-3 py-2 text-sm text-warning-text">
                        <span class="font-semibold">Registrar's note:</span> <?= e($requirement['remarks']) ?>
                    </p>
                <?php endif; ?>

                <?php if (!empty($requirement['file_id'])): ?>
                    <p class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                        <svg class="h-4 w-4 text-brand-gray" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>
                        </svg>
                        <a href="<?= e(url('/documents/' . $requirement['file_id'])) ?>" class="font-medium text-brand-600 hover:underline">
                            <?= e($requirement['original_filename']) ?>
                        </a>
                        <span class="text-brand-gray">
                            <?= e(App\Models\Download::humanSize((int) $requirement['file_size'])) ?> · uploaded <?= e(dt($requirement['uploaded_at'])) ?>
                        </span>
                    </p>
                <?php endif; ?>

                <?php if ($uploadAllowed): ?>
                    <form method="POST"
                          action="<?= e(url('/student/enrollment/' . $application['id'] . '/requirements/' . $requirement['id'] . '/upload')) ?>"
                          enctype="multipart/form-data"
                          class="mt-3 flex flex-wrap items-center gap-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
                        <label for="<?= e($inputId) ?>" class="sr-only">
                            <?= e((!empty($requirement['file_id']) ? 'Replace ' : 'Upload ') . $requirement['name']) ?>
                        </label>
                        <input type="file"
                               id="<?= e($inputId) ?>"
                               name="document"
                               accept="<?= e($accept) ?>"
                               required
                               data-max-bytes="<?= e($maxBytes) ?>"
                               aria-describedby="<?= e($inputId) ?>-help"
                               class="block w-full max-w-xs text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:font-display file:text-sm file:font-semibold file:text-brand-700 hover:file:bg-brand-100">
                        <button type="submit" class="btn-secondary">
                            <?= !empty($requirement['file_id']) ? 'Replace file' : 'Upload' ?>
                        </button>
                        <p id="<?= e($inputId) ?>-help" class="w-full text-xs text-brand-gray">
                            <?= e(strtoupper(implode(', ', array_unique(array_map(static fn ($x) => $x === 'jpeg' ? 'jpg' : $x, $extensions))))) ?>,
                            up to <?= e(round((int) $requirement['max_file_size_kb'] / 1024, 1)) ?> MB.
                        </p>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
