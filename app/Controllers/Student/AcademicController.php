<?php

declare(strict_types=1);

namespace App\Controllers\Student;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Models\ClassSchedule;
use App\Services\AcademicRecordService;
use App\Services\RegistrationService;

/**
 * The signed-in student's own schedule, grades, attendance, and history (docs/08).
 *
 * Nothing here takes an id from the request: every query starts from the
 * signed-in user's own student record, so another student's data is unreachable.
 */
final class AcademicController extends Controller
{
    public function schedule(Request $request): void
    {
        [$enrollment, $classes] = $this->current();
        $active = array_values(array_filter($classes, static fn ($c) => $c['enlistment_status'] === 'ENLISTED'));

        $this->appView('student/academic/schedule', [
            'title'      => 'Schedule',
            'enrollment' => $enrollment,
            'classes'    => $active,
            'blocks'     => ClassSchedule::blocks($active),
        ]);
    }

    public function grades(Request $request): void
    {
        [$enrollment, $classes] = $this->current();

        $this->appView('student/academic/grades', [
            'title'      => 'Grades',
            'enrollment' => $enrollment,
            'classes'    => $classes,
            'periods'    => $enrollment === null ? [] : AcademicRecordService::periods((int) $enrollment['term_id']),
        ]);
    }

    public function attendance(Request $request): void
    {
        [$enrollment, $classes] = $this->current();

        $this->appView('student/academic/attendance', [
            'title'      => 'Attendance',
            'enrollment' => $enrollment,
            'classes'    => $classes,
            'attendance' => $enrollment === null ? [] : ClassSchedule::attendanceForEnrollment((int) $enrollment['id']),
        ]);
    }

    public function history(Request $request): void
    {
        $this->appView('student/academic/history', [
            'title'   => 'Academic history',
            'history' => AcademicRecordService::history($this->studentId()),
        ]);
    }

    /** @return array{0:?array,1:array} the current enrollment and its classes */
    private function current(): array
    {
        $enrollment = AcademicRecordService::currentEnrollment($this->studentId());
        return [$enrollment, $enrollment === null ? [] : AcademicRecordService::classesForEnrollment((int) $enrollment['id'])];
    }

    private function studentId(): int
    {
        return (int) RegistrationService::ensureStudentRecord(Auth::user())['id'];
    }
}
