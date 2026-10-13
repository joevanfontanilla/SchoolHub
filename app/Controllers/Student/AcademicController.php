<?php

declare(strict_types=1);

namespace App\Controllers\Student;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Models\ClassSchedule;
use App\Models\Curriculum;
use App\Services\AcademicRecordService;
use App\Services\RegistrationService;

/**
 * Academic Records: the signed-in student's own grades, schedule, attendance,
 * and history, as tabs of one page (docs/08, docs/21).
 *
 * Nothing here takes a record id from the request. The optional ?term_id only
 * picks among the signed-in student's OWN enrollments; any other value falls
 * back to their latest term, so another student's data is unreachable.
 */
final class AcademicController extends Controller
{
    public function grades(Request $request): void
    {
        $records = $this->records($request, 'grades');
        $history = AcademicRecordService::history($records['studentId']);

        $termRows = array_map(static fn (array $term) => $term + ['summary' => AcademicRecordService::termSummary($term['classes'])], $history);
        $allClasses = array_merge(...array_map(static fn ($t) => $t['classes'], $history ?: [['classes' => []]]));

        $this->appView('student/academic/grades', $records + [
            'title'         => 'Academic Records',
            'termRows'      => $termRows,
            'cumulativeGwa' => \App\Services\GradeCalculator::gwa(array_filter($allClasses, static fn ($c) => $c['enlistment_status'] !== 'DROPPED')),
        ]);
    }

    public function schedule(Request $request): void
    {
        $records = $this->records($request, 'schedule');
        $active = array_values(array_filter($records['classes'], static fn ($c) => $c['enlistment_status'] === 'ENLISTED'));

        $this->appView('student/academic/schedule', $records + [
            'title'   => 'Class Schedule',
            'active'  => $active,
            'blocks'  => ClassSchedule::blocks($active),
        ]);
    }

    public function attendance(Request $request): void
    {
        $records = $this->records($request, 'attendance');

        $this->appView('student/academic/attendance', $records + [
            'title'      => 'Attendance',
            'attendance' => $records['enrollment'] === null ? [] : ClassSchedule::attendanceForEnrollment((int) $records['enrollment']['id']),
        ]);
    }

    public function history(Request $request): void
    {
        $records = $this->records($request, 'history');
        $history = AcademicRecordService::history($records['studentId']);
        $termRows = array_map(static fn (array $term) => $term + ['summary' => AcademicRecordService::termSummary($term['classes'])], $history);
        $allClasses = array_merge(...array_map(static fn ($t) => $t['classes'], $history ?: [['classes' => []]]));

        $this->appView('student/academic/history', $records + [
            'title'         => 'Academic History',
            'termRows'      => $termRows,
            'cumulativeGwa' => \App\Services\GradeCalculator::gwa(array_filter($allClasses, static fn ($c) => $c['enlistment_status'] !== 'DROPPED')),
        ]);
    }

    public function prospectus(Request $request): void
    {
        $records = $this->records($request, 'prospectus');
        $this->appView('student/academic/prospectus', $records + [
            'title'      => 'Curriculum Checklist',
            'prospectus' => AcademicRecordService::prospectus($records['studentId']),
        ]);
    }

    /**
     * What every tab shares: the student's terms, the chosen one with its classes,
     * that term's summary, and progress through the curriculum.
     *
     * @return array<string,mixed>
     */
    private function records(Request $request, string $tab): array
    {
        $student = RegistrationService::ensureStudentRecord(Auth::user());
        $studentId = (int) $student['id'];
        $enrollments = AcademicRecordService::enrollmentsOf($studentId);

        $wanted = (int) $request->input('term_id', 0);
        $enrollment = $enrollments[0] ?? null;
        foreach ($enrollments as $candidate) {
            if ((int) $candidate['term_id'] === $wanted) {
                $enrollment = $candidate;
            }
        }

        $classes = $enrollment === null ? [] : AcademicRecordService::classesForEnrollment((int) $enrollment['id']);
        $curriculum = $student['curriculum_id'] === null ? null : Curriculum::find((int) $student['curriculum_id']);

        return [
            'studentId'   => $studentId,
            'tab'         => $tab,
            'enrollments' => $enrollments,
            'enrollment'  => $enrollment,
            'classes'     => $classes,
            'periods'     => $enrollment === null ? [] : AcademicRecordService::periods((int) $enrollment['term_id']),
            'summary'     => AcademicRecordService::termSummary($classes),
            'curriculum'  => $curriculum,
            'progress'    => $curriculum === null ? null : AcademicRecordService::unitsEarned($studentId, (int) $curriculum['id']),
        ];
    }
}
