<?php

declare(strict_types=1);

namespace App\Controllers\Manage;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Core\ValidationException;
use App\Models\CalendarEvent;
use App\Services\SchoolCalendarService;

/**
 * Maintain the school academic calendar (docs/09). Any user holding `calendar.manage`
 * (the registrar and the Super Admin by default) can add, edit, and remove entries.
 */
final class CalendarController extends Controller
{
    public function index(Request $request): void
    {
        $years = CalendarEvent::years();
        $requested = trim((string) $request->input('ay', ''));
        $year = in_array($requested, $years, true)
            ? $requested
            : (CalendarEvent::latestYear() ?? self::currentAcademicYear());

        $editId = (int) $request->input('edit', 0);
        $editing = $editId > 0 ? CalendarEvent::find($editId) : null;

        $this->appView('manage/calendar/index', [
            'title'      => 'Academic Calendar',
            'years'      => $years,
            'year'       => $year,
            'groups'     => CalendarEvent::grouped($year),
            'editing'    => $editing,
            'categories' => SchoolCalendarService::CATEGORIES,
        ]);
    }

    public function store(Request $request): void
    {
        try {
            $row = SchoolCalendarService::create(Auth::user(), $request->all());
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), '/manage/calendar?ay=' . rawurlencode((string) $request->input('academic_year', '')));
            return;
        }
        $this->success('Calendar entry added.', '/manage/calendar?ay=' . rawurlencode((string) $row['academic_year']));
    }

    public function update(Request $request): void
    {
        $id = (int) $request->param('id');
        try {
            SchoolCalendarService::update(Auth::user(), $id, $request->all());
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), '/manage/calendar?edit=' . $id);
            return;
        }
        $this->success('Calendar entry updated.', '/manage/calendar?ay=' . rawurlencode((string) $request->input('academic_year', '')));
    }

    public function destroy(Request $request): void
    {
        SchoolCalendarService::delete(Auth::user(), (int) $request->param('id'));
        $this->success('Calendar entry removed.', '/manage/calendar?ay=' . rawurlencode((string) $request->input('ay', '')));
    }

    /** The current academic year, assuming it starts in June (e.g. "2026-2027"). */
    private static function currentAcademicYear(): string
    {
        $start = (int) date('n') >= 6 ? (int) date('Y') : (int) date('Y') - 1;
        return $start . '-' . ($start + 1);
    }
}
