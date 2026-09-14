<?php
/**
 * Programs, subjects, and sections (docs/13).
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Models\User;
use App\Services\CatalogService as Catalog;

function subjectInput(array $overrides = []): array
{
    return array_merge(['code' => testCode('S'), 'title' => 'Test Subject', 'units' => '3', 'is_active' => '1'], $overrides);
}

return [
    'a program is created and a duplicate code is refused' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $code = testCode('P');
            $input = ['code' => strtolower($code), 'name' => 'Test Program', 'years_duration' => '4', 'is_active' => '1'];

            $id = Catalog::saveProgram($registrar, null, $input);
            $t->assertEquals($code, Database::scalar('SELECT code FROM programs WHERE id = ?', [$id]), 'Codes are stored upper-case');

            $errors = fieldErrors(static fn () => Catalog::saveProgram($registrar, null, $input));
            $t->assertTrue(isset($errors['code']), 'The duplicate code is refused');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'subject units must be positive, at most 10, with one decimal place' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            foreach (['0', '-3', '11', '2.25'] as $bad) {
                $errors = fieldErrors(static fn () => Catalog::saveSubject($registrar, null, subjectInput(['units' => $bad])));
                $t->assertTrue(isset($errors['units']), "{$bad} units must be refused");
            }
            // 2.3 is a floating-point trap (2.3 * 10 = 22.999...); it must still be accepted.
            foreach (['3', '1.5', '2.3'] as $good) {
                $id = Catalog::saveSubject($registrar, null, subjectInput(['units' => $good]));
                $t->assertEquals((float) $good, (float) Database::scalar('SELECT units FROM subjects WHERE id = ?', [$id]));
            }
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a subject cannot be its own prerequisite' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $id = Catalog::saveSubject($registrar, null, subjectInput());

            $errors = fieldErrors(static fn () => Catalog::saveSubject($registrar, $id, subjectInput([
                'code' => Database::scalar('SELECT code FROM subjects WHERE id = ?', [$id]),
                'prerequisites' => [$id],
            ])));
            $t->assertTrue(isset($errors['prerequisites']));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a prerequisite loop is refused, directly or through another subject' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $a = Catalog::saveSubject($registrar, null, subjectInput(['title' => 'A']));
            $b = Catalog::saveSubject($registrar, null, subjectInput(['title' => 'B', 'prerequisites' => [$a]]));
            $c = Catalog::saveSubject($registrar, null, subjectInput(['title' => 'C', 'prerequisites' => [$b]]));

            $codeOf = static fn (int $id) => (string) Database::scalar('SELECT code FROM subjects WHERE id = ?', [$id]);

            // A -> B would be a direct loop (B already requires A).
            $errors = fieldErrors(static fn () => Catalog::saveSubject($registrar, $a, subjectInput(['code' => $codeOf($a), 'prerequisites' => [$b]])));
            $t->assertTrue(isset($errors['prerequisites']), 'A requiring B must be refused');

            // A -> C would loop through B.
            $errors = fieldErrors(static fn () => Catalog::saveSubject($registrar, $a, subjectInput(['code' => $codeOf($a), 'prerequisites' => [$c]])));
            $t->assertTrue(isset($errors['prerequisites']), 'A requiring C must be refused (C -> B -> A)');

            $t->assertEquals(0, (int) Database::scalar('SELECT COUNT(*) FROM subject_prerequisites WHERE subject_id = ?', [$a]), 'A was left unchanged');

            // A chain that is not a loop is fine: C also requiring A.
            Catalog::saveSubject($registrar, $c, subjectInput(['code' => $codeOf($c), 'prerequisites' => [$b, $a]]));
            $t->assertEquals(2, (int) Database::scalar('SELECT COUNT(*) FROM subject_prerequisites WHERE subject_id = ?', [$c]));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a section code is unique within a term' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $input = [
                'code' => testCode('X'), 'program_id' => (int) Database::scalar('SELECT id FROM programs LIMIT 1'),
                'year_level_id' => (int) Database::scalar('SELECT id FROM year_levels LIMIT 1'),
                'term_id' => openTermId(), 'capacity' => '40', 'is_active' => '1',
            ];
            Catalog::saveSection($registrar, null, $input);

            $errors = fieldErrors(static fn () => Catalog::saveSection($registrar, null, $input));
            $t->assertTrue(isset($errors['code']), 'The same code in the same term is refused');

            $input['term_id'] = closedTermId();
            $t->assertTrue(Catalog::saveSection($registrar, null, $input) > 0, 'The same code in another term is allowed');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'section capacity cannot drop below enrollment, and an enrolled section cannot move' => function (TestRunner $t): void {
        try {
            [, $registrar, $id] = approvedApplication();
            $sectionId = makeSectionFor($id, 10);
            withSetting('enrollment_payment_policy', 'NONE', static fn () => App\Services\EnrollmentConfirmationService::confirm($registrar, $id, $sectionId));

            $section = Database::selectOne('SELECT * FROM sections WHERE id = ?', [$sectionId]);
            $input = [
                'code' => $section['code'], 'program_id' => $section['program_id'], 'year_level_id' => $section['year_level_id'],
                'term_id' => $section['term_id'], 'capacity' => '0', 'is_active' => '1',
            ];

            $t->assertTrue(isset(fieldErrors(static fn () => Catalog::saveSection($registrar, $sectionId, $input))['capacity']), 'Zero is refused');

            $moved = $input;
            $moved['capacity'] = '10';
            $moved['term_id'] = closedTermId();
            $t->assertTrue(isset(fieldErrors(static fn () => Catalog::saveSection($registrar, $sectionId, $moved))['program_id']), 'An enrolled section cannot change term');

            $input['capacity'] = '1';
            Catalog::saveSection($registrar, $sectionId, $input);
            $t->assertEquals(1, (int) Database::scalar('SELECT capacity FROM sections WHERE id = ?', [$sectionId]), 'Exactly the enrolled count is allowed');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a section adviser must be an active teacher' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $input = [
                'code' => testCode('A'), 'program_id' => (int) Database::scalar('SELECT id FROM programs LIMIT 1'),
                'year_level_id' => (int) Database::scalar('SELECT id FROM year_levels LIMIT 1'),
                'term_id' => openTermId(), 'capacity' => '30', 'adviser_user_id' => (string) makeUser(User::ROLE_CASHIER)->id,
            ];
            $t->assertTrue(isset(fieldErrors(static fn () => Catalog::saveSection($registrar, null, $input))['adviser_user_id']));

            $input['adviser_user_id'] = (string) makeUser(User::ROLE_TEACHER)->id;
            $t->assertTrue(Catalog::saveSection($registrar, null, $input) > 0);
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'only the registrar may change the catalog, and changes are audited' => function (TestRunner $t): void {
        try {
            $status = null;
            try {
                Catalog::saveProgram(makeUser(User::ROLE_TEACHER), null, ['code' => testCode('P'), 'name' => 'X', 'years_duration' => '4']);
            } catch (HttpException $e) {
                $status = $e->status();
            }
            $t->assertEquals(403, $status);

            $registrar = makeUser(User::ROLE_REGISTRAR);
            $id = Catalog::saveSubject($registrar, null, subjectInput());
            $t->assertEquals(1, (int) Database::scalar(
                "SELECT COUNT(*) FROM audit_logs WHERE action = 'academic.subject_created' AND target_id = ? AND user_id = ?",
                [$id, $registrar->id]
            ));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },
];
