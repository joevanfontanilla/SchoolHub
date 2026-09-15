<?php
/**
 * Heading and tabs of the administrative reports, with an optional date-range form.
 *
 * @var string  $active    accounts|sign-ins|activity|usage
 * @var string  $heading
 * @var string  $subtitle
 * @var ?string $path      the report's own path, for the date form and the print and export links
 * @var ?string $from      when the report has a date range
 * @var ?string $to
 * @var ?string $error     why the default range is shown
 */
$from ??= null;
$to ??= null;
$error ??= null;
$query = $from !== null ? '?' . http_build_query(['from' => $from, 'to' => $to]) : '';
?>
<?= partial('report-header', [
    'eyebrow'  => 'Administrative reports',
    'tabs'     => [
        'accounts' => ['/admin/reports/accounts', 'Account review', 'users'],
        'sign-ins' => ['/admin/reports/sign-ins', 'Sign-in summary', 'key'],
        'activity' => ['/admin/reports/activity', 'Activity summary', 'list-check'],
        'usage'    => ['/admin/reports/usage', 'System usage', 'database'],
    ],
    'active'   => $active,
    'heading'  => $heading,
    'subtitle' => $subtitle,
]) ?>

<?php if ($from !== null): ?>
    <form method="GET" action="<?= e(url($path)) ?>" class="card flex flex-wrap items-end gap-4 p-4">
        <div>
            <label for="report-from" class="field-label">From</label>
            <input id="report-from" type="date" name="from" value="<?= e($from) ?>" max="<?= e(date('Y-m-d')) ?>" class="field-input">
        </div>
        <div>
            <label for="report-to" class="field-label">To</label>
            <input id="report-to" type="date" name="to" value="<?= e($to) ?>" class="field-input">
        </div>
        <button type="submit" class="btn-secondary"><?= icon('search', 'h-5 w-5') ?> Show</button>
        <div class="ml-auto flex flex-wrap gap-2">
            <a href="<?= e(url($path . '/print' . $query)) ?>" class="btn-secondary"><?= icon('document', 'h-5 w-5') ?> Print</a>
            <a href="<?= e(url($path . '/export' . $query)) ?>" class="btn-primary"><?= icon('download', 'h-5 w-5') ?> Export CSV</a>
        </div>
    </form>
    <?php if ($error !== null): ?>
        <p class="flex items-center gap-2 rounded-xl border border-warning-line bg-warning-bg px-4 py-3 text-sm text-warning-text" role="status"><?= icon('alert', 'h-5 w-5 shrink-0') ?> <?= e($error) ?></p>
    <?php endif; ?>
<?php endif; ?>
