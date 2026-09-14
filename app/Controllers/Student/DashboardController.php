<?php

declare(strict_types=1);

namespace App\Controllers\Student;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Models\Announcement;
use App\Models\EnrollmentApplication as Application;
use App\Models\Term;
use App\Services\RegistrationService;
use App\Services\StudentProfileService;

/**
 * Student dashboard (docs/08): the application in progress and what to do next.
 */
final class DashboardController extends Controller
{
    public function index(Request $request): void
    {
        $user = Auth::user();
        $student = RegistrationService::ensureStudentRecord($user);
        $studentId = (int) $student['id'];

        $current = Application::currentForStudent($studentId);
        $missingProfile = StudentProfileService::missingFields($studentId);

        $requiredTotal = 0;
        $requiredDone = 0;
        if ($current !== null) {
            foreach (Application::requirements((int) $current['id']) as $requirement) {
                if ((int) $requirement['is_required'] === 1) {
                    $requiredTotal++;
                    if (in_array($requirement['status'], ['SUBMITTED', 'UNDER_REVIEW', 'VERIFIED'], true)) {
                        $requiredDone++;
                    }
                }
            }
        }

        $status = $current['application_status'] ?? null;
        $active = $current !== null && $status !== Application::CANCELLED;
        $beyondDraft = $active && $status !== Application::DRAFT;
        $openTerms = Term::openForEnrollment();

        $steps = [
            ['Complete your profile', $missingProfile === [], '/student/profile'],
            ['Start an application', $active, $openTerms !== [] ? '/student/enrollment/apply' : '/student/enrollment'],
            ['Upload required documents', $active && $requiredTotal > 0 && $requiredDone === $requiredTotal, '/student/requirements'],
            ['Submit to the registrar', $beyondDraft, $current !== null ? '/student/enrollment/' . $current['id'] : '/student/enrollment'],
            ['Registrar review', $active && $status === Application::APPROVED, '/student/status'],
            ['Pay your assessment', false, null],
        ];

        $this->appView('dashboards/student', [
            'title'         => 'Dashboard',
            'announcements' => Announcement::forUser($user, 5),
            'current'       => $current,
            'steps'         => $steps,
            'openTerms'     => $openTerms,
            'cards'         => [
                [
                    'label' => 'Application status',
                    'value' => $current !== null ? Application::label($status) : 'No application',
                    'hint'  => $current['application_number'] ?? ($openTerms !== [] ? 'Enrollment is open' : 'Enrollment is closed'),
                    'tone'  => match ($status) {
                        Application::APPROVED => 'success',
                        Application::REQUIREMENTS_INCOMPLETE, Application::CORRECTION_REQUIRED => 'warning',
                        Application::REJECTED => 'danger',
                        default => 'neutral',
                    },
                ],
                [
                    'label' => 'Term',
                    'value' => $current !== null ? $current['term_name'] : '—',
                    'hint'  => $current !== null ? 'A.Y. ' . $current['academic_year_code'] : 'Set when you apply',
                    'tone'  => 'neutral',
                ],
                [
                    'label' => 'Required documents',
                    'value' => $current !== null ? "{$requiredDone} of {$requiredTotal}" : '—',
                    'hint'  => $current !== null ? ($requiredDone === $requiredTotal ? 'All uploaded' : 'Still to upload') : 'Listed when you apply',
                    'tone'  => $current !== null && $requiredDone < $requiredTotal ? 'warning' : 'neutral',
                ],
                [
                    'label' => 'Profile',
                    'value' => $missingProfile === [] ? 'Complete' : 'Incomplete',
                    'hint'  => $missingProfile === [] ? 'Ready for review' : count($missingProfile) . ' item' . (count($missingProfile) === 1 ? '' : 's') . ' missing',
                    'tone'  => $missingProfile === [] ? 'success' : 'warning',
                ],
            ],
        ]);
    }
}
