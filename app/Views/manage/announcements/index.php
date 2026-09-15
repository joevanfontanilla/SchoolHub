<?php
/**
 * Announcements for the people who write them (docs/16).
 *
 * @var string $state
 * @var string $q
 * @var array  $announcements
 * @var array  $labels        id => audience label
 * @var bool   $canPublish
 */
use App\Models\Announcement;

$states = ['' => 'All', 'DRAFT' => 'Drafts', 'SCHEDULED' => 'Scheduled', 'PUBLISHED' => 'Published', 'EXPIRED' => 'Expired', 'ARCHIVED' => 'Archived'];
?>
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">Communication</p>
            <h2 class="mt-1 text-2xl">Announcements</h2>
            <p class="mt-1 text-sm text-slate-600">Write news for everyone or for chosen groups. Publishing notifies the audience once, when it goes live.</p>
        </div>
        <a href="<?= e(url('/manage/announcements/create')) ?>" class="btn-primary"><?= icon('plus', 'h-5 w-5') ?> New announcement</a>
    </div>

    <form method="GET" action="<?= e(url('/manage/announcements')) ?>" class="card flex flex-wrap items-end gap-4 p-4">
        <div class="min-w-[16rem] flex-1">
            <label for="announcements-q" class="field-label">Search</label>
            <input id="announcements-q" type="search" name="q" value="<?= e($q) ?>" placeholder="Title or text" class="field-input">
        </div>
        <div class="min-w-[10rem]">
            <label for="announcements-state" class="field-label">Status</label>
            <select id="announcements-state" name="state" class="field-input">
                <?php foreach ($states as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $state === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-secondary"><?= icon('search', 'h-5 w-5') ?> Search</button>
    </form>

    <section class="card">
        <?php if ($announcements === []): ?>
            <?= component('empty-state', ['title' => 'No announcements', 'message' => $q !== '' || $state !== '' ? 'Nothing matches this search.' : 'Write the first one with "New announcement".']) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                    <thead class="bg-brand-50 text-brand-navy">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Announcement</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Audience</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Status</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Publish date</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Notified</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-50 bg-white">
                        <?php foreach ($announcements as $row): ?>
                            <tr class="hover:bg-brand-50/40">
                                <td class="px-4 py-3">
                                    <span class="font-semibold text-brand-navy"><?= e($row['title']) ?></span>
                                    <span class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-brand-gray">
                                        <?= e($row['category'] ?? 'General') ?>
                                        <?php if ($row['audience'] === Announcement::CLASSES): ?><span class="rounded-full bg-brand-50 px-2 py-0.5 font-semibold text-brand-700">Class post by <?= e($row['author_name'] ?? 'a former teacher') ?></span><?php endif; ?>
                                        <?php if ((int) $row['is_urgent'] === 1): ?><?= component('status-badge', ['status' => 'URGENT', 'label' => 'Urgent']) ?><?php endif; ?>
                                        <?php if ((int) $row['is_public'] === 1): ?><span class="rounded-full bg-brand-50 px-2 py-0.5 font-semibold text-brand-700">Public website</span><?php endif; ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-slate-700"><?= e($labels[(int) $row['id']] ?? '') ?></td>
                                <td class="whitespace-nowrap px-4 py-3"><?= component('status-badge', ['status' => $row['state'], 'label' => ucfirst(strtolower($row['state']))]) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600"><?= e($row['published_at'] ? dt($row['published_at']) : 'When published') ?><?php if ($row['expires_at']): ?><span class="block text-xs text-brand-gray">Until <?= e(dt($row['expires_at'])) ?></span><?php endif; ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums"><?= $row['recipient_count'] === null ? '<span class="text-brand-gray">Not yet</span>' : e(number_format((int) $row['recipient_count'])) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <a href="<?= e(url('/portal/announcements/' . $row['id'])) ?>" class="mr-1 inline-flex min-h-[1.75rem] items-center rounded-md px-1.5 font-semibold text-brand-600 hover:bg-brand-50 hover:underline">View<span class="sr-only"> <?= e($row['title']) ?></span></a>
                                    <?php if ($row['audience'] !== Announcement::CLASSES): ?>
                                        <a href="<?= e(url('/manage/announcements/' . $row['id'] . '/edit')) ?>" class="inline-flex min-h-[1.75rem] items-center rounded-md px-1.5 font-semibold text-brand-600 hover:bg-brand-50 hover:underline">Edit<span class="sr-only"> <?= e($row['title']) ?></span></a>
                                    <?php elseif ($canPublish && $row['status'] === Announcement::PUBLISHED): ?>
                                        <form method="POST" action="<?= e(url('/manage/announcements/' . $row['id'] . '/archive')) ?>" class="inline" data-confirm="Archive this class announcement? Its students no longer see it.">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="inline-flex min-h-[1.75rem] items-center rounded-md px-1.5 font-semibold text-brand-600 hover:bg-brand-50 hover:underline">Archive<span class="sr-only"> <?= e($row['title']) ?></span></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
