<?php
/**
 * The academic calendar (docs/09): the registrar sets each semester's dates and enrollment
 * window, and adds a new academic year with its semesters. Dates are set here, not fixed.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Models\Term;
use App\Models\User;
use App\Services\AcademicCalendarService;

/** Remove a fixture academic year created by a test, with its terms and grade periods. */
function deleteFixtureYear(string $code): void
{
    $yearId = Database::scalar('SELECT id FROM academic_years WHERE code = ?', [$code]);
    if ($yearId === null) {
        return;
    }
    $termIds = array_column(Database::select('SELECT id FROM terms WHERE academic_year_id = ?', [(int) $yearId]), 'id');
    foreach ($termIds as $termId) {
        Database::query('DELETE FROM grade_periods WHERE term_id = ?', [(int) $termId]);
    }
    Database::query('DELETE FROM terms WHERE academic_year_id = ?', [(int) $yearId]);
    Database::query('DELETE FROM academic_years WHERE id = ?', [(int) $yearId]);
}

return [
    'adding an academic year creates its semesters with dates and closed grading periods' => function (TestRunner $t): void {
        $code = '2098-2099';
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            AcademicCalendarService::addYear($registrar, [
                'code' => $code,
                'semesters' => [
                    1 => ['start_date' => '2098-08-01', 'end_date' => '2098-12-20', 'enrollment_opens_at' => '2098-06-01T08:00', 'enrollment_closes_at' => '2098-07-31T17:00'],
                    2 => ['start_date' => '2099-01-05', 'end_date' => '2099-05-30'],
                    // Summer left blank — skipped.
                ],
            ]);

            $terms = Database::select('SELECT t.* FROM terms t JOIN academic_years ay ON ay.id = t.academic_year_id WHERE ay.code = ? ORDER BY t.sequence', [$code]);
            $t->assertEquals(2, count($terms), 'Two semesters created, summer skipped');
            $t->assertEquals('2098-08-01', $terms[0]['start_date']);
            $t->assertEquals('1st Semester', $terms[0]['name']);
            $t->assertTrue($terms[0]['enrollment_opens_at'] !== null, 'The enrollment window is stored');
            $t->assertEquals(3, (int) Database::scalar('SELECT COUNT(*) FROM grade_periods WHERE term_id = ?', [(int) $terms[0]['id']]), 'Prelim/Midterm/Finals seeded');
            $t->assertEquals('2098-2099', Database::scalar('SELECT code FROM academic_years WHERE id = ?', [(int) $terms[0]['academic_year_id']]));
        } finally {
            deleteFixtureYear($code);
            deleteTestUsers();
        }
    },

    'setting a term\'s enrollment window opens or closes enrollment, and is audited' => function (TestRunner $t): void {
        $code = '2097-2098';
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            AcademicCalendarService::addYear($registrar, [
                'code' => $code,
                'semesters' => [1 => ['start_date' => '2097-08-01', 'end_date' => '2097-12-20']],
            ]);
            $termId = (int) Database::scalar('SELECT t.id FROM terms t JOIN academic_years ay ON ay.id = t.academic_year_id WHERE ay.code = ?', [$code]);

            // A window that includes now: Term::isOpen becomes true.
            AcademicCalendarService::saveTerm($registrar, $termId, [
                'start_date' => '2097-08-01', 'end_date' => '2097-12-20',
                'enrollment_opens_at' => date('Y-m-d\TH:i', strtotime('-1 day')),
                'enrollment_closes_at' => date('Y-m-d\TH:i', strtotime('+7 days')),
            ]);
            $t->assertTrue(Term::isOpen(Term::find($termId)), 'The term is open for enrollment inside the window');

            // A past window: closed again.
            AcademicCalendarService::saveTerm($registrar, $termId, [
                'start_date' => '2097-08-01', 'end_date' => '2097-12-20',
                'enrollment_opens_at' => '2097-01-01T08:00', 'enrollment_closes_at' => '2097-02-01T17:00',
            ]);
            $t->assertFalse(Term::isOpen(Term::find($termId)), 'Outside the window it is closed');

            $t->assertEquals(2, (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'academic.term_dates_set' AND target_id = ?", [$termId]), 'Each change is audited');
        } finally {
            deleteFixtureYear($code);
            deleteTestUsers();
        }
    },

    'invalid dates and years are refused' => function (TestRunner $t): void {
        $code = '2096-2097';
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            AcademicCalendarService::addYear($registrar, [
                'code' => $code,
                'semesters' => [1 => ['start_date' => '2096-08-01', 'end_date' => '2096-12-20']],
            ]);
            $termId = (int) Database::scalar('SELECT t.id FROM terms t JOIN academic_years ay ON ay.id = t.academic_year_id WHERE ay.code = ?', [$code]);

            $t->assertTrue(isset(fieldErrors(static fn () => AcademicCalendarService::saveTerm($registrar, $termId, ['start_date' => '2096-12-20', 'end_date' => '2096-08-01']))['end_date']), 'End before start is refused');
            $t->assertTrue(isset(fieldErrors(static fn () => AcademicCalendarService::saveTerm($registrar, $termId, ['start_date' => '2096-08-01', 'end_date' => '2096-12-20', 'enrollment_opens_at' => '2096-07-31T17:00', 'enrollment_closes_at' => '2096-07-01T08:00']))['enrollment_closes_at']), 'Enrollment closing before it opens is refused');

            $t->assertTrue(isset(fieldErrors(static fn () => AcademicCalendarService::addYear($registrar, ['code' => 'nope', 'semesters' => [1 => ['start_date' => '2096-08-01', 'end_date' => '2096-12-20']]]))['code']), 'A malformed year code is refused');
            $t->assertTrue(isset(fieldErrors(static fn () => AcademicCalendarService::addYear($registrar, ['code' => $code, 'semesters' => [1 => ['start_date' => '2096-08-01', 'end_date' => '2096-12-20']]]))['code']), 'A duplicate year is refused');
            $t->assertTrue(fieldErrors(static fn () => AcademicCalendarService::addYear($registrar, ['code' => '2100-2101', 'semesters' => []])) !== [], 'A year with no semesters is refused');
            $t->assertEquals(0, (int) Database::scalar("SELECT COUNT(*) FROM academic_years WHERE code = ?", ['2100-2101']), 'The refused year was not created');
        } finally {
            deleteFixtureYear($code);
            deleteTestUsers();
        }
    },

    'a role without academic.manage cannot change the calendar' => function (TestRunner $t): void {
        $code = '2095-2096';
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            AcademicCalendarService::addYear($registrar, ['code' => $code, 'semesters' => [1 => ['start_date' => '2095-08-01', 'end_date' => '2095-12-20']]]);
            $termId = (int) Database::scalar('SELECT t.id FROM terms t JOIN academic_years ay ON ay.id = t.academic_year_id WHERE ay.code = ?', [$code]);
            $cashier = makeUser(User::ROLE_CASHIER);

            $t->assertEquals(403, statusOf(static fn () => AcademicCalendarService::saveTerm($cashier, $termId, ['start_date' => '2095-08-01', 'end_date' => '2095-12-20'])), 'A cashier cannot set term dates');
            $t->assertEquals(403, statusOf(static fn () => AcademicCalendarService::addYear($cashier, ['code' => '2101-2102', 'semesters' => [1 => ['start_date' => '2101-08-01', 'end_date' => '2101-12-20']]])), 'A cashier cannot add a year');
        } finally {
            deleteFixtureYear($code);
            deleteTestUsers();
        }
    },
];
