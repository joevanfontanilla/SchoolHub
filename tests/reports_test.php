<?php
/**
 * Registrar and teacher reports (docs/15).
 *
 * Fixtures sit in real terms beside real data, so each test looks for its own rows or
 * compares counts before and after, never absolute totals.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Controllers\Teacher\ReportController as TeacherReports;
use App\Core\Database;
use App\Core\Router;
use App\Models\ClassSchedule;
use App\Models\RegistrarReport;
use App\Models\User;
use App\Services\AttendanceService;

/** @return array<int,array{method:string,pattern:string,middleware:array<int,string>}> */
function reportRoutes(): array
{
    $router = new Router();
    require BASE_PATH . '/routes/web.php';
    return array_values(array_filter($router->routes(), static fn (array $r) => str_contains($r['pattern'], '/reports')));
}

function sectionProgram(int $sectionId): array
{
    return Database::selectOne('SELECT program_id, year_level_id, term_id FROM sections WHERE id = ?', [$sectionId]);
}

return [
    'every report route is a read-only GET gated to its role and report permission' => function (TestRunner $t): void {
        $routes = array_filter(reportRoutes(), static fn ($r) => !str_starts_with($r['pattern'], '/cashier/') && !str_starts_with($r['pattern'], '/admin/'));
        $t->assertEquals(20, count($routes), 'Registrar and teacher report routes');
        foreach ($routes as $route) {
            $label = "{$route['method']} {$route['pattern']}";
            $t->assertEquals('GET', $route['method'], "{$label} changes nothing");
            [$role, $permission] = str_starts_with($route['pattern'], '/registrar/')
                ? [User::ROLE_REGISTRAR, 'reports.registrar']
                : [User::ROLE_TEACHER, 'reports.teacher'];
            $t->assertTrue(in_array('role:' . $role, $route['middleware'], true), "{$label} is gated to {$role}");
            $t->assertTrue(in_array('can:' . $permission, $route['middleware'], true), "{$label} needs {$permission}");
        }
    },

    'the enrollment list shows enrolled students by program, year level, and section, and its filters narrow it' => function (TestRunner $t): void {
        try {
            $enrolled = enrolledStudent();
            $section = sectionProgram($enrolled['section_id']);
            $termId = (int) $section['term_id'];
            $number = (string) Database::scalar('SELECT student_number FROM students WHERE id = ?', [$enrolled['student_id']]);

            $numbers = static fn (array $report) => array_column($report['rows'], 'student_number');
            $all = RegistrarReport::enrollmentList($termId);
            $t->assertTrue(in_array($number, $numbers($all), true), 'Listed for the term');

            $bySection = RegistrarReport::enrollmentList($termId, null, null, $enrolled['section_id']);
            $t->assertEquals([$number], $numbers($bySection), 'The section filter lists only that section');
            $t->assertEquals(1, $bySection['groups'][0]['count']);
            $t->assertEquals(['New' => 1], $bySection['by_type']);

            $t->assertTrue(in_array($number, $numbers(RegistrarReport::enrollmentList($termId, (int) $section['program_id'], (int) $section['year_level_id'])), true), 'Program and year level');
            $otherProgram = (int) Database::scalar('SELECT id FROM programs WHERE id <> ? ORDER BY id LIMIT 1', [$section['program_id']]);
            $t->assertFalse(in_array($number, $numbers(RegistrarReport::enrollmentList($termId, $otherProgram)), true), 'Another program excludes it');
            $otherTerm = (int) Database::scalar('SELECT id FROM terms WHERE id <> ? ORDER BY id LIMIT 1', [$termId]);
            $t->assertFalse(in_array($number, $numbers(RegistrarReport::enrollmentList($otherTerm)), true), 'Another term excludes it');
        } finally {
            deleteTestUsers();
            deleteTestCatalog();
        }
    },

    'applicants by status count submitted applications, never drafts, and the status filter narrows only the list' => function (TestRunner $t): void {
        try {
            $termId = openTermId();
            $before = RegistrarReport::applicationsByStatus($termId)['counts'];

            [, $submittedId] = submittedApplication();
            $draftStudent = makeStudent();
            $draftId = App\Services\EnrollmentApplicationService::createDraft($draftStudent, applicationInput());

            $report = RegistrarReport::applicationsByStatus($termId);
            $t->assertEquals($before['SUBMITTED'] + 1, $report['counts']['SUBMITTED'], 'The submitted application is counted');
            $ids = array_map('intval', array_column($report['rows'], 'id'));
            $t->assertTrue(in_array($submittedId, $ids, true));
            $t->assertFalse(in_array($draftId, $ids, true), 'A draft is the student\'s private work and is left out');

            $row = array_values(array_filter($report['rows'], static fn ($r) => (int) $r['id'] === $submittedId))[0];
            $t->assertTrue((int) $row['required_count'] > 0 && (int) $row['verified_count'] === 0, 'Requirements verified of required');

            $approvedOnly = RegistrarReport::applicationsByStatus($termId, null, 'APPROVED');
            $t->assertFalse(in_array($submittedId, array_map('intval', array_column($approvedOnly['rows'], 'id')), true), 'The status filter narrows the list');
            $t->assertEquals($report['counts'], $approvedOnly['counts'], 'Counts still cover every status');
            $t->assertEquals($report['counts'], RegistrarReport::applicationsByStatus($termId, null, 'NOT_A_STATUS')['counts']);
        } finally {
            deleteTestUsers();
        }
    },

    'requirements status lists what an application still needs, and a fully verified one is complete' => function (TestRunner $t): void {
        try {
            $termId = openTermId();
            [, $submittedId] = submittedApplication();
            [, , $approvedId] = approvedApplication();

            $report = RegistrarReport::requirementsStatus($termId);
            $outstanding = array_column($report['outstanding'], null, 'id');
            $t->assertTrue(isset($outstanding[$submittedId]), 'Uploaded but not yet verified is outstanding');
            $required = (int) Database::scalar('SELECT COUNT(*) FROM enrollment_requirements WHERE application_id = ? AND is_required = 1', [$submittedId]);
            $t->assertEquals($required, count($outstanding[$submittedId]['missing']), 'Each unverified required document is named');
            $t->assertFalse(isset($outstanding[$approvedId]), 'Every required document verified');
            $t->assertEquals($report['applications'] - count($report['outstanding']), $report['complete']);

            $verified = array_sum(array_map(static fn ($type) => $type['counts']['VERIFIED'], $report['types']));
            $t->assertTrue($verified >= $required, 'Verified documents are counted by requirement');
        } finally {
            deleteTestUsers();
            deleteTestCatalog();
        }
    },

    'enrollment history counts applicants and enrolled students per term and program' => function (TestRunner $t): void {
        try {
            $find = static function (int $termId): array {
                foreach (RegistrarReport::enrollmentHistory()['terms'] as $term) {
                    if ((int) $term['id'] === $termId) {
                        return $term;
                    }
                }
                return [];
            };
            $termId = openTermId();
            $before = $find($termId);

            $enrolled = enrolledStudent();
            $programCode = (string) Database::scalar('SELECT p.code FROM sections s JOIN programs p ON p.id = s.program_id WHERE s.id = ?', [$enrolled['section_id']]);
            $after = $find($termId);

            $t->assertEquals($before['applicants'] + 1, $after['applicants']);
            $t->assertEquals($before['enrolled'] + 1, $after['enrolled']);
            $t->assertEquals(($before['by_program'][$programCode] ?? 0) + 1, $after['by_program'][$programCode]);
            $t->assertEquals($before['new'] + 1, $after['new'], 'A new student');
        } finally {
            deleteTestUsers();
            deleteTestCatalog();
        }
    },

    'the attendance summary totals each student\'s marks and leaves excused absences out of the rate' => function (TestRunner $t): void {
        try {
            $enrolled = enrolledStudent(currentTermId());
            $teacher = makeUser(User::ROLE_TEACHER);
            $classId = makeClass($enrolled['registrar'], $enrolled['section_id'], makeSubject(), $teacher->id, [
                meeting(1, '08:00', '09:00'), meeting(2, '08:00', '09:00'), meeting(3, '08:00', '09:00'), meeting(4, '08:00', '09:00'), meeting(5, '08:00', '09:00'),
            ]);
            $enlistment = (int) enlistmentIdFor($enrolled['enrollment_id'], $classId);

            foreach ([1 => 'PRESENT', 2 => 'LATE', 3 => 'ABSENT', 4 => 'EXCUSED', 5 => 'PRESENT'] as $day => $status) {
                AttendanceService::record($teacher, $classId, lastWeekday($day), [$enlistment => $status]);
            }

            $summary = ClassSchedule::attendanceSummary($classId);
            $t->assertEquals(5, count($summary['dates']));
            $t->assertEquals(array_values(array_unique($summary['dates'])), $summary['dates'], 'Each date once');
            $sorted = $summary['dates'];
            sort($sorted);
            $t->assertEquals($sorted, $summary['dates'], 'Oldest first');

            $row = $summary['students'][0];
            $t->assertEquals(['PRESENT' => 2, 'ABSENT' => 1, 'LATE' => 1, 'EXCUSED' => 1], $row['counts']);
            $t->assertEquals(75, $row['rate'], 'Present and late count as attended: 3 of 4 non-excused dates');
            $t->assertEquals('EXCUSED', $row['marks'][lastWeekday(4)]);
        } finally {
            deleteTestUsers();
            deleteTestCatalog();
        }
    },

    'a teacher opens reports only for their own classes: another teacher\'s class is a 404' => function (TestRunner $t): void {
        try {
            $enrolled = enrolledStudent(currentTermId());
            $owner = makeUser(User::ROLE_TEACHER);
            $classId = makeClass($enrolled['registrar'], $enrolled['section_id'], makeSubject(), $owner->id, [meeting(1, '08:00', '09:00')]);

            $request = makeRequest('GET', '/teacher/classes/' . $classId . '/reports/grades');
            $request->setRouteParams(['id' => (string) $classId]);
            $controller = new TeacherReports();

            actingAs(makeUser(User::ROLE_TEACHER));
            foreach (['classList', 'classListExport', 'attendance', 'attendanceExport', 'grades', 'gradesExport'] as $action) {
                $t->assertEquals(404, statusOf(static fn () => $controller->{$action}($request)), "{$action}: another teacher's class");
            }
            actingAs($enrolled['registrar']);
            $t->assertEquals(403, statusOf(static fn () => $controller->gradesExport($request)), 'Not a teacher at all');
            $t->assertEquals(0, (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'reports.exported' AND target_type = 'ClassSchedule' AND target_id = ?", [$classId]), 'A refused export writes nothing');
        } finally {
            actingAs(null);
            deleteTestUsers();
            deleteTestCatalog();
        }
    },
];
