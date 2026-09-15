<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Session;
use App\Core\ValidationException;
use App\Services\PasswordResetService;

/**
 * "Forgot password?" by email, and setting a password through a one-time link (docs/07).
 * An invalid, used, or expired link shows the same message, whatever the reason.
 */
final class PasswordResetController extends Controller
{
    public function showForgot(Request $request): void
    {
        $this->authView('auth/forgot-password', [
            'title'   => 'Forgot your password?',
            'enabled' => \App\Core\Mailer::enabled(),
        ]);
    }

    /**
     * Always the same answer, whether or not the address has an account. The email is sent after
     * the answer has gone to the browser where the server allows it, so a slow mail server does
     * not reveal that a message was sent.
     */
    public function sendForgot(Request $request): void
    {
        $email = (string) $request->input('email', '');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            Session::flashErrors(['email' => ['Enter the email address of your account, like name@example.com.']]);
            Session::flashInput(['email' => $email]);
            $this->redirect('/password/forgot');
            return;
        }

        $send = PasswordResetService::requestByEmail($email, $request->ip());
        $minutes = \App\Models\SystemSetting::security('reset_link_minutes', 60);
        Session::flash('success', "If an account uses {$email}, a link to reset its password is on its way. It works once, for {$minutes} minutes. Check the spam or junk folder if it does not arrive in a few minutes.");
        $this->redirect('/password/forgot');

        if ($send !== null) {
            session_write_close();
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            $send();
        }
    }

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
