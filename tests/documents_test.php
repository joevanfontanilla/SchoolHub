<?php
/**
 * My Documents, the registrar's view of a student's documents, and the portal navigation
 * shared by the sidebar and the quick search (docs/10, docs/15, docs/21).
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\Router;
use App\Models\StudentDocuments;
use App\Models\User;
use App\Services\FinanceService;

return [
    'a student sees every document of their own, and the registrar sees them without drafts or payment proofs' => function (TestRunner $t): void {
        try {
            [$student, $applicationId] = submittedApplication();
            $studentId = (int) Database::scalar('SELECT id FROM students WHERE user_id = ?', [$student->id]);
            $draftStudent = makeStudent();
            $draftId = App\Services\EnrollmentApplicationService::createDraft($draftStudent, applicationInput());
            uploadAllRequired($draftStudent, $draftId);
            $draftStudentId = (int) Database::scalar('SELECT id FROM students WHERE user_id = ?', [$draftStudent->id]);

            $own = StudentDocuments::forStudent($studentId, true);
            $required = (int) Database::scalar('SELECT COUNT(*) FROM enrollment_requirements WHERE application_id = ? AND is_required = 1', [$applicationId]);
            $t->assertEquals(1, count($own['applications']), 'One application with files');
            $t->assertEquals($applicationId, $own['applications'][0]['id']);
            $t->assertTrue(count($own['applications'][0]['files']) >= $required, 'Every uploaded requirement is listed');
            $t->assertEquals([], array_filter($own['applications'][0]['files'], static fn ($f) => (int) $f['file_id'] <= 0), 'Each has its current file');
            $t->assertEquals(count($own['applications'][0]['files']), $own['count'], 'Nothing else issued yet');

            $t->assertEquals(1, count(StudentDocuments::forStudent($draftStudentId, true)['applications']), 'A draft\'s uploads show to the student');
            $t->assertEquals([], StudentDocuments::forStudent($draftStudentId, false)['applications'], 'The registrar never sees a draft');
            $t->assertEquals([], StudentDocuments::forStudent($draftStudentId + 100000, true)['applications'], 'Another id has nothing');
        } finally {
            deleteTestUsers();
        }
    },

    'receipts, statements, schedules, and proofs appear once issued, and proofs only for the student' => function (TestRunner $t): void {
        try {
            $enrolled = enrolledStudent();
            $cashier = makeUser(User::ROLE_CASHIER);
            $assessment = App\Models\Finance::assessmentForApplication($enrolled['application_id']);
            $payment = FinanceService::recordPayment($cashier, (int) $assessment['id'], ['amount' => '100', 'payment_method' => 'Cash']);
            FinanceService::submitPayment($enrolled['student'], (int) $assessment['id'], [
                'amount' => '50', 'method' => 'GCash', 'reference_number' => 'GC-DOCS', 'paid_on' => date('Y-m-d'),
            ], App\Core\UploadedFile::forTesting(fixtureFile('png'), 'proof.png'));

            $own = StudentDocuments::forStudent($enrolled['student_id'], true);
            $t->assertEquals([(int) $payment['payment_id']], array_map('intval', array_column($own['receipts'], 'id')), 'The official receipt');
            $t->assertEquals([(int) $assessment['id']], array_map('intval', array_column($own['statements'], 'id')), 'The statement of account');
            $t->assertEquals(1, count($own['schedules']), 'The enrolled term\'s schedule');
            $t->assertEquals(['GC-DOCS'], array_column($own['proofs'], 'reference_number'), 'The proof sent');

            $registrarView = StudentDocuments::forStudent($enrolled['student_id'], false);
            $t->assertEquals(1, count($registrarView['receipts']));
            $t->assertEquals([], $registrarView['proofs'], 'The registrar may not open payment proofs');
            $t->assertEquals([], $registrarView['schedules'], 'Schedules print only for the student');
        } finally {
            deleteTestUsers();
            deleteTestCatalog();
        }
    },

    'My Documents is a student page that takes no record id' => function (TestRunner $t): void {
        $router = new Router();
        require BASE_PATH . '/routes/web.php';
        $route = array_values(array_filter($router->routes(), static fn ($r) => $r['pattern'] === '/student/documents'))[0] ?? null;
        $t->assertNotNull($route);
        $t->assertEquals('GET', $route['method']);
        $t->assertTrue(in_array('role:' . User::ROLE_STUDENT, $route['middleware'], true));
    },

    'the sidebar and the quick search list only real pages of each role, each once, found by their words' => function (TestRunner $t): void {
        $router = new Router();
        require BASE_PATH . '/routes/web.php';
        $getPaths = array_column(array_filter($router->routes(), static fn ($r) => $r['method'] === 'GET'), 'pattern');

        foreach ([User::ROLE_STUDENT, User::ROLE_TEACHER, User::ROLE_REGISTRAR, User::ROLE_CASHIER, User::ROLE_SUPER_ADMIN] as $role) {
            $pages = portal_destinations($role);
            $paths = array_column($pages, 'path');
            $t->assertTrue(count($pages) >= 8, "{$role} has pages");
            $t->assertEquals(count($paths), count(array_unique($paths)), "{$role}: each page once");
            $t->assertEquals([], array_values(array_diff($paths, $getPaths)), "{$role}: every page is a route");
            foreach (portal_nav($role) as $entry) {
                if (is_array($entry)) {
                    $t->assertTrue($entry[3], "{$role}: {$entry[1]} is available, not a placeholder");
                }
            }
        }

        $find = static function (string $role, string $word): array {
            return array_column(array_filter(portal_destinations($role), static fn ($p) => str_contains($p['terms'], $word)), 'path');
        };
        $t->assertTrue(in_array('/student/account', $find(User::ROLE_STUDENT, 'receipt'), true), 'A student finds receipts under Payments');
        $t->assertTrue(in_array('/student/documents', $find(User::ROLE_STUDENT, 'receipt'), true), 'and under My Documents');
        $t->assertFalse(in_array('/registrar/applications', array_column(portal_destinations(User::ROLE_STUDENT), 'path'), true), 'A student is never offered a registrar page');
        $t->assertEquals([], portal_destinations(null), 'A visitor has no portal pages');
    },
];
