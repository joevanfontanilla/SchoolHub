<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Core\View;
use App\Models\SystemSetting;

/**
 * Base controller.
 *
 * Controllers coordinate only: validate through a Validator, delegate work to a
 * Service, and render. No business logic and no SQL belong here (docs/05).
 */
abstract class Controller
{
    /** Render a view inside the public-site layout. */
    protected function publicView(string $template, array $data = [], int $status = 200): void
    {
        $data += [
            'school'      => SystemSetting::publicSettings(),
            'currentPath' => (new \App\Core\Request())->path(),
        ];
        Response::html(View::render($template, $data, 'layouts/public'), $status);
    }

    /** Render a view inside the authenticated application shell. */
    protected function appView(string $template, array $data = [], int $status = 200): void
    {
        $user = Auth::user();
        if ($user !== null) {
            // Deliver scheduled announcements that have come due (there is no cron).
            \App\Services\AnnouncementService::dispatchDue();
        }
        $data += [
            'user'        => $user,
            'school'      => SystemSetting::publicSettings(),
            'currentPath' => (new \App\Core\Request())->path(),
            // The line under the user's name in the top bar, and the bell's count.
            'portal'      => $user === null ? ['subtitle' => '', 'unread' => 0] : [
                'subtitle' => $user->isStudent() ? \App\Models\Student::portalLabel($user->id) : (string) $user->role_name,
                'unread'   => \App\Models\Notification::unreadCount($user->id),
                'recent'   => \App\Models\Notification::latest($user->id, 5),
                'urgent'   => \App\Models\Announcement::urgentFor($user),
            ],
        ];
        Response::html(View::render($template, $data, 'layouts/app'), $status);
    }

    /**
     * Render a printable document (receipts, statements, reports) on the letterhead
     * layout, which the browser prints or saves as PDF (docs/15).
     */
    protected function printView(string $template, array $data = []): void
    {
        $data += ['school' => SystemSetting::publicSettings(), 'user' => Auth::user()];
        Response::html(View::render($template, $data, 'layouts/print'));
    }

    /** Render a view inside the minimal auth layout (login, reset). */
    protected function authView(string $template, array $data = [], int $status = 200): void
    {
        $data += ['school' => SystemSetting::publicSettings()];
        Response::html(View::render($template, $data, 'layouts/auth'), $status);
    }

    protected function redirect(string $path): void
    {
        Response::redirect($path);
    }

    /** Redirect back with validation errors and the submitted values. */
    protected function backWithErrors(Validator $validator, array $input, string $path): void
    {
        Session::flashErrors($validator->errors());
        Session::flashInput($input);
        Response::redirect($path);
    }

    protected function backWithError(string $message, array $input, string $path): void
    {
        Session::flash('error', $message);
        Session::flashInput($input);
        Response::redirect($path);
    }

    /**
     * Redirect back after a service refused the action: field errors for the
     * form, the summary as a flash notice, and the submitted values restored.
     */
    protected function failWith(ValidationException $e, array $input, string $path): void
    {
        if ($e->errors() !== []) {
            Session::flashErrors($e->errors());
        }
        Session::flash('error', $e->getMessage());
        Session::flashInput($input);
        Response::redirect($path);
    }

    protected function success(string $message, string $path): void
    {
        Session::flash('success', $message);
        Response::redirect($path);
    }
}
