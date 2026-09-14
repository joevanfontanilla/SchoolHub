<?php

declare(strict_types=1);

namespace App\Controllers\Registrar;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\ValidationException;
use App\Models\Catalog;
use App\Models\EnrollmentApplication as Application;
use App\Models\RegistrarQueue;
use App\Services\EnrollmentConfirmationService;
use App\Services\PaymentPolicy;

/**
 * Enrollment confirmation (docs/26 section 4): choose a section, issue the
 * student number, mark the student enrolled.
 */
final class ConfirmationController extends Controller
{
    public function show(Request $request): void
    {
        $application = $this->approvedApplication($request);

        $this->appView('registrar/applications/confirm', [
            'title'          => 'Confirm enrollment',
            'application'    => $application,
            'student'        => RegistrarQueue::student((int) $application['student_id']),
            'sections'       => Catalog::sectionsForApplication($application),
            'paymentProblem' => PaymentPolicy::problem($application),
            'policyLabel'    => PaymentPolicy::label(),
        ]);
    }

    public function store(Request $request): void
    {
        $application = $this->approvedApplication($request);
        $id = (int) $application['id'];

        try {
            $result = EnrollmentConfirmationService::confirm(Auth::user(), $id, (int) $request->input('section_id', 0));
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), "/registrar/applications/{$id}/confirm");
            return;
        }

        $message = $result['number_issued']
            ? "Enrollment confirmed. Student number {$result['student_number']} was issued."
            : "Enrollment confirmed for student number {$result['student_number']}.";
        $this->success($message, "/registrar/applications/{$id}");
    }

    /** @return array<string,mixed> */
    private function approvedApplication(Request $request): array
    {
        $application = Application::find((int) $request->param('id'));
        if ($application === null || $application['application_status'] === Application::DRAFT) {
            throw HttpException::notFound('That application was not found.');
        }
        if ($application['application_status'] !== Application::APPROVED || $application['enrollment_state'] !== 'NOT_ENROLLED') {
            // Nothing to confirm; show the application instead of an empty form.
            $this->redirect('/registrar/applications/' . $application['id']);
            exit;
        }
        return $application;
    }
}
