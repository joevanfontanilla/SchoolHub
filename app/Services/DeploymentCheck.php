<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Models\User;

/**
 * The deployment preflight (docs/23): everything that must be true before SchoolHub is
 * opened to students on the school's Windows/XAMPP server. Run by
 * database/check_deployment.php. Read-only.
 *
 * Each check is OK, WARN, or FAIL, in the same shape as SystemHealth, whose runtime
 * checks (PHP, database, storage, backups) are included.
 */
final class DeploymentCheck
{
    /** Accounts made by database/create_demo_users.php; they have well-known usernames. */
    public const DEMO_USERNAMES = ['student', 'teacher', 'registrar', 'cashier'];

    /** Files that must never be served, relative to the project root. */
    public const PRIVATE_FILES = [
        '.git/HEAD', '.htaccess', 'config/config.php', 'config/config.local.php', 'bootstrap.php',
        'storage/logs/php-error.log', 'storage/backups/', 'storage/uploads/', 'database/seed.php',
        'docs/00_MASTER_SPECIFICATION.md', 'tests/run.php', 'README.md', 'CLAUDE.md', 'tailwindcss.exe',
    ];

    /**
     * @param array<string,mixed> $override config values by dotted key, for tests
     * @return array<int,array{group:string,label:string,status:string,detail:string}>
     */
    public static function checks(array $override = []): array
    {
        return array_merge(
            self::configChecks($override),
            self::phpChecks(),
            self::databaseChecks(),
            self::dataChecks(),
            self::fileChecks(),
            array_values(array_filter(
                SystemHealth::checks(self::healthOverride($override)),
                static fn (array $check) => !in_array($check['group'], ['Application', 'Security'], true)
            ))
        );
    }

    /** @return array<int,array{group:string,label:string,status:string,detail:string}> */
    public static function configChecks(array $override = []): array
    {
        $get = static fn (string $key, mixed $default = null): mixed => array_key_exists($key, $override) ? $override[$key] : Config::get($key, $default);
        $checks = [];
        $add = self::adder($checks, 'Configuration');

        $add('config/config.local.php', is_file(BASE_PATH . '/config/config.local.php') ? 'OK' : 'FAIL',
            is_file(BASE_PATH . '/config/config.local.php') ? 'Present' : 'Missing: copy config/config.local.example.php and set the production values.');

        $env = (string) $get('app.env', 'local');
        $add('Environment', $env === 'production' ? 'OK' : 'FAIL', $env === 'production' ? 'production' : "\"{$env}\": set app.env to production.");

        $debug = (bool) $get('app.debug', false);
        $add('Debug mode', $debug ? 'FAIL' : 'OK', $debug ? 'On: error details would be shown to visitors. Set app.debug to false.' : 'Off');

        $baseUrl = (string) $get('app.base_url', '');
        $add('Site address (app.base_url)', preg_match('#^https://[^/]+#i', $baseUrl) ? 'OK' : 'FAIL',
            $baseUrl === '' ? 'Not set: password links would use whatever host name the visitor typed. Set the full https:// address.'
                : (str_starts_with(strtolower($baseUrl), 'https://') ? $baseUrl : "{$baseUrl}: the address must start with https://"));

        $secure = (bool) $get('session.secure', false);
        $add('Secure session cookie', $secure ? 'OK' : 'FAIL', $secure ? 'On' : 'Off: set session.secure to true so the sign-in cookie is sent only over HTTPS.');

        $user = (string) $get('db.username', '');
        $password = (string) $get('db.password', '');
        $add('Database account', $user === '' || strtolower($user) === 'root' ? 'FAIL' : 'OK',
            $user === '' || strtolower($user) === 'root' ? 'The application signs in to MariaDB as root. Create a dedicated account (docs/23).' : $user);
        $add('Database password', $password === '' ? 'FAIL' : (strlen($password) < 16 ? 'WARN' : 'OK'),
            $password === '' ? 'Empty.' : (strlen($password) < 16 ? 'Shorter than 16 characters.' : 'Set'));

        $timeout = (int) $get('session.idle_timeout', 1800);
        $add('Idle sign-out', $timeout > 3600 ? 'WARN' : 'OK', intdiv($timeout, 60) . ' minutes' . ($timeout > 3600 ? ': longer than an hour on shared school computers.' : ''));

        return $checks;
    }

    /** php.ini values. XAMPP's Apache and command line read the same C:\xampp\php\php.ini. */
    public static function phpChecks(): array
    {
        $checks = [];
        $add = self::adder($checks, 'PHP settings');

        $maxUpload = (int) Config::get('uploads.max_size_kb', 5120) * 1024;
        $upload = self::iniBytes((string) ini_get('upload_max_filesize'));
        $post = self::iniBytes((string) ini_get('post_max_size'));
        $add('upload_max_filesize', $upload >= $maxUpload ? 'OK' : 'FAIL', ini_get('upload_max_filesize') . ($upload >= $maxUpload ? '' : ': smaller than the ' . SystemHealth::bytes($maxUpload) . ' SchoolHub accepts, so uploads would fail.'));
        // A form carries its fields as well as the file, so leave a megabyte of room.
        $postOk = $post >= $maxUpload + 1024 ** 2;
        $add('post_max_size', $postOk ? 'OK' : 'FAIL', ini_get('post_max_size') . ($postOk ? '' : ': must be at least 1 MB larger than the ' . SystemHealth::bytes($maxUpload) . ' upload limit.'));
        $add('display_errors', filter_var(get_cfg_var('display_errors'), FILTER_VALIDATE_BOOLEAN) ? 'WARN' : 'OK',
            filter_var(get_cfg_var('display_errors'), FILTER_VALIDATE_BOOLEAN) ? 'On in php.ini. SchoolHub turns it off for web pages, but set display_errors = Off so a startup error cannot print paths.' : 'Off');
        $add('session.use_strict_mode', ini_get('session.use_strict_mode') === '1' ? 'OK' : 'WARN',
            ini_get('session.use_strict_mode') === '1' ? 'On' : 'Off: set session.use_strict_mode = 1 so the server refuses session ids it did not issue.');
        $add('expose_php', filter_var(ini_get('expose_php'), FILTER_VALIDATE_BOOLEAN) ? 'WARN' : 'OK',
            filter_var(ini_get('expose_php'), FILTER_VALIDATE_BOOLEAN) ? 'On: set expose_php = Off (SchoolHub removes the header, Apache error pages do not).' : 'Off');

        return $checks;
    }

    /** The MariaDB server: who the application connects as, and who else can connect. */
    public static function databaseChecks(): array
    {
        $checks = [];
        $add = self::adder($checks, 'MariaDB');
        try {
            $variables = array_column(Database::select("SHOW VARIABLES WHERE Variable_name IN ('bind_address', 'skip_networking')"), 'Value', 'Variable_name');
            $bind = (string) ($variables['bind_address'] ?? '');
            $localOnly = in_array($bind, ['127.0.0.1', 'localhost', '::1'], true) || strtoupper((string) ($variables['skip_networking'] ?? '')) === 'ON';
            $add('Network access', $localOnly ? 'OK' : 'FAIL', $localOnly ? 'Only this computer can connect'
                : 'MariaDB accepts connections from the network' . ($bind !== '' ? " ({$bind})" : '') . '. Set bind-address=127.0.0.1 in C:\xampp\mysql\bin\my.ini and restart MySQL.');

            $grants = implode("\n", array_map(static fn (array $row) => (string) reset($row), Database::select('SHOW GRANTS')));
            $global = self::hasServerPrivileges($grants);
            $add('Account privileges', $global ? 'FAIL' : 'OK', $global
                ? 'The application account has server-wide or administrative privileges. Grant it only the SchoolHub database (docs/23).'
                : 'Limited to the SchoolHub database');
        } catch (\Throwable) {
            $add('Server', 'FAIL', 'The database cannot be reached.');
        }
        return $checks;
    }

    /**
     * Whether SHOW GRANTS output gives more than one database: any privilege on *.* other
     * than USAGE (which only means "may sign in"), or the right to grant privileges.
     */
    public static function hasServerPrivileges(string $grants): bool
    {
        foreach (preg_split('/\R/', $grants) ?: [] as $line) {
            if (preg_match('/WITH GRANT OPTION/i', $line) || (preg_match('/^GRANT (.+?) ON \*\.\* TO /i', $line, $m) && strtoupper(trim($m[1])) !== 'USAGE')) {
                return true;
            }
        }
        return false;
    }

    /** Accounts and reference data. */
    public static function dataChecks(): array
    {
        $checks = [];
        $add = self::adder($checks, 'Accounts and data');
        try {
            $admins = (int) Database::scalar(
                "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = ? AND u.status = '" . User::STATUS_ACTIVE . "'",
                [User::ROLE_SUPER_ADMIN]
            );
            $add('Super Admin', $admins > 0 ? 'OK' : 'FAIL', $admins > 0 ? "{$admins} active" : 'None: run php database/create_admin.php');

            $samples = self::sampleAccounts();
            $add('Demo and test accounts', $samples === [] ? 'OK' : 'FAIL', $samples === [] ? 'None'
                : count($samples) . ' found (' . implode(', ', array_slice($samples, 0, 5)) . (count($samples) > 5 ? ', …' : '') . '). Disable them (Administration → Users) before going live; their passwords are known.');

            $roles = (int) Database::scalar('SELECT COUNT(*) FROM roles');
            $add('Roles and permissions', $roles >= 5 ? 'OK' : 'FAIL', $roles >= 5 ? "{$roles} roles" : 'Not seeded: run php database/seed.php');

            $sampleFees = (int) Database::scalar("SELECT COUNT(*) FROM fee_types WHERE is_active = 1 AND description LIKE 'Sample amount%'");
            $add('Fee amounts', $sampleFees === 0 ? 'OK' : 'WARN', $sampleFees === 0 ? 'Set by the school'
                : "{$sampleFees} fee types still carry the sample amounts from the seeder. The cashier sets the real fees in Fees.");
        } catch (\Throwable) {
            $add('Accounts', 'FAIL', 'The database cannot be reached.');
        }
        return $checks;
    }

    /**
     * Usernames of accounts that must not exist in production: the demo accounts, test
     * fixtures left by an interrupted test run, and performance-test users.
     *
     * @return array<int,string>
     */
    public static function sampleAccounts(): array
    {
        $in = implode(', ', array_fill(0, count(self::DEMO_USERNAMES), '?'));
        return array_column(Database::select(
            "SELECT username FROM users
              WHERE status <> '" . User::STATUS_DISABLED . "'
                AND (username IN ({$in}) OR email LIKE '%@schoolhub.test' OR username LIKE '\\_\\_test\\_%' OR username LIKE 'perf\\_%')
              ORDER BY username",
            self::DEMO_USERNAMES
        ), 'username');
    }

    /** Files the web server depends on. */
    public static function fileChecks(): array
    {
        $checks = [];
        $add = self::adder($checks, 'Files');

        foreach (['.htaccess' => 'refuses everything outside public/', 'public/.htaccess' => 'sends every page to index.php', 'storage/.htaccess' => 'refuses uploads, logs, and backups'] as $file => $purpose) {
            $add($file, is_file(BASE_PATH . '/' . $file) ? 'OK' : 'FAIL', is_file(BASE_PATH . '/' . $file) ? ucfirst($purpose) : "Missing: it {$purpose}.");
        }

        $extra = array_values(array_diff(array_map('basename', glob(BASE_PATH . '/public/*.php') ?: []), ['index.php']));
        $add('PHP files in public/', $extra === [] ? 'OK' : 'FAIL', $extra === [] ? 'Only index.php' : 'Remove: ' . implode(', ', $extra));

        // The stylesheet is committed, so a release carries it; the server has no Tailwind binary.
        $css = BASE_PATH . '/public/assets/app.css';
        $built = is_file($css) && filesize($css) > 10000;
        $add('Compiled stylesheet', $built ? 'OK' : 'FAIL', $built ? SystemHealth::bytes((int) filesize($css)) : 'public/assets/app.css is missing: build it with tailwindcss.exe --minify and copy it over.');
        return $checks;
    }

    /**
     * Checks against the running site: pages, headers, cookies, the HTTPS redirect, and
     * that no private file can be downloaded.
     *
     * @return array<int,array{group:string,label:string,status:string,detail:string}>
     */
    public static function httpChecks(string $baseUrl, bool $verifyTls = true): array
    {
        $checks = [];
        $add = self::adder($checks, 'Live site');
        $baseUrl = rtrim($baseUrl, '/') . '/';

        $home = self::fetch($baseUrl . 'login', $verifyTls);
        if ($home['status'] === 0) {
            $add('Sign-in page', 'FAIL', "{$baseUrl}login could not be reached: {$home['error']}");
            return $checks;
        }
        $add('Sign-in page', $home['status'] === 200 ? 'OK' : 'FAIL', "HTTP {$home['status']}");
        if (!$verifyTls && str_starts_with($baseUrl, 'https://')) {
            $add('Certificate', 'WARN', 'Not verified (--insecure). Browsers will warn unless the school installs the certificate on its computers.');
        }

        $header = static fn (string $name): string => implode(', ', $home['headers'][strtolower($name)] ?? []);
        $missing = array_values(array_filter(['Content-Security-Policy', 'X-Frame-Options', 'X-Content-Type-Options', 'Referrer-Policy'], static fn ($name) => $header($name) === ''));
        $add('Security headers', $missing === [] ? 'OK' : 'FAIL', $missing === [] ? 'Present' : 'Missing: ' . implode(', ', $missing));
        $add('Debug headers', $header('X-Debug-Queries') === '' ? 'OK' : 'FAIL', $header('X-Debug-Queries') === '' ? 'Not sent' : 'Sent: debug mode is on.');
        $add('Server version headers', $header('X-Powered-By') === '' ? 'OK' : 'WARN', $header('X-Powered-By') === '' ? 'Not sent' : 'X-Powered-By: ' . $header('X-Powered-By'));

        $cookie = array_values(array_filter($home['headers']['set-cookie'] ?? [], static fn ($c) => str_starts_with($c, Config::get('session.name', 'schoolhub_session') . '=')))[0] ?? '';
        if ($cookie === '') {
            $add('Session cookie', 'WARN', 'The sign-in page set no session cookie to inspect.');
        } else {
            $flags = array_filter(['Secure' => '/;\s*secure/i', 'HttpOnly' => '/;\s*httponly/i', 'SameSite' => '/;\s*samesite=(lax|strict)/i'], static fn ($pattern) => !preg_match($pattern, $cookie));
            $add('Session cookie', $flags === [] ? 'OK' : 'FAIL', $flags === [] ? 'Secure, HttpOnly, SameSite' : 'Missing: ' . implode(', ', array_keys($flags)));
        }

        if (str_starts_with($baseUrl, 'https://')) {
            $add('Strict-Transport-Security', $header('Strict-Transport-Security') !== '' ? 'OK' : 'WARN', $header('Strict-Transport-Security') ?: 'Not sent: turn on session.secure.');
            $plain = self::fetch('http://' . substr($baseUrl, 8) . 'login', $verifyTls);
            $redirects = in_array($plain['status'], [301, 302, 307, 308], true) && str_starts_with(strtolower($plain['location']), 'https://');
            $add('HTTP redirects to HTTPS', $redirects ? 'OK' : 'WARN', $redirects ? "HTTP {$plain['status']} to {$plain['location']}"
                : ($plain['status'] === 0 ? 'Port 80 does not answer (acceptable if it is closed in the firewall).' : "http:// answers with HTTP {$plain['status']} instead of redirecting."));
        } else {
            $add('HTTPS', 'FAIL', 'The site address is http://. Passwords and sessions would cross the network unencrypted.');
        }

        $exposed = [];
        foreach (self::probeUrls($baseUrl) as $url) {
            $response = self::fetch($url, $verifyTls, 4096);
            if (in_array($response['status'], [200, 206], true) && !self::isApplicationPage($response['body'])) {
                $exposed[] = $url;
            }
        }
        $add('Private files', $exposed === [] ? 'OK' : 'FAIL', $exposed === [] ? count(self::probeUrls($baseUrl)) . ' probes refused'
            : 'Downloadable: ' . implode(', ', array_slice($exposed, 0, 4)) . (count($exposed) > 4 ? ', …' : ''));

        return $checks;
    }

    /**
     * The private files, tried under the site address and under every folder above it:
     * with XAMPP's htdocs layout the project root is one or two levels up from public/.
     *
     * @return array<int,string>
     */
    public static function probeUrls(string $baseUrl): array
    {
        $parts = parse_url(rtrim($baseUrl, '/') . '/');
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $segments = array_values(array_filter(explode('/', (string) ($parts['path'] ?? '/')), 'strlen'));
        $urls = [];
        for ($depth = count($segments); $depth >= 0; $depth--) {
            $prefix = $origin . '/' . implode('/', array_slice($segments, 0, $depth));
            foreach (self::PRIVATE_FILES as $file) {
                $urls[] = rtrim($prefix, '/') . '/' . $file;
            }
        }
        return array_values(array_unique($urls));
    }

    /** "40M" => bytes, as php.ini reads it. */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return PHP_INT_MAX;
        }
        $number = (int) $value;
        return match (strtoupper(substr($value, -1))) {
            'G' => $number * 1024 ** 3,
            'M' => $number * 1024 ** 2,
            'K' => $number * 1024,
            default => $number,
        };
    }

    /** SchoolHub's own not-found page, which is a normal 404; a 200 here would be the app itself. */
    private static function isApplicationPage(string $body): bool
    {
        return str_contains($body, '<html') && str_contains($body, 'id="main"');
    }

    /** @return array{status:int,headers:array<string,array<int,string>>,body:string,location:string,error:string} */
    private static function fetch(string $url, bool $verifyTls, int $maxBytes = 1048576): array
    {
        $curl = curl_init($url);
        $headers = [];
        $body = '';
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => $verifyTls,
            CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))][] = trim($value);
                }
                return strlen($line);
            },
            // Stop after a few kilobytes: enough to recognize a page, without downloading a large file.
            CURLOPT_WRITEFUNCTION  => static function ($c, string $chunk) use (&$body, $maxBytes): int {
                $body .= $chunk;
                return strlen($body) > $maxBytes ? 0 : strlen($chunk);
            },
        ]);
        curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($curl) === CURLE_WRITE_ERROR ? '' : curl_error($curl);
        curl_close($curl);
        return ['status' => $status, 'headers' => $headers, 'body' => $body, 'location' => (string) ($headers['location'][0] ?? ''), 'error' => $error];
    }

    private static function healthOverride(array $override): ?array
    {
        $keys = array_intersect_key($override, array_flip(['app.env', 'app.debug', 'session.secure']));
        return $keys === [] ? null : $keys;
    }

    private static function adder(array &$checks, string $group): \Closure
    {
        return static function (string $label, string $status, string $detail) use (&$checks, $group): void {
            $checks[] = compact('group', 'label', 'status', 'detail');
        };
    }
}
