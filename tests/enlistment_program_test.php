<?php
/**
 * Which classes a student can be given (docs/13): their own curriculum's subjects and general ones, never another program's.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Models\ClassSchedule;
use App\Models\User;
use App\Services\EnlistmentService;

/**
 * An enrolled student in program A with that program's fixture curriculum, plus classes of: a subject of their own curriculum, another program's
 * curriculum, and a general subject.
 *
 * @return array<string,mixed>
 */
function programFixture(): array
{
    $e = enrolledStudent();
    $programA = (int) Database::scalar('SELECT program_id FROM students WHERE id = ?', [$e['student_id']]);
    $curriculumA = Database::insert('curricula', ['program_id' => $programA, 'code' => testCode('CA'), 'name' => 'Own', 'effective_school_year' => '2024-2025', 'is_active' => 1]);
    $programB = Database::insert('programs', ['code' => testCode('PB'), 'name' => 'Other Program', 'degree_level' => 'Bachelor', 'years_duration' => 4, 'is_active' => 1]);
    $curriculumB = Database::insert('curricula', ['program_id' => $programB, 'code' => testCode('CB'), 'name' => 'Other', 'effective_school_year' => '2024-2025', 'is_active' => 1]);
    Database::update('students', ['curriculum_id' => $curriculumA], ['id' => $e['student_id']]);

    $own = makeSubject(3, [], ['code' => testCode('OWN'), 'curriculum_id' => $curriculumA, 'program_id' => $programA]);
    $foreign = makeSubject(3, [], ['code' => testCode('FOR'), 'curriculum_id' => $curriculumB, 'program_id' => $programB]);
    $general = makeSubject(3, [], ['code' => testCode('GEN')]);
    // Classes in the student's own section are enlisted for them at once, so the offered ones are in a sibling section of the same program.
    $siblingA = siblingSection($e['section_id']);
    // The other program's class is held in that program's own section (a section only takes its program's subjects).
    $section = Database::selectOne('SELECT * FROM sections WHERE id = ?', [$e['section_id']]);
    $sectionB = Database::insert('sections', [
        'code' => testCode('SB'), 'program_id' => $programB, 'year_level_id' => (int) $section['year_level_id'], 'term_id' => (int) $section['term_id'], 'capacity' => 40, 'is_active' => 1,
    ]);
    $class = static fn (int $subject, int $day, ?int $sectionId = null): int => makeClass($e['registrar'], $sectionId ?? $e['section_id'], $subject, null, [meeting($day, '13:00', '14:00')]);

    return $e + [
        'program_a' => $programA, 'curriculum_a' => $curriculumA, 'curriculum_b' => $curriculumB,
        'own_class' => $class($own, 2, $siblingA), 'foreign_class' => $class($foreign, 3, $sectionB), 'general_class' => $class($general, 4, $siblingA), 'sibling_a' => $siblingA,
    ];
}

function addableIds(int $enrollmentId): array
{
    return array_map('intval', array_column(ClassSchedule::addableFor($enrollmentId), 'id'));
}

return [
    'the Add a class list offers the student\'s own curriculum and general subjects, and not another program\'s' => function (TestRunner $t): void {
        try {
            $f = programFixture();
            $ids = addableIds($f['enrollment_id']);
            $t->assertTrue(in_array($f['own_class'], $ids, true), 'A subject of the student\'s own curriculum is offered');
            $t->assertTrue(in_array($f['general_class'], $ids, true), 'A general subject is offered');
            $t->assertFalse(in_array($f['foreign_class'], $ids, true), 'Another program\'s subject is not');

            // Every offered class is the student's own, general, or (without a curriculum) their program's: none of the term's other programs.
            foreach (ClassSchedule::addableFor($f['enrollment_id']) as $class) {
                $row = Database::selectOne('SELECT curriculum_id, program_id FROM subjects WHERE id = ?', [$class['subject_id']]);
                $t->assertTrue($row['curriculum_id'] === null || (int) $row['curriculum_id'] === $f['curriculum_a'], "{$class['code']} is not another curriculum's");
            }
        } finally {
            deleteTestUsers();
            Database::query("DELETE FROM curricula WHERE code LIKE ?", [TEST_CODE . '%']);
            Database::query("DELETE FROM programs WHERE code LIKE ?", [TEST_CODE . '%']);
        }
    },

    'adding another program\'s class is refused with a message, not an override, and the rest can still be added' => function (TestRunner $t): void {
        try {
            $f = programFixture();
            $t->assertThrows(static fn () => EnlistmentService::addClass($f['registrar'], $f['enrollment_id'], $f['foreign_class'], null), 'another program');
            $t->assertThrows(static fn () => EnlistmentService::addClass($f['registrar'], $f['enrollment_id'], $f['foreign_class'], 'The student insists'), 'another program');
            $t->assertEquals(0, (int) Database::scalar("SELECT COUNT(*) FROM class_enlistments WHERE enrollment_id = ? AND class_schedule_id = ?", [$f['enrollment_id'], $f['foreign_class']]), 'Nothing was enlisted');

            EnlistmentService::addClass($f['registrar'], $f['enrollment_id'], $f['own_class'], null);
            EnlistmentService::addClass($f['registrar'], $f['enrollment_id'], $f['general_class'], null);
            $t->assertEquals(2, (int) Database::scalar("SELECT COUNT(*) FROM class_enlistments WHERE enrollment_id = ? AND class_schedule_id IN (?, ?) AND status = 'ENLISTED'", [$f['enrollment_id'], $f['own_class'], $f['general_class']]));
            $t->assertFalse(in_array($f['own_class'], addableIds($f['enrollment_id']), true), 'Once enlisted it is no longer offered');
        } finally {
            deleteTestUsers();
            Database::query("DELETE FROM curricula WHERE code LIKE ?", [TEST_CODE . '%']);
            Database::query("DELETE FROM programs WHERE code LIKE ?", [TEST_CODE . '%']);
        }
    },

    'a student with no curriculum yet is offered their program\'s classes, and a class already enlisted from another program is left alone' => function (TestRunner $t): void {
        try {
            $f = programFixture();
            Database::update('students', ['curriculum_id' => null], ['id' => $f['student_id']]);
            // Subjects of the student's program, in any of its curricula, are offered; another program's still are not.
            $ownProgram = makeSubject(3, [], ['code' => testCode('OP'), 'curriculum_id' => $f['curriculum_a'], 'program_id' => $f['program_a']]);
            $ownProgramClass = makeClass($f['registrar'], $f['sibling_a'], $ownProgram, null, [meeting(5, '13:00', '14:00')]);
            $ids = addableIds($f['enrollment_id']);
            $t->assertTrue(in_array($ownProgramClass, $ids, true), 'Their program\'s class is offered');
            $t->assertFalse(in_array($f['foreign_class'], $ids, true), 'Another program\'s is not');

            // A class from another program already on the record (made before this rule) stays enlisted and is not dropped.
            Database::insert('class_enlistments', [
                'enrollment_id' => $f['enrollment_id'], 'class_schedule_id' => $f['foreign_class'], 'status' => 'ENLISTED',
                'enlisted_at' => date('Y-m-d H:i:s'), 'enlisted_by' => $f['registrar']->id,
            ]);
            EnlistmentService::addClass($f['registrar'], $f['enrollment_id'], $ownProgramClass, null);
            $t->assertEquals('ENLISTED', Database::scalar('SELECT status FROM class_enlistments WHERE enrollment_id = ? AND class_schedule_id = ?', [$f['enrollment_id'], $f['foreign_class']]), 'The existing class is untouched');
        } finally {
            deleteTestUsers();
            Database::query("DELETE FROM curricula WHERE code LIKE ?", [TEST_CODE . '%']);
            Database::query("DELETE FROM programs WHERE code LIKE ?", [TEST_CODE . '%']);
        }
    },

    'the rule is written down and shown on the registrar\'s record page' => function (TestRunner $t): void {
        $doc = (string) file_get_contents(BASE_PATH . '/docs/13_ACADEMIC_MANAGEMENT.md');
        $t->assertContains('Which classes a student can be given', $doc);
        $t->assertContains('never another program\'s', $doc);
        $t->assertContains('addableFor', (string) file_get_contents(BASE_PATH . '/app/Models/ClassSchedule.php'));
        $t->assertContains('programProblem', (string) file_get_contents(BASE_PATH . '/app/Services/EnlistmentService.php'), 'Checked on the server too');
    },
];
