<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Models\CalendarEvent;

/**
 * The read-only school academic calendar (docs/09): a public page and a portal page,
 * both showing one academic year's activities. Editing is Manage\CalendarController.
 */
final class CalendarController extends Controller
{
    public function show(Request $request): void
    {
        $this->publicView('public/calendar', $this->load($request) + ['title' => 'Academic Calendar']);
    }

    public function portal(Request $request): void
    {
        $this->appView('portal/calendar', $this->load($request) + ['title' => 'Academic Calendar']);
    }

    /** @return array{years:array<int,string>,year:string,groups:array<int,mixed>} */
    private function load(Request $request): array
    {
        $years = CalendarEvent::years();
        $requested = trim((string) $request->input('ay', ''));
        $year = in_array($requested, $years, true) ? $requested : (CalendarEvent::latestYear() ?? '');

        return [
            'years'  => $years,
            'year'   => $year,
            'groups' => $year !== '' ? CalendarEvent::grouped($year) : [],
        ];
    }
}
