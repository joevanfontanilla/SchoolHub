<?php

declare(strict_types=1);

namespace App\Controllers\Student;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Core\ValidationException;
use App\Models\CredentialRequest;
use App\Services\CredentialRequestService;
use App\Services\RegistrationService;

/**
 * A student requests official documents and tracks their status (docs/08).
 */
final class CredentialController extends Controller
{
    public function index(Request $request): void
    {
        $student = RegistrationService::ensureStudentRecord(Auth::user());
        $this->appView('student/credentials/index', [
            'title'    => 'Document Requests',
            'requests' => CredentialRequest::forStudent((int) $student['id']),
            'types'    => CredentialRequest::TYPES,
        ]);
    }

    public function store(Request $request): void
    {
        try {
            CredentialRequestService::request(Auth::user(), $request->all());
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), '/student/credentials');
            return;
        }
        $this->success('Your document request has been sent to the registrar.', '/student/credentials');
    }

    public function cancel(Request $request): void
    {
        try {
            CredentialRequestService::cancel(Auth::user(), (int) $request->param('id'));
        } catch (ValidationException $e) {
            $this->failWith($e, [], '/student/credentials');
            return;
        }
        $this->success('Your request was cancelled.', '/student/credentials');
    }
}
