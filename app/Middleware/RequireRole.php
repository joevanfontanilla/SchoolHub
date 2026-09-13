<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;

/**
 * Role gate: 'role:Registrar' or 'role:Registrar,Super Admin'.
 *
 * This is the backend enforcement that docs/00 requires. A hidden menu item is
 * not authorization — a teacher typing a cashier URL lands here and gets 403.
 */
final class RequireRole
{
    public function handle(Request $request, ?string $argument = null): void
    {
        if ($argument === null || $argument === '') {
            throw new \RuntimeException('role middleware requires at least one role name.');
        }

        $allowed = array_map('trim', explode(',', $argument));

        if (!Auth::check()) {
            throw HttpException::unauthorized();
        }

        if (!Auth::hasRole(...$allowed)) {
            throw HttpException::forbidden(
                'This page belongs to a different role. You are signed in as ' . (Auth::roleName() ?? 'an unknown role') . '.'
            );
        }
    }
}
