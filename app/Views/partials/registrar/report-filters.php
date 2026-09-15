<?php
/**
 * Heading, tabs, and the filter form of a registrar report (docs/15).
 *
 * @var string $active    enrollment|applications|requirements|history
 * @var string $heading
 * @var string $subtitle
 * @var string $path      the report's own path
 * @var array  $filters   term_id, program_id, year_level_id, section_id, status (null when unset)
 * @var array  $fields    which filters this report offers: term, program, year, section, status
 * @var array  $terms
 * @var array  $programs
 * @var array  $yearLevels
 * @var array  $sections
 * @var array  $statuses
 */
use App\Models\RegistrarReport;

$query = http_build_query(array_filter($filters, static fn ($value) => $value !== null));
$suffix = $query !== '' ? '?' . $query : '';
$current = array_map(static fn ($value) => (string) ($value ?? ''), $filters);
?>
<?= partial('report-header', [
    'eyebrow'  => 'Registrar reports',
    'tabs'     => [
        'enrollment'   => ['/registrar/reports/enrollment', 'Enrollment list', 'users'],
        'applications' => ['/registrar/reports/applications', 'Applicants by status', 'inbox'],
        'requirements' => ['/registrar/reports/requirements', 'Requirements', 'requirements'],
        'history'      => ['/registrar/reports/history', 'Enrollment history', 'history'],
    ],
    'active'   => $active,
    'heading'  => $heading,
    'subtitle' => $subtitle,
]) ?>

<form method="GET" action="<?= e(url($path)) ?>" class="card flex flex-wrap items-end gap-4 p-4">
    <?php if (in_array('term', $fields, true)): ?>
        <div class="min-w-[14rem]">
            <label for="report-term" class="field-label">Term</label>
            <select id="report-term" name="term_id" class="field-input">
                <?php foreach ($terms as $term): ?>
                    <option value="<?= e($term['id']) ?>" <?= $current['term_id'] === (string) $term['id'] ? 'selected' : '' ?>><?= e($term['name'] . ', A.Y. ' . $term['academic_year_code']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <?php if (in_array('program', $fields, true)): ?>
        <div class="min-w-[10rem]">
            <label for="report-program" class="field-label">Program</label>
            <select id="report-program" name="program_id" class="field-input">
                <option value="">All programs</option>
                <?php foreach ($programs as $program): ?>
                    <option value="<?= e($program['id']) ?>" <?= $current['program_id'] === (string) $program['id'] ? 'selected' : '' ?>><?= e($program['code']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <?php if (in_array('year', $fields, true)): ?>
        <div class="min-w-[10rem]">
            <label for="report-year" class="field-label">Year level</label>
            <select id="report-year" name="year_level_id" class="field-input">
                <option value="">All year levels</option>
                <?php foreach ($yearLevels as $level): ?>
                    <option value="<?= e($level['id']) ?>" <?= $current['year_level_id'] === (string) $level['id'] ? 'selected' : '' ?>><?= e($level['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <?php if (in_array('section', $fields, true)): ?>
        <div class="min-w-[10rem]">
            <label for="report-section" class="field-label">Section</label>
            <select id="report-section" name="section_id" class="field-input">
                <option value="">All sections</option>
                <?php foreach ($sections as $section): ?>
                    <option value="<?= e($section['id']) ?>" <?= $current['section_id'] === (string) $section['id'] ? 'selected' : '' ?>><?= e($section['code']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <?php if (in_array('status', $fields, true)): ?>
        <div class="min-w-[12rem]">
            <label for="report-status" class="field-label">Status</label>
            <select id="report-status" name="status" class="field-input">
                <option value="">All statuses</option>
                <?php foreach ($statuses as $status): ?>
                    <option value="<?= e($status) ?>" <?= $current['status'] === $status ? 'selected' : '' ?>><?= e(RegistrarReport::label($status)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <button type="submit" class="btn-secondary"><?= icon('search', 'h-5 w-5') ?> Show</button>
    <div class="ml-auto flex flex-wrap gap-2">
        <a href="<?= e(url($path . '/print' . $suffix)) ?>" class="btn-secondary"><?= icon('document', 'h-5 w-5') ?> Print</a>
        <a href="<?= e(url($path . '/export' . $suffix)) ?>" class="btn-primary"><?= icon('download', 'h-5 w-5') ?> Export CSV</a>
    </div>
</form>
