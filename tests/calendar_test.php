<?php
/**
 * The school academic calendar (docs/09): who may edit it, ordering, and validation.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Models\CalendarEvent;
use App\Models\User;
use App\Services\SchoolCalendarService as Cal;

/** Fixture entries use a ZZT academic year, removed by cleanCalendar(). */
function calInput(array $overrides = []): array
{
    return array_merge([
        'academic_year' => 'ZZT-2099',
        'category'      => 'First Semester',
        'date_label'    => 'June 15, 2099',
        'sort_date'     => '2099-06-15',
        'activity'      => 'Beginning of classes',
    ], $overrides);
}

function cleanCalendar(): void
{
    Database::query("DELETE FROM calendar_events WHERE academic_year LIKE 'ZZT%'");
}

return [
    'a manager adds, edits, and removes an entry; each change is audited' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);

            $row = Cal::create($registrar, calInput());
            $id = (int) $row['id'];
            $t->assertEquals('First Semester', $row['category']);
            $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'calendar.event_created' AND target_id = ?", [$id]));

            Cal::update($registrar, $id, calInput(['activity' => 'Start of classes']));
            $t->assertEquals('Start of classes', Database::scalar('SELECT activity FROM calendar_events WHERE id = ?', [$id]));
            $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'calendar.event_updated' AND target_id = ?", [$id]));

            Cal::delete($registrar, $id);
            $t->assertNull(CalendarEvent::find($id), 'The entry is gone');
            $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'calendar.event_deleted' AND target_id = ?", [$id]));
        } finally {
            cleanCalendar();
            deleteTestUsers();
        }
    },

    'the Super Admin manages it too; a student without the permission is refused' => function (TestRunner $t): void {
        try {
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            $row = Cal::create($admin, calInput());
            $id = (int) $row['id'];
            $t->assertNotNull(CalendarEvent::find($id), 'Super Admin holds every permission implicitly');

            $student = makeStudent();
            $t->assertEquals(403, statusOf(static fn () => Cal::create($student, calInput())));
            $t->assertEquals(403, statusOf(static fn () => Cal::update($student, $id, calInput())));
            $t->assertEquals(403, statusOf(static fn () => Cal::delete($student, $id)));
            $t->assertNotNull(CalendarEvent::find($id), 'The student changed nothing');
        } finally {
            cleanCalendar();
            deleteTestUsers();
            deleteTestCatalog();
        }
    },

    'entries are grouped by category in reading order (semesters first, no-class days last)' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            Cal::create($registrar, calInput(['category' => 'Dates of No Classes', 'date_label' => 'July 21, 2099', 'sort_date' => '2099-07-21', 'activity' => 'Maria Aurora Day']));
            Cal::create($registrar, calInput(['category' => 'First Semester', 'date_label' => 'June 15, 2099', 'sort_date' => '2099-06-15', 'activity' => 'Beginning of classes']));
            Cal::create($registrar, calInput(['category' => 'Second Semester', 'date_label' => 'Nov 3, 2099', 'sort_date' => '2099-11-03', 'activity' => 'Second semester begins']));

            $order = array_column(CalendarEvent::grouped('ZZT-2099'), 'category');
            $t->assertEquals(['First Semester', 'Second Semester', 'Dates of No Classes'], $order);
        } finally {
            cleanCalendar();
            deleteTestUsers();
        }
    },

    'required fields and a bad sort date are refused, and nothing is stored' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $t->assertTrue(isset(fieldErrors(static fn () => Cal::create($registrar, calInput(['activity' => ''])))['activity']));
            $t->assertTrue(isset(fieldErrors(static fn () => Cal::create($registrar, calInput(['date_label' => ''])))['date_label']));
            $t->assertTrue(isset(fieldErrors(static fn () => Cal::create($registrar, calInput(['sort_date' => 'not-a-date'])))['sort_date']));
            $t->assertEquals(0, (int) Database::scalar("SELECT COUNT(*) FROM calendar_events WHERE academic_year LIKE 'ZZT%'"), 'Nothing stored on failure');

            // A blank sort date is allowed (nullable).
            $row = Cal::create($registrar, calInput(['sort_date' => '']));
            $t->assertNull(Database::scalar('SELECT sort_date FROM calendar_events WHERE id = ?', [(int) $row['id']]));
        } finally {
            cleanCalendar();
            deleteTestUsers();
        }
    },
];
