<?php
/**
 * Adding a subject puts it straight into the curriculum it was made for (docs/28): the curriculum is chosen on purpose, a
 * curriculum subject has a year level and a term, and it shows in the right block with the totals already updated.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Models\Curriculum;
use App\Models\User;
use App\Services\CatalogService as Catalog;

function placementCurriculum(): int
{
    $programId = Database::insert('programs', ['code' => testCode('P'), 'name' => 'Placement Program', 'degree_level' => 'Bachelor', 'years_duration' => 4, 'is_active' => 1]);

    return Database::insert('curricula', ['program_id' => $programId, 'code' => testCode('C'), 'name' => 'Placement Curriculum', 'effective_school_year' => '2024-2025', 'is_active' => 1]);
}

/** @return array<int,array{id:int,sequence:int}> the second year level, so the slot is not simply "the first" */
function placementYearLevel(int $position = 1): array
{
    return Database::select('SELECT id, sequence FROM year_levels ORDER BY sequence')[$position];
}

return [
    'the curriculum has to be chosen on purpose: nothing chosen is an error, and General is its own choice' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $input = ['code' => testCode('S'), 'title' => 'Placement Subject', 'units' => '3'];

            $errors = fieldErrors(static fn () => Catalog::saveSubject($registrar, null, $input + ['curriculum_id' => '']));
            $t->assertTrue(isset($errors['curriculum_id']), 'No curriculum chosen is refused');
            $t->assertEquals(0, (int) Database::scalar('SELECT COUNT(*) FROM subjects WHERE code = ?', [$input['code']]), 'Nothing was created');

            $errors = fieldErrors(static fn () => Catalog::saveSubject($registrar, null, $input));
            $t->assertTrue(isset($errors['curriculum_id']), 'Leaving the field out is refused too');

            $id = Catalog::saveSubject($registrar, null, $input + ['curriculum_id' => 'general']);
            $t->assertNull(Database::scalar('SELECT curriculum_id FROM subjects WHERE id = ?', [$id]), 'General is a deliberate choice');
            $t->assertNull(Database::scalar('SELECT year_level_id FROM subjects WHERE id = ?', [$id]), 'A general subject needs no year level');
        } finally {
            deleteTestUsers();
        }
    },

    'a curriculum subject needs a year level and a term, and then shows in that block at once with the totals updated' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $curriculumId = placementCurriculum();
            $second = placementYearLevel(1);
            $before = Curriculum::totalUnits($curriculumId);

            $input = ['code' => 'ZZ 101', 'title' => 'Placement Subject', 'units' => '3', 'is_active' => '1', 'curriculum_id' => (string) $curriculumId];
            $errors = fieldErrors(static fn () => Catalog::saveSubject($registrar, null, $input));
            $t->assertTrue(isset($errors['year_level_id']) && isset($errors['term_sequence']), 'Year level and term are required');
            $t->assertEquals(0, count(Curriculum::grid($curriculumId)), 'Nothing is added while the form is incomplete');

            $id = Catalog::saveSubject($registrar, null, $input + ['year_level_id' => $second['id'], 'term_sequence' => '2']);

            $blocks = Curriculum::grid($curriculumId);
            $t->assertEquals(1, count($blocks), 'One block');
            $t->assertEquals($second['sequence'], $blocks[0]['year'], 'In the chosen year');
            $t->assertEquals(2, $blocks[0]['term'], 'In the chosen term, not "Any term"');
            $t->assertEquals($id, (int) $blocks[0]['subjects'][0]['id']);
            $t->assertEquals($before + 3.0, Curriculum::totalUnits($curriculumId), 'The curriculum total is already updated');
            $t->assertEquals(3.0, (float) $blocks[0]['units'], 'And so is that term\'s total');
        } finally {
            deleteTestUsers();
        }
    },

    'an older subject without a year and term can still be edited, but one that has them cannot lose them' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $curriculumId = placementCurriculum();
            $first = placementYearLevel(0);

            // An older, unscheduled subject, as the early curricula had.
            $old = makeSubject(3, [], ['curriculum_id' => $curriculumId, 'code' => 'ZZ OLD 1']);
            $input = ['code' => 'ZZ OLD 1', 'title' => 'Renamed', 'units' => '3', 'is_active' => '1'];
            Catalog::saveSubject($registrar, $old, $input);
            $t->assertEquals('Renamed', Database::scalar('SELECT title FROM subjects WHERE id = ?', [$old]), 'Still editable without a slot');

            $placed = Catalog::saveSubject($registrar, null, ['code' => 'ZZ NEW 1', 'title' => 'Placed', 'units' => '3', 'curriculum_id' => (string) $curriculumId, 'year_level_id' => $first['id'], 'term_sequence' => '1']);
            $errors = fieldErrors(static fn () => Catalog::saveSubject($registrar, $placed, ['code' => 'ZZ NEW 1', 'title' => 'Placed', 'units' => '3', 'year_level_id' => '', 'term_sequence' => '']));
            $t->assertTrue(isset($errors['year_level_id']) && isset($errors['term_sequence']), 'Clearing the slot is refused');

            Catalog::saveSubject($registrar, $old, $input + ['year_level_id' => $first['id'], 'term_sequence' => '3']);
            $t->assertEquals(3, (int) Database::scalar('SELECT term_sequence FROM subjects WHERE id = ?', [$old]), 'An older subject can be given its slot');
        } finally {
            deleteTestUsers();
        }
    },

    'the New subject form has the curriculum inside it, with General last, and the prerequisites follow the choice' => function (TestRunner $t): void {
        $view = (string) file_get_contents(BASE_PATH . '/app/Views/registrar/catalog/subject-form.php');
        $t->assertFalse(strpos($view, 'method="GET"') !== false, 'No separate form that reloads the page and loses what was typed');
        $t->assertContains('name="curriculum_id" required data-curriculum-filter', $view, 'A required field of the form itself');
        $t->assertContains('Choose a curriculum…', $view, 'It starts on a placeholder, not on General');
        $t->assertTrue(strpos($view, 'Choose a curriculum…') < strpos($view, 'General (no curriculum)'), 'General comes after the curricula');
        $t->assertContains('data-curriculum-of=', $view, 'Each prerequisite knows its curriculum');

        $js = (string) file_get_contents(BASE_PATH . '/public/assets/app.js');
        $t->assertContains('data-curriculum-filter', $js, 'The script narrows the prerequisite list');
    },
];
