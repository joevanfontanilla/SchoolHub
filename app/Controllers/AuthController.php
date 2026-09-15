<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Session;
use App\Core\Validator;
use App\Services\AuthService;

/**
 * Sign-in, sign-out, and password change (docs/07).
 */
final class AuthController extends Controller
{
    public function showLogin(Request $request): void
    {
        $this->authView('auth/login', ['title' => 'Sign in', 'wide' => true]);
    }

    public function login(Request $request): void
    {
        $login = (string) $request->input('login', '');
        $password = (string) $request->raw('password', '');

        $validator = Validator::make(
            ['login' => $login, 'password' => $password],
            ['login' => 'required|max:150', 'password' => 'required'],
            ['login' => 'Email or username']
        );

        if ($validator->fails()) {
            $this->backWithErrors($validator, ['login' => $login], '/login');
            return;
        }

        $result = AuthService::attempt($login, $password, $request->ip(), $request->userAgent());

        if (!$result['ok']) {
            $this->backWithError($result['message'], ['login' => $login], '/login');
            return;
        }

        if ($result['user']?->must_change_password) {
            Session::flash('warning', 'Please set a new password before continuing.');
            $this->redirect('/password/change');
            return;
        }

        Session::flash('success', 'Welcome back, ' . $result['user']->first_name . '.');
        $this->redirect(Auth::homePath());
    }

    public function logout(Request $request): void
    {
        AuthService::logout();
        Session::start();
        Session::flash('success', 'You have been signed out.');
        $this->redirect('/login');
    }

    public function showChangePassword(Request $request): void
    {
        $this->appView('auth/change-password', ['title' => 'Change password']);
    }

    public function changePassword(Request $request): void
    {
        $user = Auth::user();
        if ($user === null) {
            $this->redirect('/login');
            return;
        }

        $validator = Validator::make($request->all(), [
            'current_password' => 'required',
            'password'         => 'required|min:8|confirmed',
        ], [
            'current_password' => 'Current password',
            'password'         => 'New password',
        ]);

        if ($validator->fails()) {
            $this->backWithErrors($validator, [], '/password/change');
            return;
        }

        $result = AuthService::changePassword(
            $user,
            (string) $request->raw('current_password', ''),
            (string) $request->raw('password', '')
        );

        if (!$result['ok']) {
            $this->backWithError($result['message'], [], '/password/change');
            return;
        }

        $this->success($result['message'], Auth::homePath());
    }
}
