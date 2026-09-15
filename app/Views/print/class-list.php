<?php
/**
 * Printable class list (docs/15).
 *
 * @var array $class
 * @var array $roster
 */
$cell = 'border-b border-slate-200 px-2 py-1.5';
?>
<section class="mt-6 space-y-6">
    <?= partial('teacher/report-heading', ['heading' => 'Class List', 'class' => $class, 'user' => $user]) ?>

    <div class="overflow-x-auto print:overflow-visible">
        <table class="w-full border border-slate-300 text-xs">
            <caption class="pb-2 text-left text-sm text-slate-600"><?= e(count($roster)) ?> enlisted student<?= count($roster) === 1 ? '' : 's' ?></caption>
            <thead class="bg-brand-50 text-left text-brand-navy">
                <tr><th scope="col" class="<?= $cell ?>">#</th><th scope="col" class="<?= $cell ?>">Student No.</th><th scope="col" class="<?= $cell ?>">Name</th><th scope="col" class="<?= $cell ?>">Program</th><th scope="col" class="<?= $cell ?>">Email</th><th scope="col" class="<?= $cell ?> w-32">Signature</th></tr>
            </thead>
            <tbody>
                <?php foreach ($roster as $i => $row): ?>
                    <tr class="break-inside-avoid">
                        <td class="<?= $cell ?> tabular-nums text-slate-500"><?= e($i + 1) ?></td>
                        <td class="<?= $cell ?> whitespace-nowrap"><?= e($row['student_number']) ?></td>
                        <td class="<?= $cell ?>"><?= e($row['last_name'] . ', ' . $row['first_name'] . ($row['middle_name'] ? ' ' . $row['middle_name'] : '')) ?></td>
                        <td class="<?= $cell ?>"><?= e(trim(($row['program_code'] ?? '') . ' · ' . ($row['year_level_name'] ?? ''), ' ·')) ?></td>
                        <td class="<?= $cell ?>"><?= e($row['email']) ?></td>
                        <td class="<?= $cell ?>"></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($roster === []): ?><tr><td colspan="6" class="<?= $cell ?> text-slate-500">No students are enlisted in this class.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
