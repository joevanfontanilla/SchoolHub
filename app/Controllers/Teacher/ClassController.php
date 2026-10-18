<?php

declare(strict_types=1);

namespace App\Controllers\Teacher;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Core\ValidationException;
use App\Models\ClassSchedule;
use App\Models\GradePeriod;
use App\Services\AcademicRecordService;
use App\Services\AttendanceService;
use App\Services\GradeCalculator;
use App\Services\GradingService;

/**
 * A teacher's own classes: roster, attendance, grades, and timetable (docs/12).
 *
 * Every class is looked up through GradingService::ownedClass(), so a class
 * assigned to someone else is a 404 — the same as one that does not exist.
 */
final class ClassController extends Controller
{
    public function index(Request $request): void
    {
        $classes = ClassSchedule::forTeacher(Auth::id());
        $this->appView('teacher/classes/index', [
            'title'   => 'My classes',
            'classes' => $classes,
            'today'   => (int) date('N'),
            'mode'    => 'classes',
        ]);
    }

    /** Pick a class to take attendance for. */
    public function attendanceIndex(Request $request): void
    {
        $this->appView('teacher/classes/index', [
            'title'   => 'Attendance',
            'classes' => ClassSchedule::forTeacher(Auth::id()),
            'today'   => (int) date('N'),
            'mode'    => 'attendance',
        ]);
    }

    /** Pick a class and an open period to grade. */
    public function gradesIndex(Request $request): void
    {
        $classes = ClassSchedule::forTeacher(Auth::id());
        $open = GradePeriod::openForTeacher(Auth::id());

        $progress = [];
        foreach ($classes as $class) {
            foreach ($open as $period) {
                if ((int) $period['term_id'] === (int) $class['term_id']) {
                    $progress[(int) $class['id']][] = $period + ['graded' => ClassSchedule::gradedCount((int) $class['id'], (int) $period['id'])];
                }
            }
        }

        $this->appView('teacher/classes/grades-index', [
            'title'    => 'Grades',
            'classes'  => $classes,
            'progress' => $progress,
            'open'     => $open,
        ]);
    }

    public function show(Request $request): void
    {
        $class = $this->ownedClass($request);
        $periods = AcademicRecordService::periods((int) $class['term_id']);
        foreach ($periods as &$period) {
            $period['graded'] = ClassSchedule::gradedCount((int) $class['id'], (int) $period['id']);
        }
        unset($period);

        $this->appView('teacher/classes/show', [
            'title'   => $class['code'] . ' · ' . $class['section_code'],
            'class'   => $class,
            'roster'  => ClassSchedule::roster((int) $class['id']),
            'periods' => $periods,
            'dates'   => ClassSchedule::attendanceDates((int) $class['id'], 5),
        ]);
    }

    public function attendance(Request $request): void
    {
        $class = $this->ownedClass($request);
        $date = (string) $request->input('date', '') ?: $this->latestMeetingDate($class);
        $problem = AttendanceService::dateProblem($class, $date);

        $this->appView('teacher/classes/attendance', [
            'title'    => 'Attendance · ' . $class['code'],
            'class'    => $class,
            'date'     => $date,
            'problem'  => $problem,
            'sheet'    => $problem === null ? ClassSchedule::attendanceSheet((int) $class['id'], $date) : [],
            'dates'    => ClassSchedule::attendanceDates((int) $class['id']),
            'statuses' => AttendanceService::STATUSES,
        ]);
    }

    public function saveAttendance(Request $request): void
    {
        $class = $this->ownedClass($request);
        $date = (string) $request->input('date', '');
        $back = '/teacher/classes/' . $class['id'] . '/attendance?date=' . rawurlencode($date);

        try {
            $result = AttendanceService::record(Auth::user(), (int) $class['id'], $date, (array) $request->input('status', []), (array) $request->input('remarks', []));
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), $back);
            return;
        }

        $message = $result['saved'] === 0 && $result['changed'] === 0
            ? 'Nothing changed.'
            : sprintf('Attendance for %s saved: %d new, %d changed.', d($date), $result['saved'], $result['changed']);
        $this->success($message, $back);
    }

    public function grades(Request $request): void
    {
        $class = $this->ownedClass($request);
        $periods = AcademicRecordService::periods((int) $class['term_id']);

        $periodId = (int) $request->input('period', 0);
        $period = null;
        foreach ($periods as $candidate) {
            if ((int) $candidate['id'] === $periodId) {
                $period = $candidate;
            }
        }
        // Default to the first open period, else the first period.
        if ($period === null) {
            $open = array_values(array_filter($periods, static fn ($p) => (int) $p['is_open'] === 1));
            $period = $open[0] ?? $periods[0] ?? null;
        }

        $this->appView('teacher/classes/grades', [
            'title'   => 'Grades · ' . $class['code'],
            'class'   => $class,
            'periods' => $periods,
            'period'  => $period,
            'sheet'   => ClassSchedule::gradeSheet((int) $class['id'], (int) $class['term_id']),
        ]);
    }

    public function saveGrades(Request $request): void
    {
        $class = $this->ownedClass($request);
        $periodId = (int) $request->input('period_id', 0);
        $back = '/teacher/classes/' . $class['id'] . '/grades?period=' . $periodId;

        try {
            $result = GradingService::recordGrades(Auth::user(), (int) $class['id'], $periodId, (array) $request->input('grades', []));
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), $back);
            return;
        }

        $message = $result['saved'] === 0 && $result['changed'] === 0
            ? 'Nothing changed.'
            : sprintf('Grades saved: %d new, %d changed.', $result['saved'], $result['changed']);
        $this->success($message, $back);
    }

    public function schedule(Request $request): void
    {
        $classes = ClassSchedule::forTeacher(Auth::id());
        $this->appView('teacher/schedule', [
            'title'   => 'Schedule',
            'classes' => $classes,
            'blocks'  => ClassSchedule::blocks($classes),
        ]);
    }

    /** @return array<string,mixed> the class, with meetings and term details */
    private function ownedClass(Request $request): array
    {
        $owned = GradingService::ownedClass(Auth::user(), (int) $request->param('id'));
        return ClassSchedule::find((int) $owned['id']) + ['term_id' => $owned['term_id']];
    }

    /**
     * The most recent day, up to today and within the term, that the class meets —
     * the date a teacher most likely wants when opening the sheet.
     *
     * @param array<string,mixed> $class
     */
    private function latestMeetingDate(array $class): string
    {
        $days = array_map(static fn ($m) => (int) $m['day_of_week'], $class['meetings']);
        $date = new \DateTimeImmutable(min(date('Y-m-d'), (string) $class['term_end']));

        for ($i = 0; $i < 7 && $days !== []; $i++) {
            if (in_array((int) $date->format('N'), $days, true) && $date->format('Y-m-d') >= $class['term_start']) {
                return $date->format('Y-m-d');
            }
            $date = $date->modify('-1 day');
        }

        return date('Y-m-d');
    }
}
