<?php
/**
 * Front controller — the only web-reachable PHP file in the application.
 *
 * Every request is routed through here. Everything else lives outside public/
 * and is denied by .htaccess.
 */

declare(strict_types=1);

define('PUBLIC_PATH', __DIR__);

// The application folder is either the parent of this folder (a web root pointed at public/,
// or XAMPP's htdocs), or a folder named "schoolhub" beside the web root or up to two levels
// above it. That is the hosting layout: public/'s contents are copied into public_html (or a
// subdomain's folder such as public_html/enroll), and the rest of the application stays in
// ~/schoolhub where the web server cannot reach it (docs/23).
$root = null;
foreach ([dirname(__DIR__), dirname(__DIR__) . '/schoolhub', dirname(__DIR__, 2) . '/schoolhub', dirname(__DIR__, 3) . '/schoolhub'] as $candidate) {
    if (is_file($candidate . '/bootstrap.php')) {
        $root = $candidate;
        break;
    }
}
if ($root === null) {
    http_response_code(500);
    exit('SchoolHub is not installed correctly: the application folder was not found next to the web folder.');
}

require $root . '/bootstrap.php';

use App\Core\Router;

$router = new Router();
require BASE_PATH . '/routes/web.php';

$router->dispatch();
