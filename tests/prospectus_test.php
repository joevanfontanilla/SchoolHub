<?php
/**
 * The curriculum checklist / prospectus (docs/28): every curriculum subject marked with the
 * student's status, with units earned, percent, and counts.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Models\User;
use App\Services\AcademicRecordService;
use App\Services\CreditedSubjectService;

return [
    'the prospectus lists curriculum subjects with status, earned units, and percent' => function (TestRunner $t): void {
        try {
            $programId = (int) Database::scalar('SELECT id FROM programs WHERE is_active = 1 LIMIT 1');
            $yearLevelId = (int) Database::scalar('SELECT id FROM year_levels ORDER BY sequence LIMIT 1');
            $curriculumId = Database::insert('curricula', [
                'program_id' => $programId, 'code' => testCode('C'), 'name' => 'Fixture Curriculum',
                'effective_school_year' => '2099-2100', 'is_active' => 1,
            ]);
            $s1 = makeSubject(3.0, [], ['curriculum_id' => $curriculumId, 'year_level_id' => $yearLevelId, 'term_sequence' => 1]);
            makeSubject(3.0, [], ['curriculum_id' => $curriculumId, 'year_level_id' => $yearLevelId, 'term_sequence' => 1]);
            makeSubject(3.0, [], ['curriculum_id' => $curriculumId, 'year_level_id' => $yearLevelId, 'term_sequence' => 2]);

            $student = makeStudent();
            $studentId = (int) Database::scalar('SELECT id FROM students WHERE user_id = ?', [$student->id]);
            Database::update('students', ['curriculum_id' => $curriculumId], ['id' => $studentId]);

            // One subject credited (transfer credit) counts as earned; the other two are not taken.
            $registrar = makeUser(User::ROLE_REGISTRAR);
            CreditedSubjectService::add($registrar, $studentId, ['subject_id' => $s1]);

            $p = AcademicRecordService::prospectus($studentId);
            $t->assertNotNull($p, 'A student with a curriculum has a prospectus');
            $t->assertEquals(9.0, $p['total'], 'Three 3-unit subjects');
            $t->assertEquals(3.0, $p['earned'], 'Only the credited subject is earned');
            $t->assertEquals(33, $p['percent']);
            $t->assertEquals(1, $p['counts']['CREDITED']);
            $t->assertEquals(2, $p['counts']['NOT_TAKEN']);
            $t->assertTrue($p['groups'] !== [], 'Subjects are grouped by year and term');
        } finally {
            deleteTestUsers();
            deleteTestCatalog();
        }
    },

    'a student with no curriculum has no prospectus' => function (TestRunner $t): void {
        try {
            $student = makeStudent();
            $studentId = (int) Database::scalar('SELECT id FROM students WHERE user_id = ?', [$student->id]);
            $t->assertNull(AcademicRecordService::prospectus($studentId));
        } finally {
            deleteTestUsers();
            deleteTestCatalog();
        }
    },
];
