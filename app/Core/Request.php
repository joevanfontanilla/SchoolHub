<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Read-only view of the current HTTP request.
 */
final class Request
{
    private array $routeParams = [];

    public function method(): string
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Allow PUT/PATCH/DELETE to be tunnelled through a POST form field.
        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper((string) $_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $override;
            }
        }

        return $method;
    }

    /** Request path with the application's base directory stripped off. */
    public function path(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $uri = explode('?', $uri, 2)[0];

        $base = base_path_uri();
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }

        $uri = '/' . trim(rawurldecode($uri), '/');
        return $uri === '/' ? '/' : rtrim($uri, '/');
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $value = $_POST[$key] ?? $_GET[$key] ?? $default;
        return is_string($value) ? trim($value) : $value;
    }

    /** Raw value with no trimming — for passwords, which may legitimately have spaces. */
    public function raw(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    public function all(): array
    {
        return array_merge($_GET, $_POST);
    }

    /** The uploaded file for a form field, or null when the field was not sent. */
    public function file(string $key): ?UploadedFile
    {
        if (!isset($_FILES[$key]) || !is_array($_FILES[$key])) {
            return null;
        }
        return UploadedFile::fromRequest($_FILES[$key]);
    }

    public function has(string $key): bool
    {
        return isset($_POST[$key]) || isset($_GET[$key]);
    }

    /**
     * The visitor's address. Behind a hosting provider's proxy or Cloudflare, REMOTE_ADDR is the
     * proxy, shared by every visitor, and one person's failed sign-ins would block the whole
     * school. X-Forwarded-For is believed only when the request comes from a proxy listed in
     * app.trusted_proxies (docs/23); anyone can send that header directly.
     */
    public function ip(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $proxies = (array) Config::get('app.trusted_proxies', []);
        if ($proxies === [] || !self::ipInRanges($remote, $proxies)) {
            return $remote;
        }
        // The right-most address not added by a trusted proxy is the visitor.
        $chain = array_reverse(array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))));
        foreach ($chain as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                break;
            }
            if (!self::ipInRanges($address, $proxies)) {
                return $address;
            }
        }
        return $remote;
    }

    /** Whether the visitor's connection is HTTPS, directly or through a trusted proxy that ended it. */
    public function isSecure(): bool
    {
        $https = (string) ($_SERVER['HTTPS'] ?? '');
        if ($https !== '' && strtolower($https) !== 'off') {
            return true;
        }
        $proxies = (array) Config::get('app.trusted_proxies', []);
        return $proxies !== []
            && self::ipInRanges((string) ($_SERVER['REMOTE_ADDR'] ?? ''), $proxies)
            && strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0])) === 'https';
    }

    /** @param array<int,string> $ranges addresses or CIDR ranges ("10.0.0.0/8", "2400:cb00::/32") */
    public static function ipInRanges(string $ip, array $ranges): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        foreach ($ranges as $range) {
            [$network, $bits] = str_contains((string) $range, '/') ? explode('/', (string) $range, 2) : [(string) $range, null];
            $net = @inet_pton(trim($network));
            if ($net === false || strlen($net) !== strlen($packed)) {
                continue;
            }
            $bits = $bits === null ? strlen($packed) * 8 : max(0, min(strlen($packed) * 8, (int) $bits));
            $bytes = intdiv($bits, 8);
            if (substr($packed, 0, $bytes) !== substr($net, 0, $bytes)) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0 || ((ord($packed[$bytes]) ^ ord($net[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0) {
                return true;
            }
        }
        return false;
    }

    public function userAgent(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    /** True for requests that change state and therefore need a CSRF token. */
    public function isStateChanging(): bool
    {
        return in_array($this->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    public function expectsJson(): bool
    {
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        return str_contains($accept, 'application/json')
            || str_starts_with($this->path(), '/api/');
    }

    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }
}
