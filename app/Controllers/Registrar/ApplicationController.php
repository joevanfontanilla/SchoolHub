<?php

declare(strict_types=1);

namespace App\Controllers\Registrar;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\ValidationException;
use App\Models\Catalog;
use App\Models\EnrollmentApplication as Application;
use App\Models\Program;
use App\Models\RegistrarQueue;
use App\Models\Student;
use App\Services\EnrollmentApplicationService;
use App\Services\EnrollmentStatusService;
use App\Services\SubjectSelectionService;
use App\Services\PaymentPolicy;
use App\Services\RequirementReviewService;

/**
 * The registrar's application queue, review page, and decisions (docs/11).
 *
 * Every decision goes through EnrollmentStatusService or
 * RequirementReviewService, which re-check the rules on the server. The buttons
 * on the page only reflect what those services would allow.
 */
final class ApplicationController extends Controller
{
    public function index(Request $request): void
    {
        $filters = [
            'status'     => (string) $request->input('status', 'action'),
            'term_id'    => $request->input('term_id'),
            'program_id' => $request->input('program_id'),
            'q'          => (string) $request->input('q', ''),
        ];

        $this->appView('registrar/applications/index', [
            'title'    => 'Applications',
            'filters'  => $filters,
            'result'   => RegistrarQueue::applications($filters, (int) $request->input('page', 1)),
            'counts'   => RegistrarQueue::counts(),
            'terms'    => Catalog::allTerms(),
            'programs' => Program::active(),
        ]);
    }

    public function show(Request $request): void
    {
        $application = $this->application($request);
        $student = RegistrarQueue::student((int) $application['student_id']);

        $this->appView('registrar/applications/show', [
            'title'         => 'Review ' . $application['application_number'],
            'application'   => $application,
            'student'       => $student,
            'profile'       => Student::profile((int) $application['student_id']),
            'guardians'     => Student::guardians((int) $application['student_id']),
            'requirements'  => Application::requirements((int) $application['id']),
            'history'       => Application::history((int) $application['id']),
            'otherApps'     => array_values(array_filter(
                RegistrarQueue::applicationsOfStudent((int) $application['student_id']),
                static fn (array $a) => (int) $a['id'] !== (int) $application['id']
            )),
            'targets'       => EnrollmentStatusService::availableTargets($application, Auth::user()),
            'paymentProblem' => PaymentPolicy::problem($application),
            'subjects'      => SubjectSelectionService::chosen((int) $application['id']),
            'sections'      => Catalog::sectionsForApplication($application),
            'assessment'    => \App\Models\Finance::assessmentForApplication((int) $application['id']),
            'enrollment'    => Database::selectOne(
                'SELECT e.*, sec.code AS section_code FROM enrollments e JOIN sections sec ON sec.id = e.section_id WHERE e.application_id = ?',
                [$application['id']]
            ),
        ]);
    }

    public function startReview(Request $request): void
    {
        $this->decide($request, Application::UNDER_REVIEW, 'Review started. The documents are now marked under review.');
    }

    public function returnIncomplete(Request $request): void
    {
        $this->decide($request, Application::REQUIREMENTS_INCOMPLETE, 'The application was returned to the student to replace documents.');
    }

    public function requestCorrection(Request $request): void
    {
        $this->decide($request, Application::CORRECTION_REQUIRED, 'The application was returned to the student for correction.');
    }

    /** Approve and place the student in a section (docs/09, docs/26 rule 8). */
    public function approve(Request $request): void
    {
        $application = $this->application($request);
        $id = (int) $application['id'];

        try {
            EnrollmentApplicationService::approve(Auth::user(), $id, (int) $request->input('section_id', 0), (string) $request->input('remarks', '') ?: null);
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), "/registrar/applications/{$id}#decision");
            return;
        }

        $enrolled = Database::scalar('SELECT enrollment_state FROM enrollment_applications WHERE id = ?', [$id]) === 'ENROLLED';
        $this->success($enrolled
            ? 'Application approved and the student enrolled (no payment is required by school policy).'
            : 'Application approved. The fee assessment was issued to the student, who is enrolled once the payment policy is met.', "/registrar/applications/{$id}");
    }

    public function reject(Request $request): void
    {
        $this->decide($request, Application::REJECTED, 'Application rejected. The student has been notified.');
    }

    public function cancel(Request $request): void
    {
        $this->decide($request, Application::CANCELLED, 'Application cancelled.');
    }

    /** Verify, reject, or ask for a correction on one document. */
    public function reviewRequirement(Request $request): void
    {
        $requirementId = (int) $request->param('requirementId');
        $applicationId = (int) Database::scalar('SELECT application_id FROM enrollment_requirements WHERE id = ?', [$requirementId]);
        if ($applicationId === 0) {
            throw HttpException::notFound('That document was not found.');
        }
        $back = "/registrar/applications/{$applicationId}#requirement-{$requirementId}";

        $decision = (string) $request->input('decision', '');

        try {
            RequirementReviewService::review(Auth::user(), $requirementId, $decision, (string) $request->input('remarks', ''));
        } catch (ValidationException $e) {
            $this->failWith($e, ['remarks_for' => $requirementId, 'remarks' => $request->input('remarks', '')], $back);
            return;
        }

        $label = match ($decision) {
            Application::REQ_VERIFIED => 'verified',
            Application::REQ_REJECTED => 'rejected',
            default                   => 'marked as needing correction',
        };
        $this->success("Document {$label}.", $back);
    }

    private function decide(Request $request, string $target, string $message): void
    {
        $application = $this->application($request);
        $id = (int) $application['id'];

        try {
            EnrollmentStatusService::transition($id, $target, Auth::user(), (string) $request->input('remarks', ''));
        } catch (ValidationException $e) {
            $this->failWith($e, ['decision' => $target, 'remarks' => $request->input('remarks', '')], "/registrar/applications/{$id}");
            return;
        }

        $this->success($message, "/registrar/applications/{$id}");
    }

    /**
     * The application for the route id. Drafts are a student's unsubmitted work
     * and do not exist as far as the registrar is concerned.
     *
     * @return array<string,mixed>
     */
    private function application(Request $request): array
    {
        $application = Application::find((int) $request->param('id'));
        if ($application === null || $application['application_status'] === Application::DRAFT) {
            throw HttpException::notFound('That application was not found.');
        }
        return $application;
    }
}
