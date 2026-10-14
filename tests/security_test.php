<?php
/**
 * Security rules checked across the whole code base (docs/07, docs/22). These read the
 * source, so a change that breaks a rule fails here even where no other test looks.
 * The live checks (every route over HTTP, injection payloads, CSRF on every POST,
 * exposed files) are in tests/http/crawl.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Config;
use App\Core\ErrorHandler;

/** @return array<string,string> relative path => source, for PHP files under a folder */
function sourceFiles(string $folder): array
{
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH . '/' . $folder, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') {
            $files[substr(str_replace('\\', '/', $file->getPathname()), strlen(BASE_PATH) + 1)] = (string) file_get_contents($file->getPathname());
        }
    }
    return $files;
}

/** Replace calls to the named functions (with balanced parentheses) by SAFE. */
function withoutCalls(string $expression, array $names): string
{
    $out = '';
    $length = strlen($expression);
    for ($i = 0; $i < $length; $i++) {
        foreach ($names as $name) {
            $n = strlen($name);
            if (substr($expression, $i, $n + 1) === $name . '(' && ($i === 0 || !preg_match('/[A-Za-z0-9_>:$\\\\]/', $expression[$i - 1]))) {
                $depth = 0;
                for ($j = $i + $n; $j < $length; $j++) {
                    $depth += $expression[$j] === '(' ? 1 : ($expression[$j] === ')' ? -1 : 0);
                    if ($depth === 0) {
                        break;
                    }
                }
                $out .= 'SAFE';
                $i = $j;
                continue 2;
            }
        }
        $out .= $expression[$i];
    }
    return $out;
}

return [
    'every value a view prints is escaped, or is one of the reviewed safe forms' => function (TestRunner $t): void {
        // Reviewed forms that print no untrusted text: integers, badge and icon components,
        // small closures that escape inside, fixed lookups, and "selected" ternaries.
        $reviewed = [
            '/^\(int\)/',
            '/^\$(badge|statusBadge|tone|auditRows|groupError|select|inner)\(/',
            '/^\$inner$/',
            '/^\$(tones|icons|barColors|swatches)\[/',
            '/^\$record\[\'counts\'\]\[\$status\]$/',
            '/^match \(\$value\)/',
            '/^nl2br\(e\(/',
            '/^date\(\'Y\'\)$/',
            '/\? \'selected\' : \'\'$/',
            '/^Finance::belowPolicy\(\$row\) \? \', enrolled below policy\'/',
            '/^\$open \? \'Close\'/',
            '/^\$policy === PaymentPolicy::FULL \?/',
            // The e-ID QR: an SVG QrCode::svg() builds from integers and an escaped label, never from raw input.
            '/^\$card\[\'qrSvg\'\]$/',
        ];
        $safeCalls = ['e', 'component', 'partial', 'icon', 'csrf_field', 'count', 'number_format', 'round', 'money', 'signed_money'];
        $unreviewed = [];
        foreach (sourceFiles('app/Views') as $path => $source) {
            preg_match_all('/<\?=\s*(.*?)\s*\?>/s', $source, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[1] as [$expression, $offset]) {
                $expression = preg_replace('/\s+/', ' ', trim($expression));
                if (array_filter($reviewed, static fn ($pattern) => preg_match($pattern, $expression) === 1) !== []) {
                    continue;
                }
                $rest = withoutCalls($expression, $safeCalls);
                // In "condition ? a : b" only a and b are printed.
                $printed = str_contains($rest, '?') ? preg_replace('/^.*?\?/s', '', $rest) : $rest;
                if (preg_match('/\$[A-Za-z_]+(\[|->)|[A-Za-z_]+\(/', $printed)) {
                    $unreviewed[] = $path . ':' . (substr_count(substr($source, 0, $offset), "\n") + 1) . '  ' . $expression;
                }
            }
        }
        $t->assertEquals([], $unreviewed, "Print record data only through e() (or add a reviewed safe form):\n" . implode("\n", $unreviewed));
    },

    'every POST form carries a CSRF token' => function (TestRunner $t): void {
        $missing = [];
        foreach (sourceFiles('app/Views') as $path => $source) {
            preg_match_all('/<form\b[^>]*method="POST"[^>]*>(.*?)<\/form>/is', $source, $forms, PREG_OFFSET_CAPTURE);
            foreach ($forms[1] as [$body, $offset]) {
                if (!str_contains($body, 'csrf_field()')) {
                    $missing[] = $path . ':' . (substr_count(substr($source, 0, $offset), "\n") + 1);
                }
            }
        }
        $t->assertEquals([], $missing, 'POST forms without csrf_field(): ' . implode(', ', $missing));
    },

    'controllers hold no SQL, and models and services never read request globals' => function (TestRunner $t): void {
        $sqlInControllers = array_keys(array_filter(sourceFiles('app/Controllers'), static fn ($s) => preg_match('/Database::|\b(SELECT|INSERT INTO|DELETE FROM)\s/', $s) === 1));
        $t->assertEquals([], $sqlInControllers, 'Controllers with SQL: ' . implode(', ', $sqlInControllers));

        $globals = array_keys(array_filter(sourceFiles('app/Models') + sourceFiles('app/Services'), static fn ($s) => preg_match('/\$_(GET|POST|REQUEST|COOKIE|FILES)\b/', $s) === 1));
        $t->assertEquals([], $globals, 'Models or services reading request globals: ' . implode(', ', $globals));
    },

    'no dangerous functions or leftover debugging output in the application' => function (TestRunner $t): void {
        $found = [];
        foreach (sourceFiles('app') + sourceFiles('routes') + sourceFiles('public') as $path => $source) {
            $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);
            if (preg_match_all('/(?<![\w>:$])(eval|shell_exec|system|passthru|proc_open|popen|unserialize|var_dump|print_r|md5|phpinfo)\s*\(|(?<![\w>:$])exec\s*\(/', $code, $m)) {
                $found[] = $path . ' (' . implode(', ', array_unique($m[0])) . ')';
            }
            if ($path !== 'app/Core/View.php' && preg_match('/(?<![\w>:$])extract\s*\(/', $code)) {
                $found[] = $path . ' (extract)';
            }
        }
        $t->assertEquals([], $found, 'Found: ' . implode('; ', $found));
    },

    'SQL strings interpolate only reviewed identifiers, placeholder lists, and integers, never request values' => function (TestRunner $t): void {
        // Every variable placed inside a double-quoted SQL string, found with PHP's tokenizer.
        // Each was reviewed: table or column names chosen in code, "?, ?, ?" placeholder
        // lists, WHERE fragments assembled from fixed text with bound values, SQL aliases,
        // and integers cast before use. A new one must be reviewed and added, or bound with ?.
        $reviewed = [
            'AcademicRecordService.php' => ['$in'],
            'AdminReport.php'           => ['$in', '$range'],
            'Announcement.php'          => ['$a', '$u', '$column', '$type', '$in', '$limit', '$offset', '$stateSql', '$where'],
            'AnnouncementService.php'   => ['$in', '$table'],
            'AuditLog.php'              => ['$join', '$limit', '$offset', '$where'],
            'BackupService.php'         => ['$columns', '$keep', '$quoted', '$where'],
            'Catalog.php'               => ['$table'],
            'DeploymentCheck.php'       => ['$in'],
            'CatalogService.php'        => ['$table'],
            'ClassSchedule.php'         => ['$in'],
            'CredentialRequest.php'     => ['$in', '$where'],
            'EnrollmentApplication.php' => ['$placeholders'],
            'Finance.php'               => ['$limit', '$offset'],
            // accountList(): "?, ?, ?" placeholder lists from array_fill, and a column name from a fixed array in code.
            'FinanceReport.php'         => ['$in', '$appIn', '$termIn', '$column'],
            'InstallmentReminderService.php' => ['$in'],
            // report(): "?, ?, ?" placeholder lists from array_fill, and a WHERE fragment assembled only from fixed strings in code.
            'PaymentBreakdownService.php' => ['$in', '$scope', '$filter'],
            'Notification.php'          => ['$limit', '$offset', '$where'],
            'RegistrarQueue.php'        => ['$where', '$whereSql'],
            'RegistrarReport.php'       => ['$programFilter', '$whereSql'],
            'User.php'                  => ['$limit', '$offset', '$sqlWhere'],
            'UserAdminService.php'      => ['$field'],
            'Validator.php'             => ['$column', '$table'],
        ];
        $unreviewed = [];
        foreach (sourceFiles('app') as $path => $source) {
            $inString = false;
            $literal = '';
            $vars = [];
            foreach (token_get_all($source) as $token) {
                if ($token === '"') {
                    if ($inString && preg_match('/\b(SELECT|INSERT INTO|UPDATE|DELETE FROM|WHERE|ORDER BY|LIMIT|JOIN)\b/', $literal)) {
                        foreach (array_diff(array_unique($vars), $reviewed[basename($path)] ?? []) as $var) {
                            $unreviewed[] = "{$path}: {$var}";
                        }
                    }
                    $inString = !$inString;
                    $literal = '';
                    $vars = [];
                    continue;
                }
                if ($inString && is_array($token)) {
                    $token[0] === T_ENCAPSED_AND_WHITESPACE && $literal .= $token[1];
                    $token[0] === T_VARIABLE && $vars[] = $token[1];
                }
            }
        }
        $t->assertEquals([], array_values(array_unique($unreviewed)), "Unreviewed variables inside SQL strings (bind values with ?):\n" . implode("\n", array_unique($unreviewed)));
    },

    'an unexpected error shows a generic message and no internals when debug is off' => function (TestRunner $t): void {
        $debug = Config::get('app.debug');
        Config::set('app.debug', false);
        try {
            $error = new RuntimeException('secret-internal-detail in C:\\xampp\\htdocs\\SchoolHub\\app\\Secret.php');
            [$status, $message, $detail] = ErrorHandler::describe($error);
            $t->assertEquals(500, $status);
            $t->assertFalse(str_contains($message, 'secret-internal-detail'), 'The exception message is not shown');
            $t->assertNull($detail, 'No stack trace or file path');
            $t->assertContains('An unexpected error occurred', $message);
            [$status, $message] = ErrorHandler::describe(App\Core\HttpException::forbidden('You may not do that.'));
            $t->assertEquals([403, 'You may not do that.'], [$status, $message], 'Deliberate refusals keep their plain message');
        } finally {
            Config::set('app.debug', $debug);
        }
    },

    'every response carries the security headers, including a strict script policy' => function (TestRunner $t): void {
        $source = (string) file_get_contents(BASE_PATH . '/app/Core/Response.php');
        foreach (["X-Content-Type-Options: nosniff", "X-Frame-Options: DENY", "Referrer-Policy: same-origin", "script-src 'self'", "frame-ancestors 'none'", "object-src 'none'"] as $needle) {
            $t->assertContains($needle, $source);
        }
        $t->assertContains('Response::securityHeaders();', (string) file_get_contents(BASE_PATH . '/app/Core/Router.php'), 'The router sends them for every request');
        $session = (string) file_get_contents(BASE_PATH . '/app/Core/Session.php');
        $t->assertContains("'httponly' => true", $session);
        $t->assertContains("'samesite' => 'Lax'", $session);
        $t->assertEquals([], glob(BASE_PATH . '/public/*.php') === [BASE_PATH . '/public/index.php'] ? [] : array_map('basename', array_diff(glob(BASE_PATH . '/public/*.php') ?: [], [BASE_PATH . '/public/index.php'])), 'index.php is the only PHP file in public/');
    },
];
