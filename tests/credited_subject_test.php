<?php
/**
 * The registrar credits subjects to a student (transfer credit; docs/28). A credit counts as
 * passed, so it satisfies prerequisites and progress, and only the registrar may record it.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\HttpException;
use App\Models\CreditedSubject;
use App\Models\User;
use App\Services\AcademicRecordService;
use App\Services\CreditedSubjectService;

/** @return array{0:User,1:int} a registrar and a fresh student's id */
function creditFixture(): array
{
    $registrar = makeUser(User::ROLE_REGISTRAR);
    $student = makeStudent();
    $studentId = (int) Database::scalar('SELECT id FROM students WHERE user_id = ?', [$student->id]);
    return [$registrar, $studentId];
}

return [
    'crediting a subject makes it count as passed, and it shows on the record' => function (TestRunner $t): void {
        try {
            [$registrar, $studentId] = creditFixture();
            $subjectId = makeSubject(3.0);

            $t->assertFalse(AcademicRecordService::hasPassed($studentId, $subjectId), 'Not passed before crediting');

            CreditedSubjectService::add($registrar, $studentId, [
                'subject_id' => $subjectId, 'grade' => '1.75', 'source' => 'Transferred from ABC College',
            ]);

            $t->assertTrue(AcademicRecordService::hasPassed($studentId, $subjectId), 'Credited subject counts as passed');
            $t->assertTrue(in_array($subjectId, AcademicRecordService::passedSubjectIds($studentId), true), 'It is in the passed set (so prerequisites see it)');

            $list = CreditedSubject::forStudent($studentId);
            $t->assertEquals(1, count($list), 'One credit listed');
            $t->assertEquals('1.75', $list[0]['grade']);
            $t->assertEquals('Transferred from ABC College', $list[0]['source']);
            $t->assertEquals('3.0', (string) $list[0]['units'], 'The units are snapshotted from the subject');

            $audit = Database::selectOne("SELECT new_values FROM audit_logs WHERE action = 'student.subject_credited' AND target_id = ? ORDER BY id DESC LIMIT 1", [$studentId]);
            $t->assertTrue($audit !== null && str_contains((string) $audit['new_values'], 'subject'), 'The credit is audited');
        } finally {
            deleteTestUsers();
            deleteTestCatalog();
        }
    },

    'a credit is refused when duplicate, already passed, unknown, or the grade is invalid' => function (TestRunner $t): void {
        try {
            [$registrar, $studentId] = creditFixture();
            $subjectId = makeSubject(3.0);

            CreditedSubjectService::add($registrar, $studentId, ['subject_id' => $subjectId]);

            $t->assertTrue(isset(fieldErrors(static fn () => CreditedSubjectService::add($registrar, $studentId, ['subject_id' => $subjectId]))['subject_id']), 'A duplicate credit is refused');
            $t->assertTrue(isset(fieldErrors(static fn () => CreditedSubjectService::add($registrar, $studentId, ['subject_id' => 0]))['subject_id']), 'No subject chosen is refused');
            $t->assertTrue(isset(fieldErrors(static fn () => CreditedSubjectService::add($registrar, $studentId, ['subject_id' => makeSubject(3.0), 'grade' => 'INC']))['grade']), 'A non-numeric grade is refused');
            $t->assertTrue(isset(fieldErrors(static fn () => CreditedSubjectService::add($registrar, $studentId, ['subject_id' => makeSubject(3.0), 'grade' => '9.99']))['grade']), 'A grade off the scale is refused');

            $t->assertEquals(1, count(CreditedSubject::forStudent($studentId)), 'Only the first valid credit was stored');
        } finally {
            deleteTestUsers();
            deleteTestCatalog();
        }
    },

    'only a role with academic.credit may credit or remove; a cashier is refused' => function (TestRunner $t): void {
        try {
            [$registrar, $studentId] = creditFixture();
            $subjectId = makeSubject(3.0);
            $cashier = makeUser(User::ROLE_CASHIER);

            $t->assertThrows(static fn () => CreditedSubjectService::add($cashier, $studentId, ['subject_id' => $subjectId]), null, 'A cashier cannot credit');
            $t->assertEquals(403, statusOf(static fn () => CreditedSubjectService::add($cashier, $studentId, ['subject_id' => $subjectId])), 'Refused with 403');
            $t->assertEquals([], CreditedSubject::forStudent($studentId), 'Nothing was credited by the cashier');

            // The registrar credits, then removes; the subject stops counting as passed.
            CreditedSubjectService::add($registrar, $studentId, ['subject_id' => $subjectId]);
            $creditId = (int) CreditedSubject::forStudent($studentId)[0]['id'];
            $t->assertEquals(403, statusOf(static fn () => CreditedSubjectService::remove($cashier, $studentId, $creditId)), 'A cashier cannot remove a credit');

            CreditedSubjectService::remove($registrar, $studentId, $creditId);
            $t->assertFalse(AcademicRecordService::hasPassed($studentId, $subjectId), 'After removal it no longer counts as passed');
            $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'student.subject_credit_removed' AND target_id = ?", [$studentId]), 'The removal is audited');
        } finally {
            deleteTestUsers();
            deleteTestCatalog();
        }
    },

    'a credit unlocks a subject whose prerequisite the student never took here' => function (TestRunner $t): void {
        try {
            [$registrar, $studentId] = creditFixture();
            $prerequisite = makeSubject(3.0);
            $advanced = makeSubject(3.0, [$prerequisite]);

            // Before crediting, the prerequisite is unmet.
            $t->assertFalse(AcademicRecordService::hasPassed($studentId, $prerequisite), 'Prerequisite not passed yet');

            CreditedSubjectService::add($registrar, $studentId, ['subject_id' => $prerequisite, 'source' => 'Credited on transfer']);

            $t->assertTrue(AcademicRecordService::hasPassed($studentId, $prerequisite), 'The credited prerequisite now counts, unlocking the advanced subject');
        } finally {
            deleteTestUsers();
            deleteTestCatalog();
        }
    },
];
