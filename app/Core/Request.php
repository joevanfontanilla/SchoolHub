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

    public function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
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
