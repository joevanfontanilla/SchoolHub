<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Session;
use App\Core\ValidationException;
use App\Services\RegistrationService;

/**
 * Student self-registration (docs/09 step 1).
 */
final class RegisterController extends Controller
{
    public function show(Request $request): void
    {
        $this->authView('auth/register', [
            'title'    => 'Create a student account',
            'honeypot' => RegistrationService::HONEYPOT,
        ]);
    }

    public function store(Request $request): void
    {
        try {
            $user = RegistrationService::register($request->all(), $request->ip(), $request->userAgent());
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), '/register');
            return;
        }

        Session::flash('success', "Welcome, {$user->first_name}. Start by completing your profile.");
        $this->redirect('/student/profile');
    }
}
