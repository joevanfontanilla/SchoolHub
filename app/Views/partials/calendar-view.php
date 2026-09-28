<?php
/**
 * Read-only academic calendar (docs/09), shared by the public page and the portal page.
 *
 * @var array<int,string> $years         academic years with entries, newest first
 * @var string            $year          the selected academic year
 * @var array             $groups        [ ['category' => string, 'rows' => [...] ], ... ]
 * @var string            $calendarPath  base path for the year selector (/calendar or /portal/calendar)
 */
$calendarPath = $calendarPath ?? '/calendar';
?>
<div class="space-y-6">
    <?php if (count($years) > 1): ?>
        <form method="GET" action="<?= e(url($calendarPath)) ?>" class="flex flex-wrap items-end gap-3" data-auto-submit>
            <div class="min-w-[13rem]">
                <label for="cal-ay" class="field-label">Academic year</label>
                <select id="cal-ay" name="ay" class="field-input">
                    <?php foreach ($years as $y): ?>
                        <option value="<?= e($y) ?>" <?= $y === $year ? 'selected' : '' ?>>A.Y. <?= e($y) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <noscript><button type="submit" class="btn-secondary">Show</button></noscript>
        </form>
    <?php elseif ($year !== ''): ?>
        <p class="eyebrow">A.Y. <?= e($year) ?></p>
    <?php endif; ?>

    <?php if ($groups === []): ?>
        <?= component('empty-state', ['title' => 'No calendar published yet', 'message' => 'The academic calendar will appear here once it is added.']) ?>
    <?php else: ?>
        <?php foreach ($groups as $i => $group): ?>
            <section class="card overflow-hidden" aria-labelledby="cal-group-<?= (int) $i ?>">
                <h3 id="cal-group-<?= (int) $i ?>" class="border-b border-slate-200 bg-brand-50 px-5 py-3 font-display text-base font-bold uppercase tracking-wide text-brand-navy">
                    <?= e($group['category']) ?>
                </h3>
                <div class="table-scroll">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <th scope="col" class="w-56 px-5 py-2.5">Date</th>
                                <th scope="col" class="px-5 py-2.5">Activity</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($group['rows'] as $row): ?>
                                <tr>
                                    <td class="whitespace-nowrap px-5 py-2.5 font-medium text-brand-navy"><?= e($row['date_label']) ?></td>
                                    <td class="px-5 py-2.5 text-slate-700"><?= e($row['activity']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
