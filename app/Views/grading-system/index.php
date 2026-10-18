<?php
/**
 * The Official Grading System (docs/27), from GradeCalculator::TABLE.
 *
 * @var array $table [equivalent, lowest percentage] rows, best first
 */
use App\Services\GradeCalculator;

$groups = [];
foreach ($table as [$equivalent, $lowest]) {
    $groups[GradeCalculator::descriptor($equivalent)][] = [$equivalent, $lowest];
}
$groups['Failed'] = [['5.00', 'below 75']];
?>
<div class="space-y-6">
    <div>
        <p class="eyebrow">Office of the Registrar</p>
        <h2 class="mt-1 text-2xl">Official Grading System</h2>
        <p class="mt-1 max-w-3xl text-sm text-slate-600">
            Lyceum of the East - Aurora, Inc. A percentage takes the equivalent of the highest row at or below it, so 92.0 is 1.44 and 91.9 is 1.45.
            Below 75 is 5.00, failed. A teacher can type either the percentage or the equivalent.
        </p>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <?php foreach ($groups as $descriptor => $rows): ?>
            <section class="card" aria-labelledby="group-<?= e(strtolower(str_replace(' ', '-', $descriptor))) ?>">
                <h3 id="group-<?= e(strtolower(str_replace(' ', '-', $descriptor))) ?>" class="border-b border-slate-200 px-4 py-3 text-base"><?= e($descriptor) ?></h3>
                <div class="table-scroll">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-left text-brand-navy">
                            <tr><th scope="col" class="px-4 py-2 font-semibold">Equivalent</th><th scope="col" class="px-4 py-2 font-semibold">Percentage</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($rows as [$equivalent, $lowest]): ?>
                                <tr><td class="px-4 py-1.5 font-semibold tabular-nums"><?= e($equivalent) ?></td><td class="px-4 py-1.5 tabular-nums"><?= e($lowest) ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endforeach; ?>

        <section class="card" aria-labelledby="marks-heading">
            <h3 id="marks-heading" class="border-b border-slate-200 px-4 py-3 text-base">Supplementary marks</h3>
            <dl class="divide-y divide-slate-100 text-sm">
                <?php foreach (['INC' => 'Incomplete', 'OW' => 'Officially withdrawn', 'UW' => 'Unofficially withdrawn', 'NGA' => 'No grade due to absences'] as $mark => $meaning): ?>
                    <div class="flex justify-between gap-3 px-4 py-2"><dt class="font-semibold"><?= e($mark) ?></dt><dd><?= e($meaning) ?></dd></div>
                <?php endforeach; ?>
            </dl>
        </section>
    </div>
</div>