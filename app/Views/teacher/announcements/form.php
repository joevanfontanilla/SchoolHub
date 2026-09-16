<?php
/**
 * Write or edit an announcement to the teacher's own classes (docs/16).
 *
 * @var ?array $announcement
 * @var string $state    NEW, DRAFT, SCHEDULED, PUBLISHED, EXPIRED, or ARCHIVED
 * @var array  $chosen   class ids already targeted
 * @var array  $classes  this teacher's active classes
 */
use App\Models\Announcement;

$editing = $announcement !== null;
$failed = errors() !== [];
$val = static fn (string $field, string $default = '') => (string) old($field, (string) ($announcement[$field] ?? $default));
$urgent = $failed ? !empty(old('is_urgent')) : (int) ($announcement['is_urgent'] ?? 0) === 1;
$chosenClasses = array_map('intval', $failed ? (array) old('classes', []) : $chosen);
$localTime = static fn (?string $value) => $value ? date('Y-m-d\TH:i', strtotime($value)) : '';
$sent = $editing && $announcement['notified_at'] !== null;
$archived = $state === 'ARCHIVED';
$formAction = $editing ? '/teacher/announcements/' . $announcement['id'] . '/edit' : '/teacher/announcements/create';
$check = 'h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-600';
?>
<div class="mx-auto max-w-4xl space-y-6">
    <a href="<?= e(url('/teacher/announcements')) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; Class announcements</a>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="eyebrow">Communication</p>
            <h2 class="mt-1 text-2xl"><?= $editing ? e($announcement['title']) : 'New class announcement' ?></h2>
            <?php if ($sent): ?>
                <p class="mt-1 text-sm text-slate-600"><?= e(number_format((int) $announcement['recipient_count'])) ?> student<?= (int) $announcement['recipient_count'] === 1 ? '' : 's' ?> notified <?= e(dt($announcement['notified_at'])) ?></p>
            <?php endif; ?>
        </div>
        <?php if ($editing): ?>
            <div class="flex flex-wrap items-center gap-2">
                <?= component('status-badge', ['status' => $state, 'label' => ucfirst(strtolower($state)), 'size' => 'md']) ?>
                <a href="<?= e(url('/portal/announcements/' . $announcement['id'])) ?>" class="btn-secondary">View as student</a>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($archived): ?>
        <p class="flex items-center gap-2 rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-navy" role="status"><?= icon('info', 'h-5 w-5 shrink-0') ?> This announcement is archived and hidden from your students. Restore it to a draft to edit or post it again.</p>
    <?php elseif ($sent): ?>
        <p class="flex items-center gap-2 rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-navy" role="status"><?= icon('info', 'h-5 w-5 shrink-0') ?> Your students have already been notified. Changes you save now are shown to them, but nobody is notified again.</p>
    <?php endif; ?>

    <?php if ($classes === [] && !$editing): ?>
        <section class="card"><?= component('empty-state', ['title' => 'No classes assigned', 'message' => 'You can post to your classes once the registrar assigns you one.']) ?></section>
    <?php else: ?>
        <form method="POST" action="<?= e(url($formAction)) ?>" enctype="multipart/form-data" class="card space-y-6 p-5 sm:p-6" novalidate>
            <fieldset class="min-w-0 space-y-5" <?= $archived ? 'disabled' : '' ?> aria-label="Announcement">
                <fieldset class="rounded-xl border border-brand-100 p-4">
                    <legend class="px-1 font-display text-sm font-bold text-brand-navy">Classes <span class="text-danger-text" aria-hidden="true">*</span><span class="sr-only">(required)</span></legend>
                    <p class="mb-3 text-xs text-slate-600">Students enlisted in the chosen classes see the announcement and are notified when it is posted. Students who drop a class stop seeing it.</p>
                    <ul class="grid gap-2 sm:grid-cols-2">
                        <?php foreach ($classes as $class): ?>
                            <li>
                                <label class="flex items-start gap-2 rounded-lg border border-brand-100 p-3 text-sm hover:bg-brand-50/60">
                                    <input type="checkbox" name="classes[]" value="<?= e($class['id']) ?>" class="mt-0.5 <?= $check ?>" <?= in_array((int) $class['id'], $chosenClasses, true) ? 'checked' : '' ?>>
                                    <span><span class="font-semibold text-brand-navy"><?= e($class['code'] . ' · ' . $class['section_code']) ?></span>
                                        <span class="block text-xs text-brand-gray"><?= e($class['title']) ?> · <?= e($class['enlisted_count']) ?> enlisted</span></span>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if (($message = error_for('classes')) !== null): ?><p class="field-error"><span><?= e($message) ?></span></p><?php endif; ?>
                </fieldset>

                <?= component('field', ['name' => 'title', 'label' => 'Title', 'required' => true, 'value' => $val('title')]) ?>

                <div>
                    <label for="field-content" class="field-label">Text <span class="text-danger-text" aria-hidden="true">*</span><span class="sr-only">(required)</span></label>
                    <textarea id="field-content" name="content" rows="8" class="field-input <?= error_for('content') ? 'field-input-error' : '' ?>" aria-describedby="field-content-help"><?= e($val('content')) ?></textarea>
                    <p id="field-content-help" class="mt-1.5 text-xs text-brand-gray">Plain text. Leave a blank line between paragraphs.</p>
                    <?php if (($message = error_for('content')) !== null): ?><p class="field-error"><span><?= e($message) ?></span></p><?php endif; ?>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="field-category" class="field-label">Category <span class="text-danger-text" aria-hidden="true">*</span></label>
                        <select id="field-category" name="category" class="field-input">
                            <?php foreach (Announcement::CATEGORIES as $category): ?>
                                <option value="<?= e($category) ?>" <?= $val('category', 'Academic') === $category ? 'selected' : '' ?>><?= e($category) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <label class="flex items-start gap-2 pt-7 text-sm text-slate-700">
                        <input type="checkbox" name="is_urgent" value="1" class="mt-0.5 <?= $check ?>" <?= $urgent ? 'checked' : '' ?>>
                        <span><strong>Urgent.</strong> Also shown as a banner at the top of your students' portal, for example when a class is cancelled today.</span>
                    </label>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="field-published_at" class="field-label">Post on</label>
                        <input id="field-published_at" type="datetime-local" name="published_at" value="<?= e($failed ? (string) old('published_at') : $localTime($announcement['published_at'] ?? null)) ?>"
                               class="field-input" aria-describedby="field-published_at-help" <?= $sent ? 'disabled' : '' ?>>
                        <p id="field-published_at-help" class="mt-1.5 text-xs text-brand-gray"><?= $sent ? 'Already posted and notified; the date is fixed.' : 'Leave empty to post as soon as you press Post. A later date schedules it.' ?></p>
                        <?php if (($message = error_for('published_at')) !== null): ?><p class="field-error"><span><?= e($message) ?></span></p><?php endif; ?>
                    </div>
                    <div>
                        <label for="field-expires_at" class="field-label">Show until</label>
                        <input id="field-expires_at" type="datetime-local" name="expires_at" value="<?= e($failed ? (string) old('expires_at') : $localTime($announcement['expires_at'] ?? null)) ?>"
                               class="field-input" aria-describedby="field-expires_at-help">
                        <p id="field-expires_at-help" class="mt-1.5 text-xs text-brand-gray">Optional. After this it disappears from your students' lists.</p>
                        <?php if (($message = error_for('expires_at')) !== null): ?><p class="field-error"><span><?= e($message) ?></span></p><?php endif; ?>
                    </div>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div data-image-field>
                        <label for="field-image" class="field-label">Image</label>
                        <input id="field-image" type="file" name="image" accept=".jpg,.jpeg,.png,image/jpeg,image/png" data-max-bytes="<?= App\Services\AnnouncementService::IMAGE_MAX_BYTES ?>"
                               class="block w-full text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:font-semibold file:text-brand-700 hover:file:bg-brand-100">
                        <p class="mt-1 text-xs text-brand-gray">Optional. JPG or PNG, up to 2 MB. The preview updates before you save.</p>
                        <?php $currentImage = $editing && $announcement['image_path'] ? url('/announcement-images/' . $announcement['id']) : null; ?>
                        <div class="mt-3 flex items-start gap-3">
                            <img data-image-preview-target src="<?= e($currentImage ?? '') ?>" alt="Selected image preview" class="h-20 w-32 rounded-lg object-cover ring-1 ring-brand-100" <?= $currentImage === null ? 'hidden' : '' ?>>
                            <?php if ($currentImage !== null): ?>
                                <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="remove_image" value="1" class="<?= $check ?>"> Remove this image</label>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?= component('field', ['name' => 'image_alt', 'label' => 'Image description', 'value' => $val('image_alt'), 'help' => 'What the image shows, for people using screen readers. Required with an image.']) ?>
                </div>
            </fieldset>

            <?php if (!$archived): ?>
                <div class="flex flex-wrap gap-3 border-t border-brand-100 pt-5">
                    <?php if (!$editing || $state === 'DRAFT'): ?>
                        <button type="submit" name="action" value="publish" class="btn-primary" data-confirm-click="Post this announcement? Your students are notified when it goes live, and that cannot be undone."><?= icon('send', 'h-5 w-5') ?> Post</button>
                        <button type="submit" name="action" value="draft" class="btn-secondary">Save draft</button>
                    <?php else: ?>
                        <button type="submit" name="action" value="save" class="btn-primary">Save changes</button>
                    <?php endif; ?>
                    <a href="<?= e(url('/teacher/announcements')) ?>" class="btn-secondary">Cancel</a>
                </div>
            <?php endif; ?>
            <?= csrf_field() ?>
        </form>
    <?php endif; ?>

    <?php if ($editing): ?>
        <div class="flex flex-wrap gap-3">
            <?php if ($announcement['status'] === Announcement::PUBLISHED): ?>
                <form method="POST" action="<?= e(url('/teacher/announcements/' . $announcement['id'] . '/archive')) ?>" data-confirm="Archive this announcement? Your students no longer see it.">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn-secondary">Archive</button>
                </form>
            <?php elseif ($archived): ?>
                <form method="POST" action="<?= e(url('/teacher/announcements/' . $announcement['id'] . '/restore')) ?>"
                      data-confirm="It becomes a draft you can edit and post again. Nobody is notified a second time." data-confirm-title="Restore as a draft?" data-confirm-tone="primary">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn-primary">Restore as draft</button>
                </form>
            <?php endif; ?>
            <?php if ($announcement['status'] === Announcement::DRAFT && $announcement['published_by'] === null): ?>
                <form method="POST" action="<?= e(url('/teacher/announcements/' . $announcement['id'] . '/delete')) ?>" data-confirm="Delete this draft? This cannot be undone.">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn-danger">Delete draft</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
