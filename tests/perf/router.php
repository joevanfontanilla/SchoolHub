<?php
/**
 * Router for PHP's built-in server during performance testing (docs/22):
 *
 *   php -S 127.0.0.1:8099 -t public tests/perf/router.php
 *
 * Points the application at the throwaway `schoolhub_perf` database, so the load data
 * never touches the real one. Static files are served directly.
 */

declare(strict_types=1);

putenv('SCHOOLHUB_DATABASE=schoolhub_perf');

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if ($path !== '/' && is_file(dirname(__DIR__, 2) . '/public' . $path)) {
    return false;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
require dirname(__DIR__, 2) . '/public/index.php';
