<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Per-session CSRF token, verified on every state-changing request (docs/07).
 */
final class Csrf
{
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        $token = Session::get(self::KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::put(self::KEY, $token);
        }
        return $token;
    }

    public static function verify(?string $candidate): bool
    {
        $token = Session::get(self::KEY);
        if (!is_string($token) || $token === '' || !is_string($candidate) || $candidate === '') {
            return false;
        }
        return hash_equals($token, $candidate);
    }

    /** Issue a fresh token — called on login and logout. */
    public static function rotate(): void
    {
        Session::forget(self::KEY);
        self::token();
    }
}
