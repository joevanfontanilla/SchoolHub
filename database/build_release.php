<?php
/**
 * Package SchoolHub for a server (docs/23).
 *
 *   php database/build_release.php            writes build/schoolhub-<version>.zip
 *
 * The zip holds two folders, ready to upload into a hosting account's home folder or /var/www:
 *   schoolhub/     the application: app, config (no local settings), database, routes, an empty
 *                  storage, the deploy/ templates, and DEPLOYMENT.md. Never inside the web folder.
 *   public_html/   the web folder: index.php, .htaccess, and assets. On shared hosting its contents
 *                  go into the existing public_html; on a VPS it is the DocumentRoot.
 *
 * Left out: config.local.php (passwords), uploads, logs, backups, tests, development documents,
 * source CSS, the Tailwind binary, and version-control files. The compiled stylesheet is included,
 * so the server needs no build step.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('This script may only be run from the command line.');
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Config;

$version = preg_replace('/[^A-Za-z0-9.\-]/', '', (string) Config::get('app.version', 'dev'));
$out = BASE_PATH . "/build/schoolhub-{$version}.zip";

/** What goes into schoolhub/: folders copied whole, then single files. */
const APPLICATION_FOLDERS = ['app', 'database', 'routes', 'deploy'];
const APPLICATION_FILES = ['bootstrap.php', '.htaccess', 'config/config.php', 'config/config.local.example.php', 'storage/.htaccess'];
/** Empty runtime folders, kept with a placeholder so they exist after unzipping. */
const STORAGE_FOLDERS = ['storage/uploads', 'storage/logs', 'storage/backups'];

$css = BASE_PATH . '/public/assets/app.css';
if (!is_file($css) || filesize($css) < 10000) {
    fwrite(STDERR, "  public/assets/app.css is missing or empty. Build it first: ./tailwindcss.exe -i src/input.css -o public/assets/app.css --minify\n");
    exit(1);
}
if (!is_dir(dirname($out))) {
    mkdir(dirname($out), 0750, true);
}
@unlink($out);

$zip = new ZipArchive();
if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "  Could not create {$out}\n");
    exit(1);
}

$files = 0;
$bytes = 0;
$add = static function (string $source, string $target) use ($zip, &$files, &$bytes): void {
    $zip->addFile($source, $target);
    // Scripts and folders keep the permissions a web server expects when unzipped on Linux.
    $zip->setExternalAttributesName($target, ZipArchive::OPSYS_UNIX, 0644 << 16);
    $files++;
    $bytes += (int) filesize($source);
};
$addFolder = static function (string $folder, string $prefix) use ($add): void {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH . '/' . $folder, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen(BASE_PATH . '/' . $folder) + 1);
        // Operating-system clutter never ships.
        if (in_array($file->getFilename(), ['Thumbs.db', 'desktop.ini', '.DS_Store'], true)) {
            continue;
        }
        $add($file->getPathname(), $prefix . $relative);
    }
};

foreach (APPLICATION_FOLDERS as $folder) {
    $addFolder($folder, "schoolhub/{$folder}/");
}
foreach (APPLICATION_FILES as $file) {
    $add(BASE_PATH . '/' . $file, "schoolhub/{$file}");
}
foreach (STORAGE_FOLDERS as $folder) {
    $zip->addFromString("schoolhub/{$folder}/.gitkeep", '');
}
$add(BASE_PATH . '/docs/23_DEPLOYMENT.md', 'schoolhub/DEPLOYMENT.md');
$addFolder('public', 'public_html/');

$zip->setArchiveComment("SchoolHub {$version}, built " . date('c'));
$zip->close();

// Nothing private may have slipped in.
$check = new ZipArchive();
$check->open($out);
$names = [];
for ($i = 0; $i < $check->numFiles; $i++) {
    $names[] = (string) $check->getNameIndex($i);
}
$check->close();
$private = array_values(array_filter($names, static fn (string $name) => (bool) preg_match('#config\.local\.php$|\.git/|tailwindcss|/tests/|^(?!schoolhub/database/migrations/).*\.sql$#', $name)
    || (preg_match('#^schoolhub/storage/(uploads|logs|backups)/#', $name) && !str_ends_with($name, '/.gitkeep'))));
if ($private !== []) {
    @unlink($out);
    fwrite(STDERR, "  Refused: private files were about to be packaged:\n    " . implode("\n    ", $private) . "\n");
    exit(1);
}

printf("  Wrote %s\n  %d files, %s KB before compression, %s KB zipped\n", $out, $files, number_format($bytes / 1024), number_format(filesize($out) / 1024));
echo "  Upload and unzip it next to the web folder, then follow schoolhub/DEPLOYMENT.md.\n";
