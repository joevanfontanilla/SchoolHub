<?php
/**
 * Academic Records: hero, tabs, and the term filter shared by every tab.
 *
 * @var string $tab
 * @var array  $enrollments the student's own terms
 * @var ?array $enrollment  the one shown
 * @var array  $school
 */
$termQuery = $enrollment !== null ? '?term_id=' . $enrollment['term_id'] : '';
$tabs = [
    ['/student/grades' . $termQuery, 'Grades', 'chart-bar', $tab === 'grades'],
    ['/student/schedule' . $termQuery, 'Class Schedule', 'calendar', $tab === 'schedule'],
    ['/student/attendance' . $termQuery, 'Attendance', 'attendance', $tab === 'attendance'],
    ['/student/history', 'Academic History', 'document', $tab === 'history'],
];
$tabLabels = ['grades' => 'Grades', 'schedule' => 'Class Schedule', 'attendance' => 'Attendance', 'history' => 'Academic History'];
?>
<?= partial('page-hero', [
    'heading'     => 'Academic Records',
    'subtitle'    => 'View your grades, class schedule, attendance, and academic history.',
    'tagline'     => $school['school_tagline'] ?? null,
    'breadcrumbs' => [['Home', '/student/dashboard'], ['Academic Records', null], [$tabLabels[$tab], null]],
    'tabs'        => $tabs,
    'print'       => true,
]) ?>

<?php if ($tab !== 'history'): ?>
    <form method="GET" action="<?= e(url('/student/' . $tab)) ?>" class="card mb-6 flex flex-wrap items-end gap-4 p-4 sm:p-5" data-auto-submit>
        <div class="min-w-[15rem] flex-1 sm:flex-none">
            <label for="records-term" class="field-label">Term</label>
            <select id="records-term" name="term_id" class="field-input" <?= $enrollments === [] ? 'disabled' : '' ?>>
                <?php if ($enrollments === []): ?>
                    <option>No enrolled terms yet</option>
                <?php endif; ?>
                <?php foreach ($enrollments as $option): ?>
                    <option value="<?= e($option['term_id']) ?>" <?= $enrollment !== null && (int) $enrollment['term_id'] === (int) $option['term_id'] ? 'selected' : '' ?>>
                        <?= e($option['term_name'] . ', A.Y. ' . $option['academic_year_code']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="min-w-[9rem]">
            <p class="field-label">Program</p>
            <p class="field-input bg-brand-50/60 text-slate-700"><?= e($enrollment['program_code'] ?? '—') ?></p>
        </div>
        <div class="min-w-[9rem]">
            <p class="field-label">Year level</p>
            <p class="field-input bg-brand-50/60 text-slate-700"><?= e($enrollment['year_level_name'] ?? '—') ?></p>
        </div>
        <div class="min-w-[9rem]">
            <p class="field-label">Section</p>
            <p class="field-input bg-brand-50/60 text-slate-700"><?= e($enrollment['section_code'] ?? '—') ?></p>
        </div>
        <noscript><button type="submit" class="btn-secondary">Show</button></noscript>
        <a href="<?= e(url('/student/credentials')) ?>" class="btn-secondary ml-auto">
            <?= icon('document', 'h-5 w-5') ?> Request Official Transcript
        </a>
    </form>
<?php endif; ?>
