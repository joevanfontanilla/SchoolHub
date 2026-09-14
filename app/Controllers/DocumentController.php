<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Services\AuditService;
use App\Services\DocumentAccessPolicy;
use App\Services\FileStreamer;

/**
 * Streams an uploaded requirement document to someone allowed to see it.
 *
 * Role-neutral on purpose: students use it for their own files now, and the
 * registrar screens in Phase 3 use the same route. Access is decided by
 * DocumentAccessPolicy, never by which page linked here.
 */
final class DocumentController extends Controller
{
    public function show(Request $request): void
    {
        $user = Auth::user();
        $fileId = (int) $request->param('fileId');

        [$decision, $file] = DocumentAccessPolicy::decide($user, $fileId);

        if ($decision === DocumentAccessPolicy::FORBIDDEN) {
            AuditService::log('document.view_denied', 'requirements', 'EnrollmentRequirementFile', $fileId, null, null, 'FAILURE');
            throw HttpException::forbidden('You are not allowed to open student documents.');
        }
        if ($decision === DocumentAccessPolicy::NOT_FOUND) {
            throw HttpException::notFound('That document was not found.');
        }

        // docs/17 section 10: every view of a student document is audited.
        AuditService::log('document.viewed', 'requirements', 'EnrollmentRequirementFile', $fileId, null, [
            'application_id' => (int) $file['application_id'],
        ]);

        FileStreamer::send($file['storage_path'], $file['original_filename'], $file['mime_type']);
    }
}
