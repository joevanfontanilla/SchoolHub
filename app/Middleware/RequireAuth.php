<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * Rejects unauthenticated requests.
 */
final class RequireAuth
{
    public function handle(Request $request, ?string $argument = null): void
    {
        if (Auth::check()) {
            // A user who must change their password may go nowhere else.
            if (Auth::user()?->must_change_password && $request->path() !== '/password/change') {
                Response::redirect('/password/change');
                exit;
            }
            return;
        }

        if ($request->expectsJson()) {
            throw HttpException::unauthorized();
        }

        Session::flash('warning', 'Please sign in to continue.');
        Response::redirect('/login');
        exit;
    }
}
