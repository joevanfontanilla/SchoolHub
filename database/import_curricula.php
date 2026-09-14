<?php
/**
 * Import the school's curricula from database/seeds/curricula/*.php (docs/28).
 *
 *   php database/import_curricula.php            check, then import every file
 *   php database/import_curricula.php --check    check only; write nothing
 *   php database/import_curricula.php bsa-2024   one file (name without .php)
 *
 * Safe to re-run: a curriculum whose code already exists is skipped, never
 * overwritten, so changes the registrar has made in the app are kept.
 * Requires the programs from database/seed.php.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('This script may only be run from the command line.');
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\CurriculumImporter;

$arguments = array_slice($argv, 1);
$checkOnly = in_array('--check', $arguments, true);
$names = array_values(array_filter($arguments, static fn ($a) => !str_starts_with($a, '--')));

$directory = __DIR__ . '/seeds/curricula';
$files = $names === []
    ? (glob($directory . '/*.php') ?: [])
    : array_map(static fn ($name) => $directory . '/' . basename($name, '.php') . '.php', $names);
sort($files);

if ($files === []) {
    fwrite(STDERR, "\n  No curriculum files found in database/seeds/curricula.\n\n");
    exit(1);
}

$failed = false;
echo "\n";

foreach ($files as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, '  ' . basename($file) . " ... not found\n");
        $failed = true;
        continue;
    }

    try {
        $data = CurriculumImporter::load($file);
        $check = CurriculumImporter::check($data);
        $label = str_pad((string) ($data['code'] ?? basename($file)), 18, ' ');

        if ($check['errors'] !== []) {
            echo "  {$label} ERRORS\n";
            foreach ($check['errors'] as $error) {
                echo "      x {$error}\n";
            }
            $failed = true;
            continue;
        }

        if ($checkOnly) {
            echo "  {$label} ok\n";
        } else {
            $result = CurriculumImporter::import($data);
            echo $result['created']
                ? "  {$label} imported ({$result['subjects']} subjects)\n"
                : "  {$label} already present, skipped\n";
        }

        foreach ($check['warnings'] as $warning) {
            echo "      ! {$warning}\n";
        }
        foreach ((array) ($data['to_confirm'] ?? []) as $note) {
            echo "      ? {$note}\n";
        }
    } catch (Throwable $e) {
        fwrite(STDERR, '  ' . basename($file) . ' ... ' . $e->getMessage() . "\n");
        $failed = true;
    }
}

echo "\n  ! = differs from the printed document    ? = to confirm with the registrar\n\n";
exit($failed ? 1 : 0);
