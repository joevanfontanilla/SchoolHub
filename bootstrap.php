<?php
/**
 * Application bootstrap: paths, autoloading, config, error handling, session.
 *
 * Loaded by public/index.php for web requests and by the CLI scripts in
 * database/ and tests/.
 */

declare(strict_types=1);

define('BASE_PATH', __DIR__);
define('APP_PATH', BASE_PATH . '/app');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('IS_CLI', PHP_SAPI === 'cli');

// ---------------------------------------------------------------------------
// Autoloader (PSR-4 for App\ — no Composer, per docs/00)
// ---------------------------------------------------------------------------
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = APP_PATH . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------
$config = require BASE_PATH . '/config/config.php';

$localConfig = BASE_PATH . '/config/config.local.php';
if (is_file($localConfig)) {
    $local = require $localConfig;
    if (is_array($local)) {
        // Recursive merge so a local file can override a single key.
        $merge = static function (array $base, array $over) use (&$merge): array {
            foreach ($over as $key => $value) {
                $base[$key] = (is_array($value) && isset($base[$key]) && is_array($base[$key]))
                    ? $merge($base[$key], $value)
                    : $value;
            }
            return $base;
        };
        $config = $merge($config, $local);
    }
}

App\Core\Config::load($config);

date_default_timezone_set(App\Core\Config::get('app.timezone', 'Asia/Manila'));

// ---------------------------------------------------------------------------
// Error handling — never leak internals to the browser (docs/00, docs/18)
// ---------------------------------------------------------------------------
$debug = (bool) App\Core\Config::get('app.debug', false);

error_reporting(E_ALL);
ini_set('display_errors', $debug && IS_CLI ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', STORAGE_PATH . '/logs/php-error.log');

require APP_PATH . '/Helpers/functions.php';

if (!IS_CLI) {
    set_exception_handler([App\Core\ErrorHandler::class, 'handleException']);
    set_error_handler([App\Core\ErrorHandler::class, 'handleError']);
    register_shutdown_function([App\Core\ErrorHandler::class, 'handleShutdown']);

    App\Core\Session::start();
}
