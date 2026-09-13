<?php
/**
 * Front controller — the only web-reachable PHP file in the application.
 *
 * Every request is routed through here. Everything else lives outside public/
 * and is denied by .htaccess.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Router;

$router = new Router();
require BASE_PATH . '/routes/web.php';

$router->dispatch();
