<?php

declare(strict_types=1);

namespace App\Controllers\Student;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\UploadedFile;
use App\Core\ValidationException;
use App\Models\EnrollmentApplication;
use App\Models\Student;
use App\Services\FileStreamer;
use App\Services\RegistrationService;
use App\Services\StudentProfileService;

/**
 * The student's own profile (docs/08).
 */
final class ProfileController extends Controller
{
    public function show(Request $request): void
    {
        $user = Auth::user();
        $student = RegistrationService::ensureStudentRecord($user);
        $studentId = (int) $student['id'];

        $this->appView('student/profile', [
            'title'       => 'My profile',
            'student'     => $student,
            'profile'     => Student::profile($studentId),
            'guardians'   => Student::guardians($studentId),
            'nameLocked'  => StudentProfileService::nameLocked($studentId),
            'missing'     => StudentProfileService::missingFields($studentId),
            'sexes'       => StudentProfileService::SEXES,
            'civilStatus' => StudentProfileService::CIVIL_STATUSES,
        ]);
    }

    public function update(Request $request): void
    {
        $user = Auth::user();

        try {
            StudentProfileService::update($user, $request->all(), UploadedFile::fromRequest($_FILES['photo'] ?? []));
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), '/student/profile');
            return;
        }

        $student = RegistrationService::ensureStudentRecord($user);
        $current = EnrollmentApplication::currentForStudent((int) $student['id']);

        // Send the student on to the next step when there is one.
        if (StudentProfileService::missingFields((int) $student['id']) === []) {
            if ($current === null) {
                $this->success('Profile saved. You can now apply for enrollment.', '/student/enrollment/apply');
                return;
            }
            if (in_array($current['application_status'], [EnrollmentApplication::DRAFT, EnrollmentApplication::CORRECTION_REQUIRED], true)) {
                $this->success('Profile saved.', '/student/enrollment/' . $current['id']);
                return;
            }
        }

        $this->success('Profile saved.', '/student/profile');
    }

    /** Stream the signed-in student's own photo (inline). Never linked directly; served from storage/. */
    public function photo(Request $request): void
    {
        $student = RegistrationService::ensureStudentRecord(Auth::user());
        $photo = StudentProfileService::photo((int) $student['id']);
        if ($photo === null) {
            throw HttpException::notFound('No photo has been uploaded.');
        }
        FileStreamer::send($photo['path'], 'photo.' . pathinfo($photo['path'], PATHINFO_EXTENSION), $photo['mime'], true);
    }
}
