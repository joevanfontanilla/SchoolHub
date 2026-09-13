<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Request;

/**
 * Verifies the CSRF token on every state-changing request.
 *
 * Applied by the router to all POST/PUT/PATCH/DELETE requests. Routes cannot
 * opt out of it.
 */
final class VerifyCsrf
{
    public function handle(Request $request, ?string $argument = null): void
    {
        if (!$request->isStateChanging()) {
            return;
        }

        $token = $request->raw('_token');
        if (!is_string($token) || $token === '') {
            $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        }

        if (!Csrf::verify(is_string($token) ? $token : null)) {
            throw new HttpException(419, 'Your session expired or the form was invalid. Please try again.');
        }
    }
}
