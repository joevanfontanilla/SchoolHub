<?php

declare(strict_types=1);

namespace App\Controllers\Registrar;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Session;
use App\Core\UploadedFile;
use App\Core\ValidationException;
use App\Models\Catalog;
use App\Models\EnrollmentApplication as Application;
use App\Models\Program;
use App\Models\Student;
use App\Models\Term;
use App\Services\PasswordResetService;
use App\Services\StudentProfileService;
use App\Services\WalkInService;

/**
 * Walk-in enrollment at the registrar's counter (docs/09). Thin: every rule lives in
 * WalkInService, which re-checks the registrar's role on each call.
 */
final class WalkInController extends Controller
{
    /** The session key a one-time set-up link waits under between the redirect and the page that shows it. */
    private const LINK_KEY = 'walkin_link';

    /** Step 1: the intake form, a search for a student who is already registered, and the walk-ins not yet submitted. */
    public function create(Request $request): void
    {
        $find = trim((string) $request->input('find', ''));

        $this->appView('registrar/walk-in/create', [
            'title'        => 'Walk-in registration',
            'openTerms'    => Term::openForEnrollment(),
            'programs'     => Program::active(),
            'yearLevels'   => Catalog::yearLevels(),
            'studentTypes' => Student::TYPES,
            'sexes'        => StudentProfileService::SEXES,
            'civilStatus'  => StudentProfileService::CIVIL_STATUSES,
            'unfinished'   => WalkInService::unfinished(),
            'find'         => $find,
            'found'        => WalkInService::findStudents($find),
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

        // The link is shown once, on its own page, never as the reply to this form post.
        $this->showLinkNext($created['application_id'], $created['token'], $created['expires_at'], true);
    }

    /** A student who is already registered: only the enrollment choices are needed. */
    public function existing(Request $request): void
    {
        $studentId = (int) $request->param('studentId');
        $student = $this->existingStudent($studentId);

        $this->appView('registrar/walk-in/existing', [
            'title'          => 'Walk-in: ' . $student['first_name'] . ' ' . $student['last_name'],
            'student'        => $student,
            'missingProfile' => StudentProfileService::missingFields($studentId),
            'openTerms'      => Term::openForEnrollment(),
            'programs'       => Program::active(),
            'yearLevels'     => Catalog::yearLevels(),
            'studentTypes'   => Student::TYPES,
        ]);
    }

    public function storeExisting(Request $request): void
    {
        $studentId = (int) $request->param('studentId');
        $this->existingStudent($studentId);

        try {
            $applicationId = WalkInService::registerExisting(Auth::user(), $studentId, $request->all());
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), "/registrar/walk-in/student/{$studentId}");
            return;
        }

        $this->success('The application was started on the existing account. Record the documents received.', "/registrar/walk-in/{$applicationId}");
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
            'hasPhoto'     => trim((string) (Student::profile((int) $application['student_id'])['photo_path'] ?? '')) !== '',
        ]);
    }

    public function saveDocuments(Request $request): void
    {
        $id = (int) $request->param('id');
        $received = (array) $request->input('received', []);

        try {
            // The ticks are saved first, so they survive a problem with the photo or a refused submission.
            WalkInService::recordDocuments(Auth::user(), $id, $received);
            $photoSaved = WalkInService::savePhoto(Auth::user(), $id, UploadedFile::fromRequest($_FILES['photo'] ?? []));
            if ($request->input('action') === 'submit') {
                WalkInService::submit(Auth::user(), $id, $received);
            }
        } catch (ValidationException $e) {
            $this->failWith($e, [], "/registrar/walk-in/{$id}");
            return;
        }

        if ($request->input('action') === 'submit') {
            $this->success('Walk-in submitted and under review. Choose a section and approve it below.', "/registrar/applications/{$id}");
            return;
        }
        $this->success($photoSaved ? 'Documents and photo saved.' : 'Documents saved.', "/registrar/walk-in/{$id}");
    }

    /** The one-time link, shown once after a redirect. Opening the page again explains that it was shown once. */
    public function link(Request $request): void
    {
        $id = (int) $request->param('id');
        $application = WalkInService::walkIn($id);

        $held = Session::get(self::LINK_KEY);
        Session::forget(self::LINK_KEY);
        if (!is_array($held) || (int) ($held['application_id'] ?? 0) !== $id) {
            Session::flash('info', 'The set-up link is shown only once and cannot be shown again. Issue a new one from here if it was lost.');
            $this->redirect($this->backTo($id));
            return;
        }

        // Never cached: the page holds a working password link.
        header('Cache-Control: no-store');
        $this->appView('registrar/walk-in/link', [
            'title'         => 'Applicant set-up link',
            'applicationId' => $id,
            'applicant'     => WalkInService::applicant($application),
            'newAccount'    => !empty($held['new']),
            'continueUrl'   => $this->backTo($id),
            'link'          => absolute_url('/password/reset/' . $held['token']),
            'expiresAt'     => (string) $held['expires_at'],
            'token'         => (string) $held['token'],
        ]);
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
        $this->showLinkNext($id, $link['token'], $link['expires_at'], false);
    }

    public function emailLink(Request $request): void
    {
        $id = (int) $request->param('id');
        $token = (string) $request->input('token', '');

        try {
            $applicant = WalkInService::emailLink(Auth::user(), $id, $token);
        } catch (ValidationException $e) {
            // Show the link again with the problem, so it can still be copied.
            $found = PasswordResetService::find($token);
            if ($found !== null) {
                Session::flash('error', $e->getMessage());
                $this->showLinkNext($id, $token, $found['expires_at'], WalkInService::walkIn($id)['application_status'] === Application::DRAFT);
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

    /** Hold the link for the next request only, then go to the page that shows it. */
    private function showLinkNext(int $applicationId, string $token, string $expiresAt, bool $newAccount): void
    {
        Session::put(self::LINK_KEY, ['application_id' => $applicationId, 'token' => $token, 'expires_at' => $expiresAt, 'new' => $newAccount]);
        $this->redirect("/registrar/walk-in/{$applicationId}/link");
    }

    /**
     * The student an application is being started for.
     *
     * @return array<string,mixed>
     */
    private function existingStudent(int $studentId): array
    {
        $found = WalkInService::studentSummary($studentId);
        if ($found === null) {
            throw HttpException::notFound('That student was not found.');
        }

        return $found;
    }

    /** Where to go after a link action: the documents step while it is a draft, else the application. */
    private function backTo(int $applicationId): string
    {
        return WalkInService::walkIn($applicationId)['application_status'] === Application::DRAFT
            ? "/registrar/walk-in/{$applicationId}"
            : "/registrar/applications/{$applicationId}";
    }
}
