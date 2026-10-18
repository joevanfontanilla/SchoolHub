<?php
/**
 * Curricula: the six school curricula, the importer, and curriculum-scoped subjects (docs/28).
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Models\Curriculum;
use App\Models\User;
use App\Services\CatalogService as Catalog;
use App\Services\CurriculumImporter as Importer;
use App\Services\EnrollmentConfirmationService as Confirmation;

/** A fixture program. */
/** Where a curriculum subject sits (a year level and a term are required): the first year, first semester. */
function curriculumTestSlot(): array
{
    return ['year_level_id' => (int) Database::scalar('SELECT id FROM year_levels ORDER BY sequence LIMIT 1'), 'term_sequence' => '1'];
}

function curriculumTestProgram(): int
{
    return Database::insert('programs', [
        'code' => testCode('P'), 'name' => 'Test Program', 'degree_level' => 'Bachelor', 'years_duration' => 4, 'is_active' => 1,
    ]);
}

/** A fixture curriculum of a program. */
function curriculumTestCurriculum(int $programId, string $schoolYear = '2024-2025', int $active = 1): int
{
    return Database::insert('curricula', [
        'program_id' => $programId, 'code' => testCode('C'), 'name' => 'Test Curriculum',
        'effective_school_year' => $schoolYear, 'is_active' => $active,
    ]);
}

/** A small, valid curriculum file's contents. */
function curriculumTestData(array $overrides = []): array
{
    return array_merge([
        'program' => 'ZZT-NONE',
        'code'    => testCode('I'),
        'name'    => 'Imported Test Curriculum',
        'effective_school_year' => '2024-2025',
        'terms'   => [
            [1, 1, 5, [
                ['GE001', 'Understanding Self', 3],
                ['PATH-FIT 1', 'Movement Enhancement', 2],
            ]],
            [1, 2, 5, [
                ['PATH-FIT 2', 'Exercise-based Fitness Activities', 2, ['PATH-FIT 1']],
                ['CORE 1', 'Lab Subject', 3, ['GE001'], ['lab_units' => 1]],
            ]],
            [4, 1, 6, [
                ['INTERN', 'Internship', 6, [], ['min_progress_percent' => 80]],
            ]],
        ],
    ], $overrides);
}

return [
    'the six school curricula are valid, and only BSCRIM differs from its printed totals' => function (TestRunner $t): void {
        $files = glob(dirname(__DIR__) . '/database/seeds/curricula/*.php') ?: [];
        $t->assertEquals(6, count($files), 'Six curriculum files');

        foreach ($files as $file) {
            $data = Importer::load($file);
            $check = Importer::check($data);
            $t->assertEquals([], $check['errors'], basename($file) . ' has no errors');

            if ($data['code'] === 'BSCRIM-2022') {
                $t->assertEquals(1, count($check['warnings']), 'BSCRIM: the one known total mismatch');
                $t->assertContains('3rd year, 1st semester', $check['warnings'][0]);
            } else {
                $t->assertEquals([], $check['warnings'], basename($file) . ' matches every printed total');
            }
        }
    },

    'the importer refuses unknown, same-term, and later-term prerequisites and duplicate codes' => function (TestRunner $t): void {
        $data = curriculumTestData();
        $data['terms'][0][3][] = ['BAD', 'Needs a missing subject', 3, ['NOPE 1']];
        $data['terms'][0][3][] = ['SAME', 'Needs a same-term subject', 3, ['GE001']];
        $data['terms'][0][3][] = ['LATER', 'Needs a later subject', 3, ['CORE 1']];
        $data['terms'][1][3][] = ['GE001', 'Duplicate code', 3];
        $data['terms'][1][3][] = ['core  2', 'Not normalized', 3];

        $errors = implode("\n", Importer::check($data)['errors']);
        $t->assertContains('NOPE 1 is not in this curriculum', $errors);
        $t->assertContains('BAD: prerequisite', $errors);
        $t->assertContains('SAME: prerequisite GE001 is taken in 1st year, 1st semester, which is not an earlier term', $errors);
        $t->assertContains('LATER: prerequisite CORE 1', $errors);
        $t->assertContains('GE001 is used twice', $errors);
        $t->assertContains('write the code "core  2" as "CORE 2"', $errors);
    },

    'the importer warns, without failing, when a term differs from its printed total' => function (TestRunner $t): void {
        $data = curriculumTestData();
        $data['terms'][0][2] = 7;
        $check = Importer::check($data);
        $t->assertEquals([], $check['errors']);
        $t->assertContains('add up to 5 units, but the document prints 7', implode("\n", $check['warnings']));
    },

    'an import creates subjects and prerequisites in one curriculum, and a re-run skips it' => function (TestRunner $t): void {
        $curriculumId = null;
        try {
            $programId = curriculumTestProgram();
            $data = curriculumTestData(['program' => Database::scalar('SELECT code FROM programs WHERE id = ?', [$programId])]);

            $result = Importer::import($data);
            $curriculumId = $result['curriculum_id'];
            $t->assertTrue($result['created']);
            $t->assertEquals(5, $result['subjects']);

            $subjects = array_column(Database::select('SELECT * FROM subjects WHERE curriculum_id = ?', [$curriculumId]), null, 'code');
            $t->assertEquals($programId, (int) $subjects['CORE 1']['program_id'], 'Subjects carry the curriculum\'s program');
            $t->assertEquals(1.0, (float) $subjects['CORE 1']['lab_units']);
            $t->assertEquals(80, (int) $subjects['INTERN']['min_progress_percent']);
            $t->assertEquals(2, (int) $subjects['CORE 1']['term_sequence']);
            $t->assertEquals(2, (int) Database::scalar(
                'SELECT COUNT(*) FROM subject_prerequisites sp JOIN subjects s ON s.id = sp.subject_id WHERE s.curriculum_id = ?',
                [$curriculumId]
            ));
            $t->assertEquals(16.0, Curriculum::totalUnits($curriculumId));
            $t->assertEquals(5.0, Curriculum::prescribedUnits($curriculumId, (int) $subjects['CORE 1']['year_level_id'], 2));

            $again = Importer::import($data);
            $t->assertFalse($again['created'], 'A second run changes nothing');
            $t->assertEquals(5, (int) Database::scalar('SELECT COUNT(*) FROM subjects WHERE curriculum_id = ?', [$curriculumId]));

            $t->assertThrows(static fn () => Importer::import(curriculumTestData(['program' => 'ZZTNOSUCH'])), 'does not exist');
        } finally {
            if ($curriculumId !== null) {
                Database::query("DELETE FROM audit_logs WHERE action = 'academic.curriculum_imported' AND target_id = ?", [$curriculumId]);
            }
            deleteTestUsers();
        }
    },

    'subject codes may contain spaces and are stored upper-case with single spaces' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $curriculumId = curriculumTestCurriculum(curriculumTestProgram());

            $id = Catalog::saveSubject($registrar, null, [...curriculumTestSlot(), 'code' => '  pub   ad 2 ', 'title' => 'Knowledge Management', 'units' => '3', 'curriculum_id' => $curriculumId, 'is_active' => '1']);
            $t->assertEquals('PUB AD 2', Database::scalar('SELECT code FROM subjects WHERE id = ?', [$id]));

            foreach (['#CORE', '-X1', 'A/B'] as $bad) {
                $errors = fieldErrors(static fn () => Catalog::saveSubject($registrar, null, [...curriculumTestSlot(), 'code' => $bad, 'title' => 'X', 'units' => '3', 'curriculum_id' => $curriculumId]));
                $t->assertTrue(isset($errors['code']), "{$bad} is refused");
            }
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the same code can mean different subjects in two curricula, but not twice in one' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $programId = curriculumTestProgram();
            $first = curriculumTestCurriculum($programId);
            $second = curriculumTestCurriculum($programId);

            Catalog::saveSubject($registrar, null, [...curriculumTestSlot(), 'code' => 'CORE 1', 'title' => 'Financial Accounting and Reporting', 'units' => '3', 'curriculum_id' => $first]);
            Catalog::saveSubject($registrar, null, [...curriculumTestSlot(), 'code' => 'CORE 1', 'title' => 'Basic Accounting', 'units' => '3', 'curriculum_id' => $second]);
            $t->assertEquals(2, (int) Database::scalar("SELECT COUNT(*) FROM subjects WHERE code = 'CORE 1' AND curriculum_id IN (?, ?)", [$first, $second]));

            $errors = fieldErrors(static fn () => Catalog::saveSubject($registrar, null, [...curriculumTestSlot(), 'code' => 'core 1', 'title' => 'Again', 'units' => '3', 'curriculum_id' => $first]));
            $t->assertContains('already used in', $errors['code'][0] ?? '');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a prerequisite must come from the same curriculum or be a general subject' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $programId = curriculumTestProgram();
            $mine = curriculumTestCurriculum($programId);
            $other = curriculumTestCurriculum($programId);

            $foreign = Catalog::saveSubject($registrar, null, [...curriculumTestSlot(), 'code' => 'GE001', 'title' => 'Understanding Self', 'units' => '3', 'curriculum_id' => $other]);
            $general = Catalog::saveSubject($registrar, null, ['code' => testCode('G'), 'title' => 'General', 'units' => '3', 'curriculum_id' => 'general']);
            $own = Catalog::saveSubject($registrar, null, [...curriculumTestSlot(), 'code' => 'GE001', 'title' => 'Understanding Self', 'units' => '3', 'curriculum_id' => $mine]);

            $errors = fieldErrors(static fn () => Catalog::saveSubject($registrar, null, [...curriculumTestSlot(), 'code' => 'CORE 2', 'title' => 'X', 'units' => '3', 'curriculum_id' => $mine, 'prerequisites' => [$foreign]]));
            $t->assertContains('belongs to a different curriculum', $errors['prerequisites'][0] ?? '');

            $id = Catalog::saveSubject($registrar, null, [...curriculumTestSlot(), 'code' => 'CORE 2', 'title' => 'X', 'units' => '3', 'curriculum_id' => $mine, 'prerequisites' => [$own, $general]]);
            $t->assertEquals(2, (int) Database::scalar('SELECT COUNT(*) FROM subject_prerequisites WHERE subject_id = ?', [$id]));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a subject stays in its curriculum when edited, and lab units and progress are bounded' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $programId = curriculumTestProgram();
            $curriculumId = curriculumTestCurriculum($programId);
            $elsewhere = curriculumTestCurriculum($programId);

            $input = [...curriculumTestSlot(), 'code' => 'BPLAN1', 'title' => 'Business Plan Implementation 1', 'units' => '5', 'lab_units' => '3', 'curriculum_id' => $curriculumId];
            $id = Catalog::saveSubject($registrar, null, $input);

            Catalog::saveSubject($registrar, $id, array_merge($input, ['curriculum_id' => $elsewhere, 'title' => 'Renamed']));
            $row = Database::selectOne('SELECT * FROM subjects WHERE id = ?', [$id]);
            $t->assertEquals($curriculumId, (int) $row['curriculum_id'], 'The curriculum did not change');
            $t->assertEquals('Renamed', $row['title']);

            $t->assertTrue(isset(fieldErrors(static fn () => Catalog::saveSubject($registrar, $id, array_merge($input, ['lab_units' => '6'])))['lab_units']), 'Lab units above units are refused');
            foreach (['-5', '101'] as $bad) {
                $t->assertTrue(isset(fieldErrors(static fn () => Catalog::saveSubject($registrar, $id, array_merge($input, ['min_progress_percent' => $bad])))['min_progress_percent']), "{$bad}% is refused");
            }
            Catalog::saveSubject($registrar, $id, array_merge($input, ['min_progress_percent' => '80']));
            $t->assertEquals(80, (int) Database::scalar('SELECT min_progress_percent FROM subjects WHERE id = ?', [$id]));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a curriculum needs a unique code and a real school year, and keeps its program once used' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $programId = curriculumTestProgram();
            $input = ['program_id' => $programId, 'code' => testCode('C'), 'name' => 'Test', 'effective_school_year' => '2024-2025', 'is_active' => '1'];

            foreach (['2024', '2024-2026', '24-25'] as $bad) {
                $t->assertTrue(isset(fieldErrors(static fn () => Catalog::saveCurriculum($registrar, null, array_merge($input, ['effective_school_year' => $bad])))['effective_school_year']), "{$bad} is refused");
            }

            $id = Catalog::saveCurriculum($registrar, null, $input);
            $t->assertTrue(isset(fieldErrors(static fn () => Catalog::saveCurriculum($registrar, null, $input))['code']), 'A duplicate code is refused');

            Catalog::saveSubject($registrar, null, [...curriculumTestSlot(), 'code' => 'GE001', 'title' => 'Understanding Self', 'units' => '3', 'curriculum_id' => $id]);
            $moved = array_merge($input, ['program_id' => curriculumTestProgram()]);
            $t->assertTrue(isset(fieldErrors(static fn () => Catalog::saveCurriculum($registrar, $id, $moved))['program_id']), 'The program is fixed once it has subjects');

            $t->assertEquals(403, statusOf(static fn () => Catalog::saveCurriculum(makeUser(User::ROLE_TEACHER), null, $input)));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'confirmation places a new student under the program\'s latest active curriculum' => function (TestRunner $t): void {
        try {
            [$student, $registrar, $id] = approvedApplication();
            $programId = curriculumTestProgram();
            curriculumTestCurriculum($programId, '2022-2023');
            $latest = curriculumTestCurriculum($programId, '2024-2025');
            curriculumTestCurriculum($programId, '2026-2027', 0); // newer, but inactive

            // Fixture setup: the application is for the fixture program.
            Database::update('enrollment_applications', ['program_id' => $programId], ['id' => $id]);
            $sectionId = makeSectionFor($id);

            withSetting('enrollment_payment_policy', 'NONE', static fn () => Confirmation::confirm($registrar, $id, $sectionId));

            $t->assertEquals($latest, (int) Database::scalar('SELECT curriculum_id FROM students WHERE user_id = ?', [$student->id]));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a student already under a curriculum of the same program keeps it' => function (TestRunner $t): void {
        try {
            [$student, $registrar, $id] = approvedApplication();
            $programId = curriculumTestProgram();
            $older = curriculumTestCurriculum($programId, '2022-2023');
            curriculumTestCurriculum($programId, '2024-2025');

            Database::update('enrollment_applications', ['program_id' => $programId], ['id' => $id]);
            Database::update('students', ['program_id' => $programId, 'curriculum_id' => $older], ['user_id' => $student->id]);
            $sectionId = makeSectionFor($id);

            withSetting('enrollment_payment_policy', 'NONE', static fn () => Confirmation::confirm($registrar, $id, $sectionId));

            $t->assertEquals($older, (int) Database::scalar('SELECT curriculum_id FROM students WHERE user_id = ?', [$student->id]));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },
];
