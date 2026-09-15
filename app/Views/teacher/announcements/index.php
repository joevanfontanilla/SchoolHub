<?php
/**
 * The teacher's announcements to their classes (docs/16).
 *
 * @var array $announcements
 * @var array $labels  id => "Students of IT 201 · BSIT-2A, ..."
 */
?>
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">Communication</p>
            <h2 class="mt-1 text-2xl">Class Announcements</h2>
            <p class="mt-1 text-sm text-slate-600">Post news to the students of your classes: a room change, a reminder, a cancelled meeting. They are notified once, when it goes live.</p>
        </div>
        <a href="<?= e(url('/teacher/announcements/create')) ?>" class="btn-primary"><?= icon('plus', 'h-5 w-5') ?> New announcement</a>
    </div>

    <section class="card">
        <?php if ($announcements === []): ?>
            <?= component('empty-state', ['title' => 'No class announcements yet', 'message' => 'Write the first one with "New announcement".']) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-brand-100 text-left text-sm">
                    <thead class="bg-brand-50 text-brand-navy">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-semibold">Announcement</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Classes</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Status</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Posted</th>
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
                                        <?php if ((int) $row['is_urgent'] === 1): ?><?= component('status-badge', ['status' => 'URGENT', 'label' => 'Urgent']) ?><?php endif; ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-slate-700"><?= e(preg_replace('/^Students of /', '', $labels[(int) $row['id']] ?? '')) ?></td>
                                <td class="whitespace-nowrap px-4 py-3"><?= component('status-badge', ['status' => $row['state'], 'label' => ucfirst(strtolower($row['state']))]) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600"><?= e($row['published_at'] ? dt($row['published_at']) : 'Not yet') ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums"><?= e($row['recipient_count'] === null ? 'Not yet' : number_format((int) $row['recipient_count'])) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <a href="<?= e(url('/teacher/announcements/' . $row['id'] . '/edit')) ?>" class="font-semibold text-brand-600 hover:underline">Edit<span class="sr-only"> <?= e($row['title']) ?></span></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
