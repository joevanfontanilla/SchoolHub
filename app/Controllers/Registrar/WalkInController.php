<?php

declare(strict_types=1);

namespace App\Controllers\Registrar;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Core\ValidationException;
use App\Models\Catalog;
use App\Models\EnrollmentApplication as Application;
use App\Models\Program;
use App\Models\Student;
use App\Models\Term;
use App\Services\StudentProfileService;
use App\Services\WalkInService;

/**
 * Walk-in enrollment at the registrar's counter (docs/09). Thin: every rule lives in
 * WalkInService, which re-checks the registrar's role on each call.
 */
final class WalkInController extends Controller
{
    /** Step 1: the intake form, and the walk-ins not yet submitted. */
    public function create(Request $request): void
    {
        $this->appView('registrar/walk-in/create', [
            'title'        => 'Walk-in registration',
            'openTerms'    => Term::openForEnrollment(),
            'programs'     => Program::active(),
            'yearLevels'   => Catalog::yearLevels(),
            'studentTypes' => Student::TYPES,
            'sexes'        => StudentProfileService::SEXES,
            'civilStatus'  => StudentProfileService::CIVIL_STATUSES,
            'unfinished'   => WalkInService::unfinished(),
        ]);
    }

    public function store(Request $request): void
    {
        try {
            $created = WalkInService::register(Auth::user(), $request->all());
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), '/registrar/walk-in');
            return;
        }

        // The link is shown once, in this response, and never stored in plain form.
        $this->linkView($created['application_id'], $created['token'], $created['expires_at']);
    }

    /** Step 2: documents received in person, then submit. */
    public function documents(Request $request): void
    {
        $application = WalkInService::draft((int) $request->param('id'));
        $applicant = WalkInService::applicant($application);

        $this->appView('registrar/walk-in/documents', [
            'title'        => 'Walk-in documents',
            'application'  => $application + Application::find((int) $application['id']),
            'applicant'    => $applicant,
            'requirements' => Application::requirements((int) $application['id']),
        ]);
    }

    public function saveDocuments(Request $request): void
    {
        $id = (int) $request->param('id');
        $received = (array) $request->input('received', []);

        try {
            if ($request->input('action') === 'submit') {
                WalkInService::submit(Auth::user(), $id, $received);
            } else {
                WalkInService::recordDocuments(Auth::user(), $id, $received);
            }
        } catch (ValidationException $e) {
            $this->failWith($e, [], "/registrar/walk-in/{$id}");
            return;
        }

        if ($request->input('action') === 'submit') {
            $this->success('Walk-in submitted. It is in the application queue, ready for review.', "/registrar/applications/{$id}");
            return;
        }
        $this->success('Documents saved.', "/registrar/walk-in/{$id}");
    }

    public function reissueLink(Request $request): void
    {
        $id = (int) $request->param('id');
        try {
            $link = WalkInService::reissueLink(Auth::user(), $id);
        } catch (ValidationException $e) {
            $this->failWith($e, [], $this->backTo($id));
            return;
        }
        $this->linkView($id, $link['token'], $link['expires_at']);
    }

    public function emailLink(Request $request): void
    {
        $id = (int) $request->param('id');
        $token = (string) $request->input('token', '');

        try {
            $applicant = WalkInService::emailLink(Auth::user(), $id, $token);
        } catch (ValidationException $e) {
            // Show the link again with the problem, so it can still be copied.
            $found = \App\Services\PasswordResetService::find($token);
            if ($found !== null) {
                \App\Core\Session::flash('error', $e->getMessage());
                $this->linkView($id, $token, $found['expires_at']);
                return;
            }
            $this->failWith($e, [], $this->backTo($id));
            return;
        }
        $this->success("The link was emailed to {$applicant->email}.", $this->backTo($id));
    }

    public function cancel(Request $request): void
    {
        try {
            WalkInService::cancel(Auth::user(), (int) $request->param('id'));
        } catch (ValidationException $e) {
            $this->failWith($e, [], '/registrar/walk-in/' . (int) $request->param('id'));
            return;
        }
        $this->success('Walk-in cancelled.', '/registrar/walk-in');
    }

    /** Where to go after a link action: the documents step while it is a draft, else the application. */
    private function backTo(int $applicationId): string
    {
        return WalkInService::walkIn($applicationId)['application_status'] === Application::DRAFT
            ? "/registrar/walk-in/{$applicationId}"
            : "/registrar/applications/{$applicationId}";
    }

    private function linkView(int $applicationId, string $token, string $expiresAt): void
    {
        // Never cached: the page holds a working password link.
        header('Cache-Control: no-store');
        $application = WalkInService::walkIn($applicationId);
        $this->appView('registrar/walk-in/link', [
            'title'         => 'Applicant set-up link',
            'applicationId' => $applicationId,
            'applicant'     => WalkInService::applicant($application),
            'newAccount'    => $application['application_status'] === Application::DRAFT,
            'continueUrl'   => $this->backTo($applicationId),
            'link'          => absolute_url('/password/reset/' . $token),
            'expiresAt'     => $expiresAt,
        ]);
    }
}
