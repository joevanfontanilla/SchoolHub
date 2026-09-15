<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Response helpers. Controllers return the result of one of these.
 */
final class Response
{
    public static function html(string $body, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        self::debugHeaders();
        echo $body;
    }

    /**
     * Local development only: how many database statements and how much time the page
     * took, for the performance checks (docs/22). Never sent in production or with
     * debug off.
     */
    public static function debugHeaders(): void
    {
        if (!Config::get('app.debug', false) || Config::get('app.env', 'local') === 'production' || headers_sent()) {
            return;
        }
        header('X-Debug-Queries: ' . Database::queryCount());
        if (defined('SCHOOLHUB_START')) {
            header('X-Debug-Time-Ms: ' . (int) round((microtime(true) - SCHOOLHUB_START) * 1000));
        }
    }

    public static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Redirect to an application path (never a raw absolute URL). */
    /**
     * Where to send a plain-HTTP request when the production site address is https:// (docs/23).
     * Hosting panels do not always redirect port 80 themselves, and a sign-in form reached over
     * http:// would send the password unencrypted. Null when no redirect is needed.
     */
    public static function httpsRedirectTarget(Request $request): ?string
    {
        $base = (string) Config::get('app.base_url', '');
        if (Config::get('app.env') !== 'production' || !preg_match('#^https://#i', $base) || $request->isSecure()) {
            return null;
        }
        $query = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_QUERY);
        return absolute_url($request->path()) . ($query !== '' ? '?' . $query : '');
    }

    public static function redirect(string $path, int $status = 302): void
    {
        header('Location: ' . url($path), true, $status);
    }

    public static function back(string $fallback = '/'): void
    {
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');

        // Only follow a referer pointing at this same host — never an open redirect.
        if ($referer !== '' && $host !== '' && str_contains($referer, $host)) {
            header('Location: ' . $referer, true, 302);
            return;
        }

        self::redirect($fallback);
    }

    /** The Content-Security-Policy of every page, without its frame-ancestors part. */
    private const PAGE_POLICY = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'";

    /**
     * Let this site, and only this site, show the current response inside a frame: the
     * document preview window (docs/10, docs/15). Replaces the "never framed" headers sent
     * by securityHeaders(). Another site still cannot frame it.
     *
     * @param bool $file true for an uploaded file shown inline (a PDF or an image): it gets a
     *                   policy that allows nothing to run, instead of the page policy
     */
    public static function allowFramingBySelf(bool $file = false): void
    {
        header('X-Frame-Options: SAMEORIGIN');
        header('Content-Security-Policy: ' . ($file
            ? "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; frame-ancestors 'self'"
            : self::PAGE_POLICY . "; frame-ancestors 'self'"));
    }

    /** Security headers applied to every response (docs/07). */
    public static function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('X-XSS-Protection: 0');
        // Only this site's own scripts, styles, fonts, and images; no framing, no plugins,
        // forms post only back here. Inline style attributes are allowed for the few
        // computed widths (progress bars); inline scripts are not.
        header('Content-Security-Policy: ' . self::PAGE_POLICY . "; frame-ancestors 'none'");
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
        if (Config::get('session.secure', false)) {
            header('Strict-Transport-Security: max-age=31536000');
        }
        header_remove('X-Powered-By');
    }
}
