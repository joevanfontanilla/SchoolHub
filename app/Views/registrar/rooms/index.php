<?php
/** @var array $rooms */
?>
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">Academic catalog</p>
            <h2 class="mt-1 text-2xl">Rooms</h2>
            <p class="mt-1 text-sm text-slate-600">A room cannot hold two classes at the same time. Rooms are deactivated, never deleted.</p>
        </div>
        <a href="<?= e(url('/registrar/rooms/create')) ?>" class="btn-primary">New room</a>
    </div>

    <section class="card">
        <?php if ($rooms === []): ?>
            <?= component('empty-state', ['title' => 'No rooms yet', 'message' => 'Add the rooms and laboratories classes are held in.']) ?>
        <?php else: ?>
            <div class="table-scroll">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Code</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Name</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Type</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Capacity</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Active classes</th>
                            <th scope="col" class="px-4 py-3 font-display font-semibold text-brand-navy">Status</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($rooms as $room): ?>
                            <tr>
                                <td class="whitespace-nowrap px-4 py-3 font-display font-bold text-brand-navy"><?= e($room['code']) ?></td>
                                <td class="px-4 py-3 text-slate-700"><?= e($room['name']) ?><?= $room['building'] ? '<span class="block text-xs text-brand-gray">' . e($room['building']) . '</span>' : '' ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-700"><?= e($room['type']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-700"><?= e($room['capacity']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3 text-slate-700"><?= e($room['class_count']) ?></td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <span class="badge <?= (int) $room['is_active'] === 1 ? 'bg-success-bg text-success-text' : 'bg-slate-100 text-slate-600' ?>">
                                        <?= (int) $room['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <a href="<?= e(url('/registrar/rooms/' . $room['id'] . '/edit')) ?>" class="font-semibold text-brand-600 hover:underline">Edit<span class="sr-only"> <?= e($room['code']) ?></span></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
