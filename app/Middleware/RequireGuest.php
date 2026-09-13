<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

/**
 * Keeps signed-in users away from the login and registration pages.
 */
final class RequireGuest
{
    public function handle(Request $request, ?string $argument = null): void
    {
        if (Auth::check()) {
            Response::redirect(Auth::homePath());
            exit;
        }
    }
}
