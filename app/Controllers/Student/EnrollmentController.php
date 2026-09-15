<?php

declare(strict_types=1);

namespace App\Controllers\Student;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Session;
use App\Core\ValidationException;
use App\Models\Catalog;
use App\Models\EnrollmentApplication;
use App\Models\Program;
use App\Models\Student;
use App\Models\Term;
use App\Services\EnrollmentApplicationService;
use App\Services\EnrollmentStatusService;
use App\Services\RegistrationService;
use App\Services\StudentProfileService;
use App\Services\SubjectSelectionService;

/**
 * The student's enrollment applications (docs/09).
 *
 * Every lookup goes through EnrollmentApplicationService::ownedApplication, so
 * an id belonging to another student is a 404.
 */
final class EnrollmentController extends Controller
{
    public function index(Request $request): void
    {
        $student = RegistrationService::ensureStudentRecord(Auth::user());

        $this->appView('student/enrollment/index', [
            'title'        => 'Enrollment',
            'applications' => EnrollmentApplication::listForStudent((int) $student['id']),
            'openTerms'    => Term::openForEnrollment(),
        ]);
    }

    public function create(Request $request): void
    {
        $student = RegistrationService::ensureStudentRecord(Auth::user());
        $studentId = (int) $student['id'];

        $openTerms = array_values(array_filter(
            Term::openForEnrollment(),
            static fn (array $term) => !EnrollmentApplication::hasOpenApplicationForTerm($studentId, (int) $term['id'])
        ));

        $this->appView('student/enrollment/apply', [
            'title'          => 'Apply for enrollment',
            'openTerms'      => $openTerms,
            'anyTermOpen'    => Term::openForEnrollment() !== [],
            'programs'       => Program::active(),
            'yearLevels'     => Catalog::yearLevels(),
            'studentTypes'   => Student::TYPES,
            'missingProfile' => StudentProfileService::missingFields($studentId),
            'current'        => EnrollmentApplication::currentForStudent($studentId),
        ]);
    }

    public function store(Request $request): void
    {
        try {
            $id = EnrollmentApplicationService::createDraft(Auth::user(), $request->all());
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), '/student/enrollment/apply');
            return;
        }

        $number = EnrollmentApplication::find($id)['application_number'] ?? '';
        $this->success("Application {$number} created. Check your subjects and upload your documents next.", "/student/enrollment/{$id}#subjects");
    }

    public function show(Request $request): void
    {
        $user = Auth::user();
        $application = EnrollmentApplicationService::ownedApplication($user, (int) $request->param('id'));
        $status = $application['application_status'];

        $this->appView('student/enrollment/show', [
            'title'          => 'Application ' . $application['application_number'],
            'application'    => $application,
            'requirements'   => EnrollmentApplication::requirements((int) $application['id']),
            'history'        => EnrollmentApplication::history((int) $application['id']),
            'targets'        => EnrollmentStatusService::availableTargets($application, $user),
            'canEdit'        => in_array($status, EnrollmentApplication::EDITS_UNLOCKED, true),
            'canUpload'      => in_array($status, EnrollmentApplication::UPLOADS_UNLOCKED, true),
            'missingProfile' => StudentProfileService::missingFields((int) $application['student_id']),
            'programs'       => Program::active(),
            'yearLevels'     => Catalog::yearLevels(),
            'subjectOptions' => SubjectSelectionService::options($application),
            'chosenIds'      => SubjectSelectionService::chosenIds((int) $application['id']),
            'unitLimit'      => SubjectSelectionService::unitLimit($application),
            'curriculum'     => SubjectSelectionService::curriculumFor($application),
            'assessment'     => \App\Models\Finance::assessmentForApplication((int) $application['id']),
        ]);
    }

    /** Save the subjects the student chose (docs/09). */
    public function saveSubjects(Request $request): void
    {
        $id = (int) $request->param('id');

        try {
            SubjectSelectionService::save(Auth::user(), $id, (array) $request->input('subjects', []));
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), "/student/enrollment/{$id}#subjects");
            return;
        }

        $this->success('Your subjects were saved.', "/student/enrollment/{$id}#subjects");
    }

    public function update(Request $request): void
    {
        $id = (int) $request->param('id');

        try {
            EnrollmentApplicationService::updateDraft(Auth::user(), $id, $request->all());
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), "/student/enrollment/{$id}");
            return;
        }

        $this->success('Application details saved.', "/student/enrollment/{$id}");
    }

    public function submit(Request $request): void
    {
        $id = (int) $request->param('id');

        try {
            EnrollmentApplicationService::submit(Auth::user(), $id);
        } catch (ValidationException $e) {
            $this->failWith($e, [], "/student/enrollment/{$id}");
            return;
        }

        $this->success('Your application has been submitted to the registrar.', "/student/enrollment/{$id}");
    }

    public function cancel(Request $request): void
    {
        $id = (int) $request->param('id');

        try {
            EnrollmentApplicationService::cancel(Auth::user(), $id, (string) $request->input('remarks', ''));
        } catch (ValidationException $e) {
            $this->failWith($e, [], "/student/enrollment/{$id}");
            return;
        }

        $this->success('Your application has been cancelled.', '/student/enrollment');
    }

    /** docs/08 "Requirements": the documents of the application in progress. */
    public function requirements(Request $request): void
    {
        $application = $this->currentOrRedirect();
        if ($application === null) {
            return;
        }

        $this->appView('student/requirements', [
            'title'        => 'Requirements',
            'application'  => $application,
            'requirements' => EnrollmentApplication::requirements((int) $application['id']),
            'canUpload'    => in_array($application['application_status'], EnrollmentApplication::UPLOADS_UNLOCKED, true),
        ]);
    }

    /** docs/08 "Status": where the application in progress stands. */
    public function status(Request $request): void
    {
        $application = $this->currentOrRedirect();
        if ($application === null) {
            return;
        }

        $this->appView('student/status', [
            'title'       => 'Enrollment status',
            'application' => $application,
            'history'     => EnrollmentApplication::history((int) $application['id']),
        ]);
    }

    /** @return array<string,mixed>|null */
    private function currentOrRedirect(): ?array
    {
        $student = RegistrationService::ensureStudentRecord(Auth::user());
        $current = EnrollmentApplication::currentForStudent((int) $student['id']);

        if ($current === null) {
            Session::flash('info', 'You have not started an application yet.');
            $this->redirect('/student/enrollment/apply');
        }

        return $current;
    }
}
