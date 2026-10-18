<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;

/**
 * Global helpers. Loaded by bootstrap.php before anything else runs.
 */

if (!function_exists('base_path_uri')) {
    /**
     * The URI prefix the application is served from, with no trailing slash.
     *
     * Auto-detected from the front controller's location so the app works
     * unchanged at a domain root, at /SchoolHub/public, or anywhere else.
     * Never hardcode a leading-slash path anywhere in the app — use url().
     */
    function base_path_uri(): string
    {
        static $base = null;
        if ($base !== null) {
            return $base;
        }

        $configured = App\Core\Config::get('app.base_url');
        if (is_string($configured) && $configured !== '') {
            // A full address ("https://enroll.school.edu.ph/portal") contributes only its
            // path. The base is also the session cookie path and is stripped from each
            // request path, so it must never contain the scheme and host.
            if (preg_match('#^https?://#i', $configured)) {
                $configured = (string) parse_url($configured, PHP_URL_PATH);
            }
            return $base = rtrim($configured, '/');
        }

        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

        // dirname() returns a BACKSLASH on Windows for '/index.php', so its
        // result is normalized again before trimming. Without this the base
        // becomes '\' and every asset URL turns into '//assets/...', which the
        // browser reads as protocol-relative and sends to a host named
        // "assets" instead of to this application.
        $dir = str_replace('\\', '/', dirname($script));
        $dir = rtrim($dir, '/');

        return $base = ($dir === '' || $dir === '.') ? '' : $dir;
    }
}

if (!function_exists('url')) {
    /** Build an application URL. ALWAYS use this instead of a literal "/path". */
    function url(string $path = '/'): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        return base_path_uri() . '/' . ltrim($path, '/');
    }
}

if (!function_exists('absolute_url')) {
    /**
     * A full URL (scheme and host) for links given to people outside the page, such as a
     * password link. Uses app.base_url when configured; otherwise this request's host.
     */
    function absolute_url(string $path): string
    {
        $configured = (string) App\Core\Config::get('app.base_url', '');
        if (preg_match('#^https?://#i', $configured)) {
            return rtrim($configured, '/') . '/' . ltrim($path, '/');
        }
        $https = (new App\Core\Request())->isSecure();
        $host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        return ($https ? 'https' : 'http') . '://' . $host . url($path);
    }
}

if (!function_exists('asset')) {
    /** URL for a file under public/, cache-busted by its modification time. */
    function asset(string $path): string
    {
        $relative = ltrim($path, '/');
        $file = PUBLIC_PATH . '/' . $relative;
        $version = is_file($file) ? '?v=' . filemtime($file) : '';
        return url($relative) . $version;
    }
}

if (!function_exists('route')) {
    /** URL for a named route. */
    function route(string $name, array $params = []): string
    {
        return url(Router::route($name, $params));
    }
}

if (!function_exists('e')) {
    /** Escape for HTML output. Every dynamic value in a view goes through this. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return Csrf::token();
    }
}

if (!function_exists('csrf_field')) {
    /** Hidden CSRF input. Every form in the application must include this. */
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(Csrf::token()) . '">';
    }
}

if (!function_exists('method_field')) {
    function method_field(string $method): string
    {
        return '<input type="hidden" name="_method" value="' . e(strtoupper($method)) . '">';
    }
}

if (!function_exists('old')) {
    /** Previously submitted value, for redisplaying a form that failed validation. */
    function old(string $key, mixed $default = ''): mixed
    {
        static $old = null;
        if ($old === null) {
            $old = Session::pullOldInput();
        }
        return $old[$key] ?? $default;
    }
}

if (!function_exists('errors')) {
    /** @return array<string,array<int,string>> */
    function errors(): array
    {
        static $errors = null;
        if ($errors === null) {
            $errors = Session::pullErrors();
        }
        return $errors;
    }
}

if (!function_exists('error_for')) {
    function error_for(string $field): ?string
    {
        $all = errors();
        return isset($all[$field][0]) ? (string) $all[$field][0] : null;
    }
}

if (!function_exists('view')) {
    function view(string $template, array $data = [], ?string $layout = null): string
    {
        return View::render($template, $data, $layout);
    }
}

if (!function_exists('component')) {
    /** Render a reusable component from app/Views/components. */
    function component(string $name, array $data = []): string
    {
        return View::partial('components/' . $name, $data);
    }
}

if (!function_exists('icon')) {
    /**
     * An inline SVG icon from app/Helpers/icons.php, decorative (aria-hidden).
     * Pair it with visible or sr-only text that says what it means.
     */
    function icon(string $name, string $class = 'h-5 w-5'): string
    {
        static $icons = null;
        $icons ??= require __DIR__ . '/icons.php';

        $paths = $icons[$name] ?? $icons['info'];
        return '<svg class="shrink-0 ' . e($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"'
            . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
    }
}

if (!function_exists('portal_nav')) {
    /**
     * The sidebar entries of a role (app/Helpers/navigation.php).
     *
     * @return array<int,string|array<int,mixed>>
     */
    function portal_nav(?string $role): array
    {
        static $navigation = null;
        $navigation ??= require __DIR__ . '/navigation.php';
        return $navigation['nav'][$role] ?? [];
    }

    /**
     * Every page the quick search can open for a role, once each: the sidebar pages and
     * the extra ones, with the words that find them.
     *
     * @return array<int,array{path:string,label:string,icon:string,terms:string}>
     */
    function portal_destinations(?string $role): array
    {
        static $navigation = null;
        $navigation ??= require __DIR__ . '/navigation.php';
        $pages = [];
        foreach ([...portal_nav($role), ...($navigation['extra'][$role] ?? [])] as $entry) {
            if (!is_array($entry) || ($entry[3] ?? true) === false || isset($pages[$entry[0]])) {
                continue;
            }
            $pages[$entry[0]] = [
                'path'  => $entry[0],
                'label' => $entry[1],
                'icon'  => $entry[2],
                'terms' => strtolower($entry[1] . ' ' . ($navigation['keywords'][$entry[0]] ?? '')),
            ];
        }
        return array_values($pages);
    }
}

if (!function_exists('auth_user')) {
    function auth_user(): ?App\Models\User
    {
        return Auth::user();
    }
}

if (!function_exists('money')) {
    /** Format pesos. Money is always DECIMAL in the database, never float. */
    function money(mixed $amount): string
    {
        return '₱' . number_format((float) $amount, 2);
    }
}

if (!function_exists('method_text')) {
    /** A payment method with the school bank it went to, when it went through one: "Bank transfer · Landbank". @param array<string,mixed> $row */
    function method_text(array $row, string $key = 'payment_method'): string
    {
        $method = (string) ($row[$key] ?? '');
        $bank = trim((string) ($row['bank_name'] ?? ''));

        return $bank !== '' ? $method . ' · ' . $bank : $method;
    }
}

if (!function_exists('signed_money')) {
    /** Centavos as a signed peso amount for adjustments and reversals: "+₱500.00", "−₱1,000.00". */
    function signed_money(int $cents): string
    {
        return ($cents < 0 ? "−" : "+") . '₱' . number_format(abs($cents) / 100, 2);
    }
}

if (!function_exists('dt')) {
    /** Format a datetime for display; returns an em dash for null. */
    function dt(?string $value, string $format = 'M j, Y g:i A'): string
    {
        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
            return '—';
        }
        $ts = strtotime($value);
        return $ts === false ? '—' : date($format, $ts);
    }
}

if (!function_exists('d')) {
    /** Format a date for display. */
    function d(?string $value): string
    {
        return dt($value, 'M j, Y');
    }
}

if (!function_exists('str_limit')) {
    function str_limit(string $value, int $limit = 100): string
    {
        return mb_strlen($value) <= $limit ? $value : mb_substr($value, 0, $limit) . '…';
    }
}

if (!function_exists('partial')) {
    /**
     * Render a partial from app/Views/partials.
     *
     * Views are included in the global namespace, so they cannot reference
     * App\Core\View by its short name — use this instead.
     */
    function partial(string $name, array $data = []): string
    {
        return View::partial('partials/' . $name, $data);
    }
}

if (!function_exists('school_logo_url')) {
    /** The school logo: the one uploaded in System Settings, or the built-in seal. */
    function school_logo_url(): string
    {
        $stored = (string) App\Models\SystemSetting::get('school_logo', '');
        if ($stored !== '' && is_file(STORAGE_PATH . '/' . $stored)) {
            return url('/branding/logo') . '?v=' . substr(sha1($stored), 0, 10);
        }
        return asset('assets/images/lyceum-logo.jpg');
    }
}
