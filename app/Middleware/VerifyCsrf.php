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
            // 403, not 419: 419 is a framework convention, not a registered HTTP
            // status. Apache does not recognize it and rewrites the response to a
            // 500, which hides the real cause from the user and the logs.
            throw new HttpException(403, 'Your session expired or the form was invalid. Please go back, reload the page, and try again.');
        }
    }
}
