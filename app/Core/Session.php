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

    /**
     * End the current session and continue on a brand-new, empty one.
     *
     * The old session's data is discarded and its server-side file deleted, and
     * the browser is sent a replacement cookie for the new id. Anything written
     * afterwards in the same request — a flash notice, a fresh CSRF token for
     * the sign-in form — therefore lands in a session the browser actually holds.
     *
     * Do not "fix" this back to session_destroy() + a cookie deletion: that keeps
     * using the OLD id for the rest of the request while telling the browser to
     * drop its cookie. The page rendered after an idle timeout then carries a
     * CSRF token no browser can ever submit, and the next sign-in fails with 403.
     */
    public static function destroy(): void
    {
        $_SESSION = [];

        // No active session under CLI (tests, scripts) — clearing the array is
        // all there is to do.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        session_regenerate_id(true);
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
