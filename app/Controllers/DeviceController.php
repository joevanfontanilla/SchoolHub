<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Services\DeviceSessionService;

/**
 * The sign-in alert (docs/07 "One device at a time"): the waiting page for a browser that must be approved, the owner's banner, and their answers.
 */
final class DeviceController extends Controller
{
    /** A browser whose password was right but whose account is open elsewhere waits here. */
    public function waiting(Request $request): void
    {
        $token = DeviceSessionService::pendingToken();
        $state = DeviceSessionService::resolvePending($token);
        if ($state['state'] === 'approved') {
            $this->signIn($state['user'], (string) $token);
            return;
        }
        if ($state['state'] === 'gone') {
            Session::flash('warning', 'That sign-in request is no longer open. Please sign in again.');
            $this->redirect('/login');
            return;
        }
        $this->authView('auth/waiting', ['title' => 'Waiting for approval', 'state' => $state['state']]);
    }

    /** The waiting page asks every few seconds; an approved browser is signed in here and told where to go. */
    public function waitingStatus(Request $request): void
    {
        $token = DeviceSessionService::pendingToken();
        $state = DeviceSessionService::resolvePending($token);
        if ($state['state'] === 'approved') {
            $user = $state['user'];
            Auth::login($user);
            DeviceSessionService::rememberActive((string) $token);
            \App\Services\AuditService::log('login.success', 'auth', 'User', $user->id, null, ['via' => 'approval'], 'SUCCESS');
            Response::json(['state' => 'approved', 'redirect' => url($user->must_change_password ? '/password/change' : Auth::homePath())]);
            return;
        }
        Response::json(['state' => $state['state']]);
    }

    public function cancel(Request $request): void
    {
        DeviceSessionService::cancelPending(DeviceSessionService::pendingToken());
        $this->redirect('/login');
    }

    /** The banner's content, fetched in the background by the signed-in page. */
    public function alerts(Request $request): void
    {
        Response::html(partial('device-alerts', ['user' => Auth::user()]));
    }

    public function approve(Request $request): void
    {
        $this->answer($request, static fn () => DeviceSessionService::approve(Auth::user(), (int) $request->param('id')), 'The new device was allowed to sign in.');
    }

    public function deny(Request $request): void
    {
        $this->answer($request, static fn () => DeviceSessionService::deny(Auth::user(), (int) $request->param('id')), 'Denied. That device was not signed in. Consider changing your password if it was not you.');
    }

    public function signOut(Request $request): void
    {
        $this->answer($request, static fn () => DeviceSessionService::signOutDevice(Auth::user(), (int) $request->param('id')), 'That device was signed out.');
    }

    private function answer(Request $request, callable $action, string $message): void
    {
        try {
            $action();
        } catch (ValidationException $e) {
            $this->failWith($e, [], '/account#devices');
            return;
        }
        $this->success($message, '/account#devices');
    }

    private function signIn(\App\Models\User $user, string $token): void
    {
        Auth::login($user);
        DeviceSessionService::rememberActive($token);
        \App\Services\AuditService::log('login.success', 'auth', 'User', $user->id, null, ['via' => 'approval'], 'SUCCESS');
        Session::flash('success', 'Welcome back, ' . $user->first_name . '.');
        $this->redirect($user->must_change_password ? '/password/change' : Auth::homePath());
    }
}
