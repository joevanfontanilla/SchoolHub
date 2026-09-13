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
        echo $body;
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
        header_remove('X-Powered-By');
    }
}
