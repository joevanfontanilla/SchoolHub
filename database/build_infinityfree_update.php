<?php
/**
 * Package an update for the InfinityFree site (docs/23, section F.1).
 *
 *   php database/build_infinityfree_update.php     writes build/schoolhub-infinityfree-update-<date>.zip
 *
 * InfinityFree has no command line, so an update is a zip that is extracted into htdocs/ over the
 * running site. The zip root is htdocs/: assets/ and schoolhub/ (the application, inside the web folder).
 *
 * Left out on purpose, so extracting it can never overwrite what lives only on the server:
 *   - schoolhub/config/config.local.php (passwords; the release never contains it)
 *   - schoolhub/storage/ (uploaded documents, logs, backups)
 *   - htdocs/index.php, .htaccess and .user.ini (they rarely change; copy them by hand if they do)
 *
 * It builds the release first (database/build_release.php), so run the CSS build before it:
 *   ./tailwindcss.exe -i src/input.css -o public/assets/app.css --minify
 *
 * The database changes are NOT in the zip: they are pasted into phpMyAdmin first (docs/23, F.1).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('This script may only be run from the command line.');
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Config;

$version = preg_replace('/[^A-Za-z0-9.\-]/', '', (string) Config::get('app.version', 'dev'));
$release = BASE_PATH . "/build/schoolhub-{$version}.zip";
$out = BASE_PATH . '/build/schoolhub-infinityfree-update-' . date('Y-m-d') . '.zip';

// A fresh release every time, so the update can never carry stale files.
$status = 0;
passthru('"' . PHP_BINARY . '" ' . escapeshellarg(__DIR__ . '/build_release.php'), $status);
if ($status !== 0 || !is_file($release)) {
    fwrite(STDERR, "  The release could not be built. Nothing was written.\n");
    exit(1);
}

$in = new ZipArchive();
if ($in->open($release) !== true) {
    fwrite(STDERR, "  Cannot read {$release}.\n");
    exit(1);
}
@unlink($out);
$zip = new ZipArchive();
if ($zip->open($out, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "  Cannot write {$out}.\n");
    exit(1);
}

$kept = $left = 0;
for ($i = 0; $i < $in->numFiles; $i++) {
    $name = (string) $in->getNameIndex($i);
    if (str_ends_with($name, '/')) {
        continue;
    }
    if (str_starts_with($name, 'public_html/assets/')) {
        $target = substr($name, strlen('public_html/'));
    } elseif (str_starts_with($name, 'schoolhub/') && !str_starts_with($name, 'schoolhub/storage/')) {
        $target = $name;
    } else {
        $left++;
        continue;
    }
    // The release script already refuses secrets; this is the second lock on the same door.
    if (preg_match('#(^|/)config\.local\.php$#', $target)) {
        $zip->close();
        @unlink($out);
        fwrite(STDERR, "  Refusing: {$target} must never be in an update.\n");
        exit(1);
    }
    $zip->addFromString($target, (string) $in->getFromIndex($i));
    $kept++;
}
$zip->close();
$in->close();

echo "  Wrote {$out}\n";
echo "  {$kept} files; left out {$left} (index.php, .htaccess, storage placeholders).\n";
echo "  Order on the server: 1) back up the database, 2) run the SQL in phpMyAdmin, 3) extract this zip into htdocs (overwrite), 4) hard-refresh.\n";
