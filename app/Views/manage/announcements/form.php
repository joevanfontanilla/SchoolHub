<?php
/**
 * Write or edit an announcement (docs/16).
 *
 * @var ?array  $announcement
 * @var string  $state          NEW, DRAFT, SCHEDULED, PUBLISHED, EXPIRED, or ARCHIVED
 * @var array   $targets        roles, programs, year_levels (ids)
 * @var string  $audienceLabel
 * @var array   $roles
 * @var array   $programs
 * @var array   $yearLevels
 * @var bool    $canPublish
 */
use App\Models\Announcement;
use App\Models\User;

$editing = $announcement !== null;
$failed = errors() !== [];
$val = static fn (string $field, string $default = '') => (string) old($field, (string) ($announcement[$field] ?? $default));
$checked = static fn (string $field) => $failed ? !empty(old($field)) : (int) ($announcement[$field] ?? 0) === 1;
$selected = static fn (string $field, string $key) => array_map('intval', $failed ? (array) old($field, []) : $targets[$key]);
$localTime = static fn (?string $value) => $value ? date('Y-m-d\TH:i', strtotime($value)) : '';
$audience = $val('audience', 'EVERYONE');
$chosenRoles = $selected('roles', 'roles');
$chosenPrograms = $selected('programs', 'programs');
$chosenYears = $selected('year_levels', 'year_levels');
$studentRoleId = (int) (array_values(array_filter($roles, static fn ($r) => $r['name'] === User::ROLE_STUDENT))[0]['id'] ?? 0);
$sent = $editing && $announcement['notified_at'] !== null;
$archived = $state === 'ARCHIVED';
$formAction = $editing ? '/manage/announcements/' . $announcement['id'] . '/edit' : '/manage/announcements/create';
$check = 'h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-600';
$groupError = static fn (string $field) => ($message = error_for($field)) === null ? '' : '<p class="field-error"><span>' . e($message) . '</span></p>';
?>
<div class="mx-auto max-w-4xl space-y-6">
    <a href="<?= e(url('/manage/announcements')) ?>" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 hover:underline">&larr; Announcements</a>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="text-2xl"><?= $editing ? e($announcement['title']) : 'New announcement' ?></h2>
            <?php if ($editing): ?>
                <p class="mt-1 text-sm text-slate-600">
                    By <?= e($announcement['author_name'] ?? 'a former user') ?>
                    <?php if ($announcement['publisher_name']): ?> · published by <?= e($announcement['publisher_name']) ?><?php endif; ?>
                    <?php if ($sent): ?> · <?= e(number_format((int) $announcement['recipient_count'])) ?> notified <?= e(dt($announcement['notified_at'])) ?><?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
        <?php if ($editing): ?>
            <div class="flex flex-wrap items-center gap-2">
                <?= component('status-badge', ['status' => $state, 'label' => ucfirst(strtolower($state)), 'size' => 'md']) ?>
                <a href="<?= e(url('/portal/announcements/' . $announcement['id'])) ?>" class="btn-secondary">View as reader</a>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($archived): ?>
        <p class="flex items-center gap-2 rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-navy" role="status"><?= icon('info', 'h-5 w-5 shrink-0') ?> This announcement is archived and hidden everywhere. Restore it to a draft to edit or publish it again.</p>
    <?php elseif ($sent): ?>
        <p class="flex items-center gap-2 rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-navy" role="status"><?= icon('info', 'h-5 w-5 shrink-0') ?> Its audience has already been notified. Changes you save now are shown to readers, but nobody is notified again.</p>
    <?php endif; ?>

    <form method="POST" action="<?= e(url($formAction)) ?>" enctype="multipart/form-data" class="card space-y-6 p-5 sm:p-6" novalidate data-announcement-form>
        <fieldset class="min-w-0 space-y-5" <?= $archived ? 'disabled' : '' ?> aria-label="Announcement">
            <?= component('field', ['name' => 'title', 'label' => 'Title', 'required' => true, 'value' => $val('title')]) ?>

            <div>
                <label for="field-content" class="field-label">Text <span class="text-danger-text" aria-hidden="true">*</span><span class="sr-only">(required)</span></label>
                <textarea id="field-content" name="content" rows="9" class="field-input <?= error_for('content') ? 'field-input-error' : '' ?>" aria-describedby="field-content-help"><?= e($val('content')) ?></textarea>
                <p id="field-content-help" class="mt-1.5 text-xs text-brand-gray">Plain text. Leave a blank line between paragraphs.</p>
                <?= $groupError('content') ?>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="field-category" class="field-label">Category <span class="text-danger-text" aria-hidden="true">*</span></label>
                    <select id="field-category" name="category" class="field-input">
                        <?php foreach (Announcement::CATEGORIES as $category): ?>
                            <option value="<?= e($category) ?>" <?= $val('category', 'General') === $category ? 'selected' : '' ?>><?= e($category) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= $groupError('category') ?>
                </div>
                <div class="space-y-2 pt-1">
                    <label class="flex items-start gap-2 text-sm text-slate-700">
                        <input type="checkbox" name="is_urgent" value="1" class="mt-0.5 <?= $check ?>" <?= $checked('is_urgent') ? 'checked' : '' ?>>
                        <span><strong>Urgent.</strong> Also shown as a banner at the top of the portal until it ends or the reader dismisses it.</span>
                    </label>
                    <label class="flex items-start gap-2 text-sm text-slate-700">
                        <input type="checkbox" name="is_public" value="1" class="mt-0.5 <?= $check ?>" <?= $checked('is_public') ? 'checked' : '' ?>>
                        <span><strong>Public website.</strong> Anyone can read it on the school website. Only for announcements to everyone.</span>
                    </label>
                    <?= $groupError('is_public') ?>
                </div>
            </div>

            <fieldset class="rounded-xl border border-brand-100 p-4">
                <legend class="px-1 font-display text-sm font-bold text-brand-navy">Who should see it</legend>
                <div class="flex flex-wrap gap-5 text-sm">
                    <label class="inline-flex items-center gap-2"><input type="radio" name="audience" value="EVERYONE" <?= $audience === 'EVERYONE' ? 'checked' : '' ?> data-audience> Everyone with an account</label>
                    <label class="inline-flex items-center gap-2"><input type="radio" name="audience" value="TARGETED" <?= $audience === 'TARGETED' ? 'checked' : '' ?> data-audience> Chosen groups</label>
                </div>
                <?= $groupError('audience') ?>

                <div class="mt-4 space-y-4" data-targets>
                    <div>
                        <p class="field-label">Groups</p>
                        <div class="flex flex-wrap gap-x-5 gap-y-2 text-sm">
                            <?php foreach ($roles as $role): ?>
                                <label class="inline-flex items-center gap-2">
                                    <input type="checkbox" name="roles[]" value="<?= e($role['id']) ?>" class="<?= $check ?>" <?= in_array((int) $role['id'], $chosenRoles, true) ? 'checked' : '' ?> <?= (int) $role['id'] === $studentRoleId ? 'data-student-role' : '' ?>>
                                    <?= e($role['name'] === User::ROLE_SUPER_ADMIN ? 'Super Admins' : $role['name'] . 's') ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <?= $groupError('roles') ?>
                    </div>

                    <div class="space-y-4 rounded-lg bg-brand-50/60 p-3" data-student-narrowing>
                        <p class="text-xs text-slate-600">Optional, for students only: leave both empty to reach every student. Based on the program and year level of each student's latest application.</p>
                        <div>
                            <p class="field-label">Programs</p>
                            <div class="flex flex-wrap gap-x-5 gap-y-2 text-sm">
                                <?php foreach ($programs as $program): ?>
                                    <label class="inline-flex items-center gap-2" title="<?= e($program['name']) ?>">
                                        <input type="checkbox" name="programs[]" value="<?= e($program['id']) ?>" class="<?= $check ?>" <?= in_array((int) $program['id'], $chosenPrograms, true) ? 'checked' : '' ?>> <?= e($program['code']) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <?= $groupError('programs') ?>
                        </div>
                        <div>
                            <p class="field-label">Year levels</p>
                            <div class="flex flex-wrap gap-x-5 gap-y-2 text-sm">
                                <?php foreach ($yearLevels as $year): ?>
                                    <label class="inline-flex items-center gap-2">
                                        <input type="checkbox" name="year_levels[]" value="<?= e($year['id']) ?>" class="<?= $check ?>" <?= in_array((int) $year['id'], $chosenYears, true) ? 'checked' : '' ?>> <?= e($year['name']) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <?= $groupError('year_levels') ?>
                        </div>
                    </div>
                </div>
                <?php if ($editing && $audienceLabel !== ''): ?>
                    <p class="mt-3 text-xs text-brand-gray">Saved audience: <?= e($audienceLabel) ?></p>
                <?php endif; ?>
            </fieldset>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="field-published_at" class="field-label">Publish on</label>
                    <input id="field-published_at" type="datetime-local" name="published_at" value="<?= e($failed ? (string) old('published_at') : $localTime($announcement['published_at'] ?? null)) ?>"
                           class="field-input" aria-describedby="field-published_at-help" <?= $sent ? 'disabled' : '' ?>>
                    <p id="field-published_at-help" class="mt-1.5 text-xs text-brand-gray"><?= $sent ? 'Already published and notified; the date is fixed.' : 'Leave empty to publish as soon as you press Publish. A later date schedules it.' ?></p>
                    <?= $groupError('published_at') ?>
                </div>
                <div>
                    <label for="field-expires_at" class="field-label">Show until</label>
                    <input id="field-expires_at" type="datetime-local" name="expires_at" value="<?= e($failed ? (string) old('expires_at') : $localTime($announcement['expires_at'] ?? null)) ?>"
                           class="field-input" aria-describedby="field-expires_at-help">
                    <p id="field-expires_at-help" class="mt-1.5 text-xs text-brand-gray">Optional. After this it disappears from every list.</p>
                    <?= $groupError('expires_at') ?>
                </div>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="field-image" class="field-label">Image</label>
                    <input id="field-image" type="file" name="image" accept=".jpg,.jpeg,.png,image/jpeg,image/png" data-max-bytes="<?= App\Services\AnnouncementService::IMAGE_MAX_BYTES ?>"
                           class="block w-full text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:font-semibold file:text-brand-700 hover:file:bg-brand-100">
                    <p class="mt-1 text-xs text-brand-gray">Optional. JPG or PNG, up to 2 MB.</p>
                    <?php if ($editing && $announcement['image_path']): ?>
                        <div class="mt-3 flex items-start gap-3">
                            <img src="<?= e(url('/announcement-images/' . $announcement['id'])) ?>" alt="<?= e($announcement['image_alt'] ?? '') ?>" class="h-20 w-32 rounded-lg object-cover ring-1 ring-brand-100">
                            <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="remove_image" value="1" class="<?= $check ?>"> Remove this image</label>
                        </div>
                    <?php endif; ?>
                </div>
                <?= component('field', ['name' => 'image_alt', 'label' => 'Image description', 'value' => $val('image_alt'), 'help' => 'What the image shows, for people using screen readers. Required with an image.']) ?>
            </div>
        </fieldset>

        <?php if (!$archived): ?>
            <div class="flex flex-wrap gap-3 border-t border-brand-100 pt-5">
                <?php if (!$editing || $state === 'DRAFT'): ?>
                    <?php if ($canPublish): ?>
                        <button type="submit" name="action" value="publish" class="btn-primary" data-confirm-click="Publish this announcement? Its audience is notified when it goes live, and that cannot be undone."><?= icon('send', 'h-5 w-5') ?> Publish</button>
                    <?php endif; ?>
                    <button type="submit" name="action" value="draft" class="btn-secondary">Save draft</button>
                <?php else: ?>
                    <button type="submit" name="action" value="save" class="btn-primary">Save changes</button>
                <?php endif; ?>
                <a href="<?= e(url('/manage/announcements')) ?>" class="btn-secondary">Cancel</a>
            </div>
        <?php endif; ?>
        <?= csrf_field() ?>
    </form>

    <?php if ($editing): ?>
        <div class="flex flex-wrap gap-3">
            <?php if ($announcement['status'] === Announcement::PUBLISHED && $canPublish): ?>
                <form method="POST" action="<?= e(url('/manage/announcements/' . $announcement['id'] . '/archive')) ?>" data-confirm="Archive this announcement? It disappears from every list and banner.">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn-secondary">Archive</button>
                </form>
            <?php elseif ($archived): ?>
                <form method="POST" action="<?= e(url('/manage/announcements/' . $announcement['id'] . '/restore')) ?>"
                      data-confirm="It becomes a draft you can edit and publish again. Nobody is notified a second time." data-confirm-title="Restore as a draft?" data-confirm-tone="primary">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn-primary">Restore as draft</button>
                </form>
            <?php endif; ?>
            <?php if ($announcement['status'] === Announcement::DRAFT && $announcement['published_by'] === null): ?>
                <form method="POST" action="<?= e(url('/manage/announcements/' . $announcement['id'] . '/delete')) ?>" data-confirm="Delete this draft? This cannot be undone.">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn-danger">Delete draft</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
