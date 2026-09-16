<?php

declare(strict_types=1);

namespace App\Controllers\Registrar;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Core\ValidationException;
use App\Models\Catalog;
use App\Services\AcademicCalendarService;

/**
 * The academic calendar (docs/09): the registrar sets each semester's dates and enrollment
 * window, and adds the next academic year. Governed by `academic.manage`.
 */
final class AcademicCalendarController extends Controller
{
    public function index(Request $request): void
    {
        $this->appView('registrar/calendar/index', [
            'title'     => 'Academic Calendar',
            'terms'     => Catalog::allTerms(),
            'semesters' => AcademicCalendarService::SEMESTERS,
        ]);
    }

    public function saveTerm(Request $request): void
    {
        $id = (int) $request->param('id');
        try {
            AcademicCalendarService::saveTerm(Auth::user(), $id, $request->all());
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), '/registrar/calendar#term-' . $id);
            return;
        }
        $this->success('Semester dates saved.', '/registrar/calendar#term-' . $id);
    }

    public function addYear(Request $request): void
    {
        try {
            AcademicCalendarService::addYear(Auth::user(), $request->all());
        } catch (ValidationException $e) {
            $this->failWith($e, $request->all(), '/registrar/calendar#add-year');
            return;
        }
        $this->success('Academic year added.', '/registrar/calendar');
    }
}
