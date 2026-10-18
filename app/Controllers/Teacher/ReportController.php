<?php

declare(strict_types=1);

namespace App\Controllers\Teacher;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Models\ClassSchedule;
use App\Services\AcademicRecordService;
use App\Services\AuditService;
use App\Services\CsvExport;
use App\Services\GradeCalculator;
use App\Services\GradingService;

/**
 * Class reports for a teacher's own classes (docs/15): the class list, the attendance
 * summary, and the grade sheet, each printable and as a CSV export.
 *
 * The class is found through GradingService::ownedClass(), so another teacher's class
 * is a 404, exactly as on the roster, attendance, and grade pages. Exports are audited
 * because they take student records out of the system.
 */
final class ReportController extends Controller
{
    public function index(Request $request): void
    {
        $this->appView('teacher/reports/index', [
            'title'   => 'Class Reports',
            'classes' => ClassSchedule::forTeacher(Auth::id()),
        ]);
    }

    public function classList(Request $request): void
    {
        $class = $this->ownedClass($request);
        $this->printView('print/class-list', [
            'title'  => 'Class List · ' . $class['code'] . ' ' . $class['section_code'],
            'class'  => $class,
            'roster' => ClassSchedule::roster((int) $class['id']),
            'back'   => '/teacher/reports',
        ]);
    }

    public function classListExport(Request $request): void
    {
        $class = $this->ownedClass($request);
        $roster = ClassSchedule::roster((int) $class['id']);
        $this->audit('class-list', $class, count($roster));

        CsvExport::send($this->filename($class, 'class-list'),
            ['#', 'Student No.', 'Last name', 'First name', 'Middle name', 'Program', 'Year level', 'Email'],
            array_map(static fn (array $row, int $i): array => [
                $i + 1, $row['student_number'], $row['last_name'], $row['first_name'], $row['middle_name'] ?? '',
                $row['program_code'] ?? '', $row['year_level_name'] ?? '', $row['email'],
            ], $roster, array_keys($roster))
        );
    }

    public function attendance(Request $request): void
    {
        $class = $this->ownedClass($request);
        $this->printView('print/class-attendance', [
            'title'   => 'Attendance · ' . $class['code'] . ' ' . $class['section_code'],
            'class'   => $class,
            'summary' => ClassSchedule::attendanceSummary((int) $class['id']),
            'back'    => '/teacher/reports',
        ]);
    }

    public function attendanceExport(Request $request): void
    {
        $class = $this->ownedClass($request);
        $summary = ClassSchedule::attendanceSummary((int) $class['id']);
        $this->audit('attendance', $class, count($summary['students']));

        // One column per date with P/A/L/E, after the totals, so the sheet reads like a class record.
        $letters = ['PRESENT' => 'P', 'ABSENT' => 'A', 'LATE' => 'L', 'EXCUSED' => 'E'];
        CsvExport::send($this->filename($class, 'attendance'),
            ['Student No.', 'Last name', 'First name', 'Present', 'Late', 'Absent', 'Excused', 'Attendance %', ...$summary['dates']],
            array_map(static fn (array $row): array => [
                $row['student_number'], $row['last_name'], $row['first_name'],
                $row['counts']['PRESENT'], $row['counts']['LATE'], $row['counts']['ABSENT'], $row['counts']['EXCUSED'], $row['rate'] ?? '',
                ...array_map(static fn (string $date) => $letters[$row['marks'][$date] ?? ''] ?? '', $summary['dates']),
            ], $summary['students'])
        );
    }

    public function grades(Request $request): void
    {
        $class = $this->ownedClass($request);
        $this->printView('print/class-grades', [
            'title'   => 'Grade Sheet · ' . $class['code'] . ' ' . $class['section_code'],
            'class'   => $class,
            'periods' => AcademicRecordService::periods((int) $class['term_id']),
            'sheet'   => ClassSchedule::gradeSheet((int) $class['id'], (int) $class['term_id']),
            'back'    => '/teacher/reports',
        ]);
    }

    public function gradesExport(Request $request): void
    {
        $class = $this->ownedClass($request);
        $periods = AcademicRecordService::periods((int) $class['term_id']);
        $sheet = ClassSchedule::gradeSheet((int) $class['id'], (int) $class['term_id']);
        $this->audit('grades', $class, count($sheet));

        CsvExport::send($this->filename($class, 'grades'),
            ['Student No.', 'Last name', 'First name', ...array_map(static fn (array $p) => $p['name'], $periods), 'Final grade', 'Final %', 'Remark'],
            array_map(static fn (array $row): array => [
                $row['student_number'], $row['last_name'], $row['first_name'],
                ...array_map(static function (array $period) use ($row): string {
                    $entry = $row['period_grades'][(int) $period['id']] ?? null;
                    return $entry === null ? '' : GradeCalculator::display($entry);
                }, $periods),
                $row['final']['grade'] ?? '',
                $row['final']['percentage'] ?? '',
                GradeCalculator::remarkLabel($row['final']['remark']),
            ], $sheet)
        );
    }

    /** @return array<string,mixed> the class, with meetings and term details */
    private function ownedClass(Request $request): array
    {
        $owned = GradingService::ownedClass(Auth::user(), (int) $request->param('id'));
        return ClassSchedule::find((int) $owned['id']) + ['term_id' => $owned['term_id']];
    }

    private function filename(array $class, string $report): string
    {
        return strtolower($class['code'] . '-' . $class['section_code'] . '-' . $report . '-' . date('Y-m-d')) . '.csv';
    }

    private function audit(string $report, array $class, int $rows): void
    {
        AuditService::log('reports.exported', 'reports', 'ClassSchedule', (int) $class['id'], null,
            ['report' => 'teacher.' . $report, 'class' => $class['code'] . ' ' . $class['section_code'], 'rows' => $rows],
            'SUCCESS', null, Auth::user()->id, Auth::user()->role_name);
    }
}
