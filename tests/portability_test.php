<?php
/**
 * What a Linux host needs that Windows forgives (docs/23). Windows finds "Views/Auth/Login.php"
 * when the code asks for "views/auth/login.php"; Linux file names are case-sensitive, so the
 * same code would fail only after it is deployed. These read the source and compare every
 * referenced class, template, asset, and required file with the real file names.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;

/** @return array<string,true> every file and folder under the project, by exact relative path */
function projectPaths(): array
{
    static $paths = null;
    if ($paths !== null) {
        return $paths;
    }
    $paths = [];
    foreach (['app', 'config', 'database', 'public', 'routes', 'tests'] as $top) {
        $paths[$top] = true;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH . '/' . $top, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            $paths[substr(str_replace('\\', '/', $file->getPathname()), strlen(BASE_PATH) + 1)] = true;
        }
    }
    $paths['bootstrap.php'] = true;
    return $paths;
}

/** A problem for a referenced relative path: null when it exists with exactly this spelling. */
function pathProblem(string $relative): ?string
{
    $paths = projectPaths();
    if (isset($paths[$relative])) {
        return null;
    }
    foreach (array_keys($paths) as $real) {
        if (strcasecmp($real, $relative) === 0) {
            return "{$relative} is spelled {$real} on disk";
        }
    }
    return "{$relative} does not exist";
}

/** @return array<string,string> relative path => source of the PHP files that can reference others */
function portableSources(): array
{
    $sources = [];
    foreach (projectPaths() as $path => $_) {
        if (str_ends_with($path, '.php') && !str_starts_with($path, 'tests/perf/')) {
            $sources[$path] = (string) file_get_contents(BASE_PATH . '/' . $path);
        }
    }
    return $sources + ['bootstrap.php' => (string) file_get_contents(BASE_PATH . '/bootstrap.php')];
}

return [
    'every class, template, asset, and required file is named with the exact letter case on disk' => function (TestRunner $t): void {
        $problems = [];
        $checked = 0;
        $paths = projectPaths();

        foreach (portableSources() as $path => $source) {
            // Classes: App\Foo\Bar lives in app/Foo/Bar.php. A reference that is a folder is a namespace.
            preg_match_all('/\bApp\\\\([A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)*)/', $path === 'tests/portability_test.php' ? '' : $source, $classes);
            foreach (array_unique($classes[1]) as $class) {
                $relative = 'app/' . str_replace('\\', '/', rtrim($class, '\\'));
                $checked++;
                if (!isset($paths[$relative . '.php']) && !isset($paths[$relative])) {
                    $problems[] = "{$path}: class App\\{$class}: " . pathProblem($relative . '.php');
                }
            }

            // Templates rendered by name.
            $templates = [];
            preg_match_all('/(?:appView|authView|printView|publicView|View::render|View::partial)\(\s*\'([a-z0-9\/_-]*[a-z0-9_-])\'\s*[,)]/i', $source, $m);
            $templates = array_merge($templates, $m[1]);
            preg_match_all('/\'(layouts\/[a-z0-9_-]+)\'/i', $source, $m);
            $templates = array_merge($templates, $m[1]);
            preg_match_all('/(?<![:>])\bpartial\(\s*\'([a-z0-9\/_-]*[a-z0-9_-])\'\s*[,)]/i', $source, $m);
            $templates = array_merge($templates, array_map(static fn ($name) => 'partials/' . $name, $m[1]));
            preg_match_all('/\bcomponent\(\s*\'([a-z0-9\/_-]*[a-z0-9_-])\'\s*[,)]/i', $source, $m);
            $templates = array_merge($templates, array_map(static fn ($name) => 'components/' . $name, $m[1]));
            foreach (array_unique($templates) as $template) {
                $checked++;
                if (($problem = pathProblem('app/Views/' . $template . '.php')) !== null) {
                    $problems[] = "{$path}: template {$template}: {$problem}";
                }
            }

            // Files under public/.
            preg_match_all('/\basset\(\s*\'([^\']+)\'/', $source, $m);
            foreach (array_unique($m[1]) as $asset) {
                $checked++;
                if (($problem = pathProblem('public/' . ltrim($asset, '/'))) !== null) {
                    $problems[] = "{$path}: asset {$asset}: {$problem}";
                }
            }

            // require / include with a literal path.
            preg_match_all('/\b(?:require|require_once|include|include_once)\s*\(?\s*(__DIR__|BASE_PATH|APP_PATH|dirname\(__DIR__(?:,\s*(\d))?\))\s*\.\s*\'([^\']+)\'/', $source, $m, PREG_SET_ORDER);
            foreach ($m as [, $anchor, $levels, $tail]) {
                $dir = dirname($path);
                $base = match (true) {
                    $anchor === 'BASE_PATH' => '',
                    $anchor === 'APP_PATH' => 'app',
                    $anchor === '__DIR__' => $dir,
                    default => dirname($dir, $levels === '' ? 1 : (int) $levels),
                };
                $relative = ltrim(($base === '.' ? '' : $base) . $tail, '/');
                $checked++;
                if (($problem = pathProblem($relative)) !== null) {
                    $problems[] = "{$path}: require {$tail}: {$problem}";
                }
            }
        }

        $t->assertTrue($checked > 500, "Checked the references ({$checked})");
        $t->assertEquals([], $problems, 'References that would fail on a case-sensitive file system');
    },

    'behind a trusted proxy the visitor address and HTTPS come from its headers; from anyone else they are ignored' => function (TestRunner $t): void {
        $server = $_SERVER;
        $proxies = App\Core\Config::get('app.trusted_proxies');
        try {
            $t->assertTrue(App\Core\Request::ipInRanges('173.245.48.7', ['173.245.48.0/20']), 'IPv4 range');
            $t->assertFalse(App\Core\Request::ipInRanges('173.245.64.1', ['173.245.48.0/20']), 'Outside the range');
            $t->assertTrue(App\Core\Request::ipInRanges('2400:cb00:12::1', ['2400:cb00::/32']), 'IPv6 range');
            $t->assertTrue(App\Core\Request::ipInRanges('10.0.0.5', ['10.0.0.5']), 'A single address');
            $t->assertFalse(App\Core\Request::ipInRanges('not-an-ip', ['0.0.0.0/0']), 'Garbage is never in a range');

            $_SERVER['REMOTE_ADDR'] = '10.0.0.5';
            $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.23, 10.0.0.9';
            $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
            unset($_SERVER['HTTPS']);

            App\Core\Config::set('app.trusted_proxies', []);
            $t->assertEquals('10.0.0.5', (new App\Core\Request())->ip(), 'With no trusted proxy the header is ignored');
            $t->assertFalse((new App\Core\Request())->isSecure(), 'And so is the forwarded protocol');

            App\Core\Config::set('app.trusted_proxies', ['10.0.0.0/8']);
            $t->assertEquals('198.51.100.23', (new App\Core\Request())->ip(), 'The right-most address not added by a trusted proxy');
            $t->assertTrue((new App\Core\Request())->isSecure(), 'HTTPS ended at the proxy');

            $_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6, 198.51.100.23';
            $t->assertEquals('198.51.100.23', (new App\Core\Request())->ip(), 'A visitor cannot pick an address by sending the header themselves');

            $_SERVER['REMOTE_ADDR'] = '203.0.113.50';
            $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
            $t->assertEquals('203.0.113.50', (new App\Core\Request())->ip(), 'A request that did not come through the proxy keeps its own address');
            $t->assertFalse((new App\Core\Request())->isSecure(), 'And cannot claim HTTPS');
        } finally {
            $_SERVER = $server;
            App\Core\Config::set('app.trusted_proxies', $proxies);
        }
    },

    'in production with an https address, a plain-HTTP request is sent to HTTPS' => function (TestRunner $t): void {
        $server = $_SERVER;
        $original = ['app.env' => App\Core\Config::get('app.env'), 'app.base_url' => App\Core\Config::get('app.base_url')];
        try {
            $request = makeRequest('GET', '/login?next=%2Fstudent');
            unset($_SERVER['HTTPS']);
            App\Core\Config::set('app.env', 'local');
            App\Core\Config::set('app.base_url', 'https://enroll.lyceum.edu.ph');
            $t->assertEquals(null, App\Core\Response::httpsRedirectTarget($request), 'Not outside production');

            App\Core\Config::set('app.env', 'production');
            $t->assertEquals('https://enroll.lyceum.edu.ph/login?next=%2Fstudent', App\Core\Response::httpsRedirectTarget($request), 'Same page and query, on HTTPS');
            $_SERVER['HTTPS'] = 'on';
            $t->assertEquals(null, App\Core\Response::httpsRedirectTarget($request), 'Already HTTPS');
            unset($_SERVER['HTTPS']);
            App\Core\Config::set('app.base_url', 'http://192.168.1.10/SchoolHub/public');
            $t->assertEquals(null, App\Core\Response::httpsRedirectTarget($request), 'Not when the site address is http://');
        } finally {
            $_SERVER = $server;
            foreach ($original as $key => $value) {
                App\Core\Config::set($key, $value);
            }
        }
    },

    'the database session uses MySQL 8 strict mode and the application time zone' => function (TestRunner $t): void {
        $session = Database::selectOne('SELECT @@SESSION.sql_mode AS mode, @@SESSION.time_zone AS zone, NOW() AS now');
        foreach (['ONLY_FULL_GROUP_BY', 'STRICT_TRANS_TABLES', 'NO_ZERO_DATE', 'ERROR_FOR_DIVISION_BY_ZERO'] as $flag) {
            $t->assertTrue(str_contains((string) $session['mode'], $flag), "sql_mode includes {$flag}");
        }
        $t->assertEquals(date('P'), $session['zone'], 'The database clock follows the application time zone');
        $t->assertTrue(abs(strtotime((string) $session['now']) - time()) < 5, 'NOW() agrees with PHP');
    },
];
