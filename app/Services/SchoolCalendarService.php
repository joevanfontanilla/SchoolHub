<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Core\ValidationException;
use App\Models\CalendarEvent;
use App\Models\User;

/**
 * The school academic calendar (docs/09): dated activities and no-class days that the public
 * site and the portal display. Entries are maintained by any user holding `calendar.manage`
 * (the registrar and the Super Admin by default). Every change is audited.
 *
 * This is separate from AcademicCalendarService, which sets a term's enrollment window; this
 * one is the published, human-readable calendar of activities.
 */
final class SchoolCalendarService
{
    /** Suggested categories (free text: a school may add its own). */
    public const CATEGORIES = ['First Semester', 'Second Semester', 'Summer', 'Dates of No Classes'];

    /** @return array<string,mixed> the created row */
    public static function create(User $actor, array $input): array
    {
        self::assertManager($actor);
        $data = self::validated($input);

        $id = Database::insert('calendar_events', $data);
        AuditService::log('calendar.event_created', 'academic', 'CalendarEvent', $id, null, $data, 'SUCCESS', null, $actor->id, $actor->role_name);

        return CalendarEvent::find($id) ?? [];
    }

    public static function update(User $actor, int $id, array $input): void
    {
        self::assertManager($actor);
        $existing = CalendarEvent::find($id);
        if ($existing === null) {
            throw HttpException::notFound('That calendar entry was not found.');
        }
        $data = self::validated($input);

        Database::update('calendar_events', $data, ['id' => $id]);
        AuditService::log('calendar.event_updated', 'academic', 'CalendarEvent', $id, [
            'category' => $existing['category'], 'date_label' => $existing['date_label'], 'activity' => $existing['activity'],
        ], $data, 'SUCCESS', null, $actor->id, $actor->role_name);
    }

    public static function delete(User $actor, int $id): void
    {
        self::assertManager($actor);
        $existing = CalendarEvent::find($id);
        if ($existing === null) {
            throw HttpException::notFound('That calendar entry was not found.');
        }

        Database::query('DELETE FROM calendar_events WHERE id = ?', [$id]);
        AuditService::log('calendar.event_deleted', 'academic', 'CalendarEvent', $id, [
            'academic_year' => $existing['academic_year'], 'category' => $existing['category'],
            'date_label' => $existing['date_label'], 'activity' => $existing['activity'],
        ], null, 'SUCCESS', null, $actor->id, $actor->role_name);
    }

    /**
     * Validate and normalize the input into a storable row.
     *
     * @return array{academic_year:string,category:string,date_label:string,sort_date:?string,activity:string}
     */
    private static function validated(array $input): array
    {
        $validator = Validator::make($input, [
            'academic_year' => 'required|max:20',
            'category'      => 'required|max:50',
            'date_label'    => 'required|max:120',
            'sort_date'     => 'nullable|date',
            'activity'      => 'required|max:255',
        ]);
        if ($validator->fails()) {
            throw ValidationException::withErrors($validator->errors());
        }

        $sortDate = trim((string) ($input['sort_date'] ?? ''));

        return [
            'academic_year' => trim((string) $input['academic_year']),
            'category'      => trim((string) $input['category']),
            'date_label'    => trim((string) $input['date_label']),
            'sort_date'     => $sortDate !== '' ? $sortDate : null,
            'activity'      => trim((string) $input['activity']),
        ];
    }

    private static function assertManager(User $user): void
    {
        if (!$user->hasPermission('calendar.manage')) {
            throw HttpException::forbidden('You do not have permission to manage the academic calendar.');
        }
    }
}
