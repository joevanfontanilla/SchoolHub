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
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
        if (Config::get('session.secure', false)) {
            header('Strict-Transport-Security: max-age=31536000');
        }
        header_remove('X-Powered-By');
    }
}
