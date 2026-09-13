<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session handling with secure cookie flags, idle timeout, and id regeneration
 * on privilege change (docs/07).
 */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name((string) Config::get('session.name', 'schoolhub_session'));

        session_set_cookie_params([
            'lifetime' => (int) Config::get('session.lifetime', 7200),
            'path'     => base_path_uri() ?: '/',
            'domain'   => '',
            'secure'   => (bool) Config::get('session.secure', false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();

        self::enforceIdleTimeout();
    }

    private static function enforceIdleTimeout(): void
    {
        $timeout = (int) Config::get('session.idle_timeout', 1800);
        $last = self::get('_last_activity');

        if ($last !== null && (time() - (int) $last) > $timeout) {
            self::destroy();
            session_start();
            self::flash('warning', 'Your session expired after a period of inactivity. Please sign in again.');
        }

        self::put('_last_activity', time());
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Regenerate the session id — called on every privilege change. */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        $_SESSION = [];

        // No active session under CLI (tests, scripts) — clearing the array is
        // all there is to do.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    // -- Flash messages ----------------------------------------------------

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][$type][] = $message;
    }

    /** @return array<string,array<int,string>> */
    public static function pullFlash(): array
    {
        $flash = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $flash;
    }

    // -- Old input (redisplay a failed form without losing what was typed) --

    public static function flashInput(array $input): void
    {
        unset($input['password'], $input['password_confirmation'], $input['current_password'], $input['_token']);
        $_SESSION['_old'] = $input;
    }

    public static function pullOldInput(): array
    {
        $old = $_SESSION['_old'] ?? [];
        unset($_SESSION['_old']);
        return $old;
    }

    public static function flashErrors(array $errors): void
    {
        $_SESSION['_errors'] = $errors;
    }

    public static function pullErrors(): array
    {
        $errors = $_SESSION['_errors'] ?? [];
        unset($_SESSION['_errors']);
        return $errors;
    }
}
