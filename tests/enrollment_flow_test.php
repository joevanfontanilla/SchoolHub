<?php
/**
 * The enrollment flow end to end (docs/09): choose subjects, registrar approval
 * with a section and an assessment, online payment, cashier verification, and
 * automatic enrollment in the chosen subjects.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\HttpException;
use App\Core\UploadedFile;
use App\Core\ValidationException;
use App\Models\Finance;
use App\Models\User;
use App\Services\ClassScheduleService;
use App\Services\EnrollmentApplicationService;
use App\Services\FeeScheduleService;
use App\Services\FinanceAccessPolicy;
use App\Services\FinanceService;
use App\Services\SubjectSelectionService as Subjects;

function flowApplication(int $id): array
{
    return Database::selectOne('SELECT * FROM enrollment_applications WHERE id = ?', [$id]);
}

/** An online payment with a genuine PNG proof. */
function payOnline(User $student, int $assessmentId, string $amount, array $overrides = []): int
{
    return FinanceService::submitPayment($student, $assessmentId, array_merge([
        'amount' => $amount, 'method' => 'GCash', 'reference_number' => 'GC' . random_int(100000, 999999), 'paid_on' => date('Y-m-d'),
    ], $overrides), UploadedFile::forTesting(fixtureFile('png'), 'gcash-receipt.png'));
}

/** The expected assessment total, in centavos, straight from the fee schedule. */
function expectedTotal(array $subjects): int
{
    $units = array_sum(array_map(static fn ($s) => (float) $s['units'], $subjects));
    $lab = array_sum(array_map(static fn ($s) => (float) $s['lab_units'], $subjects));
    $total = 0;
    foreach (Database::select('SELECT * FROM fee_types WHERE is_active = 1') as $fee) {
        $quantity = match ($fee['basis']) { 'PER_UNIT' => $units, 'PER_LAB_UNIT' => $lab, default => 1.0 };
        $total += (int) round($quantity * FinanceService::cents($fee['amount']));
    }
    return $total;
}

return [
    'a first-year first-semester student can take every subject: there are no prerequisites' => function (TestRunner $t): void {
        try {
            $student = makeStudent();
            $studentId = (int) Database::scalar('SELECT id FROM students WHERE user_id = ?', [$student->id]);
            $firstYear = (int) Database::scalar('SELECT id FROM year_levels WHERE sequence = 1');
            $firstSemester = (int) Database::scalar('SELECT id FROM terms WHERE sequence = 1 ORDER BY start_date DESC LIMIT 1');

            foreach (Database::select("SELECT id, code FROM programs WHERE is_active = 1 AND code IN ('BSA','BSAIS','BSCRIM','BSENTREP','BPA','BSTM')") as $program) {
                $options = Subjects::options(['student_id' => $studentId, 'program_id' => $program['id'], 'year_level_id' => $firstYear, 'term_id' => $firstSemester]);
                $t->assertTrue($options !== [], "{$program['code']} offers first-year first-semester subjects");
                $locked = array_filter($options, static fn ($o) => !$o['selectable']);
                $t->assertEquals([], array_column($locked, 'code'), "{$program['code']}: nothing is locked for a new student");
            }
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'an application offers only its program\'s subjects for its year and term, with the regular load pre-checked' => function (TestRunner $t): void {
        try {
            [$student, $id] = submittedApplication();
            $application = flowApplication($id);
            $options = Subjects::options($application);
            $term = (int) Database::scalar('SELECT sequence FROM terms WHERE id = ?', [$application['term_id']]);
            $curriculum = Subjects::curriculumFor($application);

            $t->assertTrue($options !== [], 'Subjects are offered');
            foreach ($options as $option) {
                $t->assertEquals((int) $curriculum['id'], (int) $option['curriculum_id'], "{$option['code']} is from the program's curriculum");
                $t->assertEquals((int) $application['year_level_id'], (int) $option['year_level_id'], "{$option['code']} is for the year level");
                $t->assertEquals($term, (int) $option['term_sequence'], "{$option['code']} is for the term");
                if (!$option['selectable']) {
                    $t->assertContains('Requires', (string) $option['locked_reason'], "{$option['code']} says why it is locked");
                }
            }

            $selectable = array_map(static fn ($o) => (int) $o['id'], array_filter($options, static fn ($o) => $o['selectable']));
            sort($selectable);
            $chosen = Subjects::chosenIds($id);
            sort($chosen);
            $t->assertEquals($selectable, $chosen, 'Every subject that can be taken is pre-checked');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'saving subjects refuses locked, foreign, and empty choices, and nothing changes once submitted' => function (TestRunner $t): void {
        try {
            $student = makeStudent();
            $id = EnrollmentApplicationService::createDraft($student, applicationInput());
            $options = Subjects::options(flowApplication($id));
            $locked = array_values(array_filter($options, static fn ($o) => !$o['selectable']));
            $selectable = array_values(array_filter($options, static fn ($o) => $o['selectable']));
            $foreign = (int) Database::scalar("SELECT s.id FROM subjects s JOIN curricula c ON c.id = s.curriculum_id WHERE c.code = 'BSCRIM-2022' LIMIT 1");

            if ($locked !== []) {
                $errors = fieldErrors(static fn () => Subjects::save($student, $id, [$locked[0]['id']]));
                $t->assertContains('Requires', implode(' ', $errors['subjects'] ?? []), 'A subject with an unmet prerequisite is refused');
            }
            $errors = fieldErrors(static fn () => Subjects::save($student, $id, [$selectable[0]['id'], $foreign]));
            $t->assertContains('not offered', implode(' ', $errors['subjects'] ?? []), 'Another program\'s subject is refused');
            $errors = fieldErrors(static fn () => Subjects::save($student, $id, []));
            $t->assertContains('at least one', implode(' ', $errors['subjects'] ?? []));

            Subjects::save($student, $id, [$selectable[0]['id']]);
            $t->assertEquals([(int) $selectable[0]['id']], Subjects::chosenIds($id));

            $t->assertEquals(404, statusOf(static fn () => Subjects::save(makeStudent(), $id, [$selectable[0]['id']])), 'Another student\'s application');

            uploadAllRequired($student, $id);
            EnrollmentApplicationService::submit($student, $id);
            $t->assertThrows(static fn () => Subjects::save($student, $id, [$selectable[1]['id'] ?? $selectable[0]['id']]), 'cannot be changed');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'approval needs a section, and issues an assessment from the chosen subjects and the fee schedule' => function (TestRunner $t): void {
        try {
            withSetting('enrollment_payment_policy', 'MINIMUM_DOWNPAYMENT', static function () use ($t): void {
                [$student, $id] = submittedApplication();
                $registrar = makeUser(User::ROLE_REGISTRAR);
                App\Services\EnrollmentStatusService::transition($id, 'UNDER_REVIEW', $registrar);
                Database::query("UPDATE enrollment_requirements SET status = 'VERIFIED' WHERE application_id = ? AND is_required = 1", [$id]);

                $t->assertThrows(static fn () => App\Services\EnrollmentStatusService::transition($id, 'APPROVED', $registrar), 'Assign a section');
                $t->assertThrows(static fn () => EnrollmentApplicationService::approve($registrar, $id, 0), 'Choose a section');

                $sectionId = makeSectionFor($id);
                EnrollmentApplicationService::approve($registrar, $id, $sectionId);

                $application = flowApplication($id);
                $t->assertEquals('APPROVED', $application['application_status']);
                $t->assertEquals($sectionId, (int) $application['section_id']);
                $t->assertEquals('PENDING', $application['payment_status']);
                $t->assertEquals('NOT_ENROLLED', $application['enrollment_state'], 'A down payment is still needed');

                $assessment = Finance::assessmentForApplication($id);
                $t->assertTrue((bool) preg_match('/^ASM-\d{4}-\d{5}$/', $assessment['assessment_number']));
                $t->assertEquals(expectedTotal(Subjects::chosen($id)), FinanceService::cents($assessment['total_amount']), 'Total = units × tuition + lab units × lab fee + fixed fees');
                $t->assertEquals($assessment['total_amount'], $assessment['balance']);
                $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'enrollment.approved'", [$student->id]));
            });
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a payment below the down payment is receipted but does not enroll; reaching it enrolls in the chosen subjects only' => function (TestRunner $t): void {
        try {
            withSetting('enrollment_payment_policy', 'MINIMUM_DOWNPAYMENT', static fn () => withSetting('minimum_downpayment_percent', '20', static function () use ($t): void {
                [$student, $registrar, $id] = approvedApplication();
                $application = flowApplication($id);
                $sectionId = (int) $application['section_id'];
                $chosen = Subjects::chosenIds($id);

                $chosenClass = makeClass($registrar, $sectionId, $chosen[0], null, [meeting(1, '08:00', '09:00')]);
                $otherClass = ClassScheduleService::saveClass($registrar, $sectionId, null, [
                    'subject_id' => makeSubject(), 'teacher_user_id' => makeUser(User::ROLE_TEACHER)->id, 'meetings' => [meeting(2, '08:00', '09:00')],
                ])['class_id'];

                $assessment = Finance::assessmentForApplication($id);
                $required = (int) ceil(FinanceService::cents($assessment['total_amount']) * 20 / 100);
                $cashier = makeUser(User::ROLE_CASHIER);

                $first = payOnline($student, (int) $assessment['id'], '100.00');
                $result = FinanceService::approveSubmission($cashier, $first);
                $t->assertTrue((bool) preg_match('/^OR-\d{4}-\d{5}$/', $result['receipt_number']), 'An official receipt is issued');
                $t->assertFalse($result['enrolled']);
                $t->assertEquals('PARTIAL', flowApplication($id)['payment_status']);
                $t->assertEquals('NOT_ENROLLED', flowApplication($id)['enrollment_state']);
                $t->assertThrows(static fn () => FinanceService::approveSubmission($cashier, $first), 'already approved');

                $rest = FinanceService::money($required - 10000);
                $second = payOnline($student, (int) $assessment['id'], $rest);
                $result = FinanceService::approveSubmission($cashier, $second);
                $t->assertTrue($result['enrolled'], 'The down payment is met, so the student is enrolled');

                $application = flowApplication($id);
                $t->assertEquals('ENROLLED', $application['enrollment_state']);
                $enrollment = Database::selectOne('SELECT * FROM enrollments WHERE application_id = ?', [$id]);
                $t->assertEquals($sectionId, (int) $enrollment['section_id']);
                $t->assertNotNull(Database::scalar('SELECT student_number FROM students WHERE user_id = ?', [$student->id]), 'A student number is issued');
                $t->assertNotNull(enlistmentIdFor((int) $enrollment['id'], $chosenClass), 'Enlisted in a chosen subject\'s class');
                $t->assertNull(enlistmentIdFor((int) $enrollment['id'], $otherClass), 'Not enlisted in a subject the student did not choose');

                $fresh = Finance::assessment((int) $assessment['id']);
                $t->assertEquals(FinanceService::money($required), $fresh['total_paid']);
                $t->assertEquals(FinanceService::money(FinanceService::cents($fresh['total_amount']) - $required), $fresh['balance']);
                $t->assertEquals(2, (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'finance.payment_recorded'", [$student->id]));
                $t->assertEquals(2, (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'finance.payment_recorded' AND user_id = ?", [$cashier->id]));
            }));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'online payments are validated, owned, and verified only by the cashier; a rejection needs a reason' => function (TestRunner $t): void {
        try {
            withSetting('enrollment_payment_policy', 'FULL', static function () use ($t): void {
                [$student, , $id] = approvedApplication();
                $assessment = Finance::assessmentForApplication($id);
                $assessmentId = (int) $assessment['id'];
                $over = FinanceService::money(FinanceService::cents($assessment['balance']) + 1);

                $t->assertTrue(isset(fieldErrors(static fn () => payOnline($student, $assessmentId, $over))['amount']), 'More than the balance');
                $t->assertTrue(isset(fieldErrors(static fn () => payOnline($student, $assessmentId, '0'))['amount']), 'Zero');
                $t->assertTrue(isset(fieldErrors(static fn () => payOnline($student, $assessmentId, '10.555'))['amount']), 'Three decimals');
                $t->assertTrue(isset(fieldErrors(static fn () => payOnline($student, $assessmentId, '100', ['paid_on' => date('Y-m-d', strtotime('+2 days'))]))['paid_on']), 'A future date');
                $t->assertTrue(isset(fieldErrors(static fn () => payOnline($student, $assessmentId, '100', ['reference_number' => '']))['reference_number']), 'No reference');
                $t->assertThrows(static fn () => FinanceService::submitPayment($student, $assessmentId, ['amount' => '100', 'method' => 'GCash', 'reference_number' => 'X1', 'paid_on' => date('Y-m-d')], UploadedFile::forTesting(fixtureFile('fake_pdf'), 'proof.pdf')), 'not a real PDF');
                $t->assertEquals(0, (int) Database::scalar('SELECT COUNT(*) FROM payment_submissions WHERE fee_assessment_id = ?', [$assessmentId]), 'Nothing was saved');

                $t->assertEquals(404, statusOf(static fn () => payOnline(makeStudent(), $assessmentId, '100')), 'Another student\'s assessment');

                $submission = payOnline($student, $assessmentId, '500.00');
                $stored = Database::scalar('SELECT storage_path FROM payment_submissions WHERE id = ?', [$submission]);
                $t->assertTrue(is_file(STORAGE_PATH . '/' . $stored) && !str_contains((string) $stored, 'gcash'), 'The proof is stored under a random name, outside the web root');
                $t->assertTrue(isset(fieldErrors(static fn () => payOnline($student, $assessmentId, FinanceService::money(FinanceService::cents($assessment['balance']) - 49999)))['amount']), 'Pending payments count against the balance');

                foreach ([User::ROLE_REGISTRAR, User::ROLE_TEACHER, User::ROLE_SUPER_ADMIN] as $role) {
                    $t->assertEquals(403, statusOf(static fn () => FinanceService::approveSubmission(makeUser($role), $submission)), "{$role} cannot verify payments");
                }
                $t->assertEquals(403, statusOf(static fn () => FinanceService::approveSubmission($student, $submission)));

                $cashier = makeUser(User::ROLE_CASHIER);
                $t->assertTrue(isset(fieldErrors(static fn () => FinanceService::rejectSubmission($cashier, $submission, ' '))['review_remarks']));
                FinanceService::rejectSubmission($cashier, $submission, 'The reference number does not match any GCash transfer.');
                $t->assertEquals('REJECTED', Database::scalar('SELECT status FROM payment_submissions WHERE id = ?', [$submission]));
                $t->assertEquals(0, (int) Database::scalar('SELECT COUNT(*) FROM payments WHERE fee_assessment_id = ?', [$assessmentId]), 'A rejected payment records nothing');
                $t->assertEquals('PENDING', flowApplication($id)['payment_status']);
                $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'finance.payment_rejected'", [$student->id]));
            });
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'under the full-payment policy, a window payment enrolls the student only when the balance is zero' => function (TestRunner $t): void {
        try {
            withSetting('enrollment_payment_policy', 'FULL', static function () use ($t): void {
                [$student, , $id] = approvedApplication();
                $assessment = Finance::assessmentForApplication($id);
                $cashier = makeUser(User::ROLE_CASHIER);
                $total = FinanceService::cents($assessment['total_amount']);

                $t->assertTrue(isset(fieldErrors(static fn () => FinanceService::recordPayment($cashier, (int) $assessment['id'], ['amount' => '-5', 'payment_method' => 'Cash']))['amount']));
                $t->assertEquals(403, statusOf(static fn () => FinanceService::recordPayment(makeUser(User::ROLE_REGISTRAR), (int) $assessment['id'], ['amount' => '1', 'payment_method' => 'Cash'])));

                $first = FinanceService::recordPayment($cashier, (int) $assessment['id'], ['amount' => FinanceService::money($total - 100), 'payment_method' => 'Cash']);
                $t->assertFalse($first['enrolled'], 'One peso short of full payment');
                $t->assertThrows(static fn () => FinanceService::recordPayment($cashier, (int) $assessment['id'], ['amount' => '1.01', 'payment_method' => 'Cash']), 'more than the remaining balance');

                $last = FinanceService::recordPayment($cashier, (int) $assessment['id'], ['amount' => '1.00', 'payment_method' => 'Cash']);
                $t->assertTrue($last['enrolled']);
                $t->assertEquals('PAID', flowApplication($id)['payment_status']);
                $t->assertEquals('0.00', Finance::assessment((int) $assessment['id'])['balance']);
            });
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'receipts and statements open for their student, the cashier, and the registrar; not for other students or roles' => function (TestRunner $t): void {
        try {
            $owner = makeUser(User::ROLE_STUDENT);
            $check = static fn (?User $user, bool $exists = true) => statusOf(static fn () => FinanceAccessPolicy::check($user, $owner->id, $exists, 'receipt', [User::ROLE_CASHIER, User::ROLE_REGISTRAR]));

            $t->assertNull($check($owner));
            $t->assertEquals(404, $check(makeUser(User::ROLE_STUDENT)), 'Another student: it does not exist');
            $t->assertNull($check(makeUser(User::ROLE_CASHIER)));
            $t->assertNull($check(makeUser(User::ROLE_REGISTRAR)));
            $t->assertEquals(403, $check(makeUser(User::ROLE_TEACHER)));
            $t->assertEquals(403, $check(makeUser(User::ROLE_SUPER_ADMIN)));
            $t->assertEquals(404, $check(makeUser(User::ROLE_CASHIER), false));
            $t->assertEquals(401, $check(null));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'only the cashier sets fees, with a positive peso amount' => function (TestRunner $t): void {
        try {
            $cashier = makeUser(User::ROLE_CASHIER);
            $input = ['code' => testCode('F'), 'name' => 'Test fee', 'basis' => 'FIXED', 'amount' => '250.50', 'is_active' => '1'];

            foreach (['0', '-1', 'abc', '1.234'] as $bad) {
                $t->assertTrue(isset(fieldErrors(static fn () => FeeScheduleService::save($cashier, null, array_merge($input, ['amount' => $bad])))['amount']), "{$bad} is refused");
            }
            $t->assertTrue(isset(fieldErrors(static fn () => FeeScheduleService::save($cashier, null, array_merge($input, ['basis' => 'PER_DAY'])))['basis']));
            $t->assertEquals(403, statusOf(static fn () => FeeScheduleService::save(makeUser(User::ROLE_REGISTRAR), null, $input)));

            $id = FeeScheduleService::save($cashier, null, $input);
            $t->assertEquals('250.50', Database::scalar('SELECT amount FROM fee_types WHERE id = ?', [$id]));
            Database::query('DELETE FROM fee_types WHERE id = ?', [$id]); // fixture fee: must not leak into real assessments
        } finally {
            Database::query('DELETE FROM fee_types WHERE code LIKE ?', [TEST_CODE . '%']);
            actingAs(null);
            deleteTestUsers();
        }
    },
];
