<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Session;
use App\Core\ValidationException;
use App\Services\PasswordResetService;

/**
 * Setting a password through a one-time link issued by the Super Admin (docs/07).
 * An invalid, used, or expired link shows the same message, whatever the reason.
 */
final class PasswordResetController extends Controller
{
    public function show(Request $request): void
    {
        header('Cache-Control: no-store');
        $found = PasswordResetService::find((string) $request->param('token'));
        $this->authView('auth/reset-password', [
            'title'   => $found !== null && $found['purpose'] === PasswordResetService::SETUP ? 'Set your password' : 'Reset your password',
            'found'   => $found,
            'token'   => (string) $request->param('token'),
        ], $found === null ? 404 : 200);
    }

    public function update(Request $request): void
    {
        $token = (string) $request->param('token');
        try {
            $user = PasswordResetService::complete($token, (string) $request->raw('password', ''), (string) $request->raw('password_confirmation', ''));
        } catch (ValidationException $e) {
            $this->failWith($e, [], '/password/reset/' . $token);
            return;
        }
        Session::flash('success', 'Your password is set, ' . $user->first_name . '. Sign in with your username or email and the new password.');
        $this->redirect('/login');
    }
}
