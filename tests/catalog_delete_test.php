<?php
/**
 * Deleting a subject or a curriculum (docs/28): only when nothing refers to it, with a reason and the code typed, audited, and
 * the registrar's alone.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\Router;
use App\Models\User;
use App\Services\CatalogService as Catalog;

function delCatCurriculum(): int
{
    $programId = Database::insert('programs', ['code' => testCode('P'), 'name' => 'Delete Program', 'degree_level' => 'Bachelor', 'years_duration' => 4, 'is_active' => 1]);

    return Database::insert('curricula', ['program_id' => $programId, 'code' => testCode('C'), 'name' => 'Delete Curriculum', 'effective_school_year' => '2024-2025', 'is_active' => 1]);
}

function delCatSubject(int $curriculumId, string $code, array $prerequisites = []): int
{
    return makeSubject(3, $prerequisites, ['code' => $code, 'curriculum_id' => $curriculumId]);
}

return [
    'an unused subject is deleted with its prerequisite links, after a reason and its typed code, and is audited' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $curriculumId = delCatCurriculum();
            $base = delCatSubject($curriculumId, 'ZZ BASE 1');
            $subject = delCatSubject($curriculumId, 'ZZ DEL 1', [$base]);

            $t->assertEquals([], Catalog::subjectDeleteBlockers($subject));
            $errors = fieldErrors(static fn () => Catalog::deleteSubject($registrar, $subject, 'no', 'ZZ DEL 1'));
            $t->assertTrue(isset($errors['reason']), 'A reason is required');
            $errors = fieldErrors(static fn () => Catalog::deleteSubject($registrar, $subject, 'Added by mistake', 'wrong'));
            $t->assertTrue(isset($errors['confirm_code']), 'The code must be typed');
            $t->assertEquals(1, (int) Database::scalar('SELECT COUNT(*) FROM subjects WHERE id = ?', [$subject]), 'A refused attempt deletes nothing');

            Catalog::deleteSubject($registrar, $subject, 'Added by mistake', 'ZZ DEL 1');
            $t->assertEquals(0, (int) Database::scalar('SELECT COUNT(*) FROM subjects WHERE id = ?', [$subject]), 'Gone');
            $t->assertEquals(0, (int) Database::scalar('SELECT COUNT(*) FROM subject_prerequisites WHERE subject_id = ?', [$subject]), 'Its prerequisite links went too');
            $t->assertEquals(1, (int) Database::scalar('SELECT COUNT(*) FROM subjects WHERE id = ?', [$base]), 'The subject it required stays');

            $audit = Database::selectOne("SELECT old_values, remarks FROM audit_logs WHERE action = 'academic.subject_deleted' AND target_id = ?", [$subject]);
            $t->assertNotNull($audit, 'Audited');
            $t->assertContains('ZZ DEL 1', (string) $audit['old_values'], 'With the code, so the record says what went');
            $t->assertEquals('Added by mistake', $audit['remarks']);
        } finally {
            deleteTestUsers();
        }
    },

    'a subject in use is refused with the reasons: a class, a prerequisite elsewhere' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $curriculumId = delCatCurriculum();
            $needed = delCatSubject($curriculumId, 'ZZ NEED 1');
            $needing = delCatSubject($curriculumId, 'ZZ NEED 2', [$needed]);

            $blockers = Catalog::subjectDeleteBlockers($needed);
            $t->assertContains('prerequisite of ZZ NEED 2', implode(' ', $blockers));
            $t->assertThrows(static fn () => Catalog::deleteSubject($registrar, $needed, 'Not needed any more', 'ZZ NEED 1'), 'prerequisite of ZZ NEED 2');
            $t->assertEquals(1, (int) Database::scalar('SELECT COUNT(*) FROM subjects WHERE id = ?', [$needed]), 'Still there');

            $enrolled = enrolledStudent();
            $withClass = delCatSubject($curriculumId, 'ZZ CLASS 1');
            makeClass($enrolled['registrar'], $enrolled['section_id'], $withClass, null, [meeting(2, '10:00', '11:00')]);
            $t->assertContains('scheduled for it', implode(' ', Catalog::subjectDeleteBlockers($withClass)));
            $t->assertThrows(static fn () => Catalog::deleteSubject($registrar, $withClass, 'Not needed any more', 'ZZ CLASS 1'), 'scheduled');

            // The dependent can go (it is nobody's prerequisite), and then the first is free too.
            Catalog::deleteSubject($registrar, $needing, 'Not needed any more', 'ZZ NEED 2');
            $t->assertEquals([], Catalog::subjectDeleteBlockers($needed), 'Free once nothing requires it');
        } finally {
            deleteTestUsers();
        }
    },

    'an unused curriculum is deleted together with its subjects; a followed one, or one with a subject in use, is refused whole' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);

            // Free: two subjects, one requiring the other, count as nothing blocking.
            $free = delCatCurriculum();
            $a = delCatSubject($free, 'ZZ FREE 1');
            $b = delCatSubject($free, 'ZZ FREE 2', [$a]);
            $code = (string) Database::scalar('SELECT code FROM curricula WHERE id = ?', [$free]);
            $t->assertEquals([], Catalog::curriculumDeleteBlockers($free));
            $errors = fieldErrors(static fn () => Catalog::deleteCurriculum($registrar, $free, 'Created by mistake', 'wrong'));
            $t->assertTrue(isset($errors['confirm_code']));
            $t->assertEquals(2, Catalog::deleteCurriculum($registrar, $free, 'Created by mistake', $code), 'Both subjects went with it');
            $t->assertEquals(0, (int) Database::scalar('SELECT COUNT(*) FROM curricula WHERE id = ?', [$free]));
            $t->assertEquals(0, (int) Database::scalar('SELECT COUNT(*) FROM subjects WHERE id IN (?, ?)', [$a, $b]));
            $audit = Database::selectOne("SELECT old_values FROM audit_logs WHERE action = 'academic.curriculum_deleted' AND target_id = ?", [$free]);
            $t->assertContains('"subjects":2', (string) $audit['old_values'], 'The audit entry says how many subjects went');

            // A student follows it: refused, nothing deleted.
            $followed = delCatCurriculum();
            $kept = delCatSubject($followed, 'ZZ KEPT 1');
            $student = makeStudent();
            Database::update('students', ['curriculum_id' => $followed], ['user_id' => $student->id]);
            $t->assertContains('follows this curriculum', implode(' ', Catalog::curriculumDeleteBlockers($followed)));
            $followedCode = (string) Database::scalar('SELECT code FROM curricula WHERE id = ?', [$followed]);
            $t->assertThrows(static fn () => Catalog::deleteCurriculum($registrar, $followed, 'Created by mistake', $followedCode), 'follows this curriculum');
            Database::update('students', ['curriculum_id' => null], ['user_id' => $student->id]);

            // One of its subjects has a class: refused as a whole.
            $enrolled = enrolledStudent();
            makeClass($enrolled['registrar'], $enrolled['section_id'], $kept, null, [meeting(4, '10:00', '11:00')]);
            $t->assertContains('ZZ KEPT 1', implode(' ', Catalog::curriculumDeleteBlockers($followed)));
            $t->assertThrows(static fn () => Catalog::deleteCurriculum($registrar, $followed, 'Created by mistake', $followedCode), 'in use');
            $t->assertEquals(1, (int) Database::scalar('SELECT COUNT(*) FROM curricula WHERE id = ?', [$followed]), 'Nothing was deleted');
            $t->assertEquals(1, (int) Database::scalar('SELECT COUNT(*) FROM subjects WHERE id = ?', [$kept]));
        } finally {
            deleteTestUsers();
        }
    },

    'only the registrar can delete, and a missing subject or curriculum is a 404' => function (TestRunner $t): void {
        try {
            $curriculumId = delCatCurriculum();
            $subject = delCatSubject($curriculumId, 'ZZ ROLE 1');
            $code = (string) Database::scalar('SELECT code FROM curricula WHERE id = ?', [$curriculumId]);
            foreach ([User::ROLE_CASHIER, User::ROLE_TEACHER, User::ROLE_STUDENT, User::ROLE_SUPER_ADMIN] as $role) {
                $other = makeUser($role);
                $t->assertEquals(403, statusOf(static fn () => Catalog::deleteSubject($other, $subject, 'Not my job to do', 'ZZ ROLE 1')), "{$role} cannot delete a subject");
                $t->assertEquals(403, statusOf(static fn () => Catalog::deleteCurriculum($other, $curriculumId, 'Not my job to do', $code)), "{$role} cannot delete a curriculum");
            }
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $t->assertEquals(404, statusOf(static fn () => Catalog::deleteSubject($registrar, PHP_INT_MAX, 'Nobody is there', 'X')));
            $t->assertEquals(404, statusOf(static fn () => Catalog::deleteCurriculum($registrar, PHP_INT_MAX, 'Nobody is there', 'X')));
            $t->assertEquals(1, (int) Database::scalar('SELECT COUNT(*) FROM subjects WHERE id = ?', [$subject]), 'Untouched');
        } finally {
            deleteTestUsers();
        }
    },

    'the delete routes are the registrar\'s catalog permission, and the forms ask for confirmation' => function (TestRunner $t): void {
        $router = new Router();
        require BASE_PATH . '/routes/web.php';
        $found = 0;
        foreach ($router->routes() as $route) {
            if (in_array($route['pattern'], ['/registrar/subjects/{id}/delete', '/registrar/curricula/{id}/delete'], true)) {
                $found++;
                $t->assertEquals('POST', $route['method']);
                $t->assertTrue(in_array('role:' . User::ROLE_REGISTRAR, $route['middleware'], true) && in_array('can:academic.manage', $route['middleware'], true), $route['pattern'] . ' is the registrar\'s');
            }
        }
        $t->assertEquals(2, $found);

        foreach (['subject-form' => '/delete\'\)\) \?>"[^>]*data-confirm=', 'curriculum' => '/delete\'\)\) \?>"[^>]*data-confirm='] as $view => $pattern) {
            $source = (string) file_get_contents(BASE_PATH . "/app/Views/registrar/catalog/{$view}.php");
            $t->assertTrue(preg_match('#' . $pattern . '#s', $source) === 1, "{$view} asks for confirmation");
        }
    },
];
