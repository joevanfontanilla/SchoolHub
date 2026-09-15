<?php

declare(strict_types=1);

namespace App\Controllers\Student;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Models\StudentDocuments;
use App\Services\RegistrationService;

/**
 * My Documents: everything the signed-in student has uploaded or been issued, in one
 * place, each opening in the preview window (docs/08, docs/10). Takes no record id from
 * the request, so another student's documents are unreachable.
 */
final class DocumentsController extends Controller
{
    public function index(Request $request): void
    {
        $student = RegistrationService::ensureStudentRecord(Auth::user());
        $this->appView('student/documents', [
            'title'     => 'My Documents',
            'documents' => StudentDocuments::forStudent((int) $student['id'], true),
        ]);
    }
}
