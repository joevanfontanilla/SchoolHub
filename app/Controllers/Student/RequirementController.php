<?php

declare(strict_types=1);

namespace App\Controllers\Student;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Core\ValidationException;
use App\Models\EnrollmentApplication;
use App\Services\DocumentUploadService;

/**
 * Requirement document uploads (docs/10).
 */
final class RequirementController extends Controller
{
    public function upload(Request $request): void
    {
        $applicationId = (int) $request->param('id');
        $requirementId = (int) $request->param('requirementId');

        // Only these two pages post here. A whitelist, never a free redirect.
        $back = $request->input('return_to') === 'requirements'
            ? '/student/requirements'
            : "/student/enrollment/{$applicationId}";

        try {
            DocumentUploadService::upload(Auth::user(), $applicationId, $requirementId, $request->file('document'));
        } catch (ValidationException $e) {
            $this->failWith($e, [], $back . "#requirement-{$requirementId}");
            return;
        }

        $name = EnrollmentApplication::requirementName($requirementId);
        $this->success("{$name} uploaded.", $back . "#requirement-{$requirementId}");
    }
}
