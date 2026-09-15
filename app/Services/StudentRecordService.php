<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Core\UploadedFile;
use App\Models\Student;
use App\Models\User;

/**
 * The registrar's corrections to a student record (docs/04 "manage student records").
 *
 * Uses the same validation as the student's own profile page. Unlike the
 * student, the registrar may correct the legal name after an application has
 * been submitted; the change is audited as a record correction, listing which
 * fields changed but never their values.
 */
final class StudentRecordService
{
    /** @param array<string,mixed> $input */
    public static function update(User $registrar, int $studentId, array $input, ?UploadedFile $photo = null): void
    {
        if (!$registrar->isRegistrar()) {
            throw HttpException::forbidden('Only the registrar can correct student records.');
        }

        $student = Student::find($studentId);
        if ($student === null) {
            throw HttpException::notFound('That student was not found.');
        }

        StudentProfileService::save($studentId, (int) $student['user_id'], $input, true, $registrar, $photo);
    }
}
