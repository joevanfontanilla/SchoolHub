<?php
/**
 * Dependency-free layout for error pages. Must render even when the database,
 * the session, or the compiled CSS is unavailable.
 *
 * @var string $content
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php /* SystemSetting::get() falls back safely when the database is down. */ ?>
    <title><?= e($status ?? 'Error') ?> · <?= e(App\Models\SystemSetting::get('school_name', 'SchoolHub')) ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/app.css')) ?>">
    <link rel="icon" type="image/jpeg" href="<?= e(asset('assets/images/lyceum-logo.jpg')) ?>">
    <style>
        /* Inline fallback so the page is still readable if app.css is missing. */
        body { font-family: 'Segoe UI', system-ui, sans-serif; margin: 0; background: #f8fafc; color: #1e293b; }
        .wrap { max-width: 36rem; margin: 0 auto; padding: 5rem 1.5rem; }
    </style>
</head>
<body>
    <div class="wrap"><?= $content ?></div>
</body>
</html>
