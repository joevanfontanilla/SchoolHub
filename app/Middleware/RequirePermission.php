<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;

/**
 * Permission gate: 'can:enrollment.approve'.
 *
 * Super Admin passes implicitly; every other role must hold the permission
 * through role_permissions (docs/04).
 */
final class RequirePermission
{
    public function handle(Request $request, ?string $argument = null): void
    {
        if ($argument === null || $argument === '') {
            throw new \RuntimeException('can middleware requires a permission key.');
        }

        if (!Auth::check()) {
            throw HttpException::unauthorized();
        }

        if (!Auth::can($argument)) {
            throw HttpException::forbidden();
        }
    }
}
