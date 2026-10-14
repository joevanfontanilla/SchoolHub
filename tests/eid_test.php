<?php
/**
 * E-ID cards and QR verification (docs/29): the QR encoder, who is eligible, what the public page shows,
 * when an ID stops being valid, reissue, and the lookup limit.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\Router;
use App\Core\UploadedFile;
use App\Models\Finance;
use App\Models\User;
use App\Services\AvatarService;
use App\Services\EIdService;
use App\Services\FinanceService;
use App\Services\PaymentPolicy;
use App\Services\QrCode;

function eidMatrixHash(string $text): string
{
    return sha1(implode('', array_map(static fn (array $row): string => implode('', array_map(static fn (bool $b): string => $b ? '1' : '0', $row)), QrCode::matrix($text))));
}

/**
 * An enrolled student (so they hold a student number) and the active-year setup the e-ID needs. Put the
 * school year back with eidRestoreYear().
 *
 * @return array{0:User,1:array<string,mixed>} the student and the year as it was
 */
function eidEnrolledStudent(): array
{
    [$student, , $applicationId] = approvedApplication();
    $cashier = makeUser(User::ROLE_CASHIER);
    $assessment = Finance::assessmentForApplication($applicationId);
    FinanceService::recordPayment($cashier, (int) $assessment['id'], ['amount' => FinanceService::money(PaymentPolicy::requiredCents($assessment)), 'payment_method' => 'Cash']);

    $year = Database::selectOne(
        'SELECT ay.* FROM academic_years ay JOIN terms t ON t.academic_year_id = ay.id WHERE t.id = ?',
        [(int) $assessment['term_id']]
    );
    Database::update('academic_years', ['is_active' => 1, 'end_date' => date('Y-m-d', strtotime('+1 year'))], ['id' => $year['id']]);

    return [$student, $year];
}

function eidRestoreYear(array $year): void
{
    Database::update('academic_years', ['is_active' => $year['is_active'], 'end_date' => $year['end_date']], ['id' => $year['id']]);
}

function eidCodeOf(User $user): string
{
    return (string) Database::scalar('SELECT code FROM e_ids WHERE user_id = ?', [$user->id]);
}

return [
    'the QR encoder produces the verified matrices, sizes by version, and refuses text that does not fit' => function (TestRunner $t): void {
        // Hashes of matrices that an independent decoder read back correctly (versions 1, 4, 10).
        $t->assertEquals('1ac30f51b8856652bc11fd34705b48bef460b393', eidMatrixHash('a'));
        $t->assertEquals('bae9500644f57dcb9c6e6e12960bc777bc7f7a27', eidMatrixHash('HELLO WORLD'));
        $t->assertEquals('c0b786695cbffea5cdd60c9bed41934208a352a7', eidMatrixHash('https://example.com/verify/0123456789abcdef01234567'));
        $t->assertEquals('83ed03aadf230da1d73899ad646ba10e07701ef9', eidMatrixHash(str_repeat('0123456789', 21) . 'abc'));

        foreach ([[14, 21], [15, 25], [62, 33], [63, 37], [213, 57]] as [$length, $size]) {
            $t->assertEquals($size, count(QrCode::matrix(str_repeat('x', $length))), "{$length} bytes is a {$size} x {$size} code");
        }

        $threw = false;
        try {
            QrCode::matrix(str_repeat('a', 214));
        } catch (InvalidArgumentException) {
            $threw = true;
        }
        $t->assertTrue($threw, '214 bytes is too long');

        // The three finder patterns, the timing line, and the always-dark module are where the standard puts them.
        $m = QrCode::matrix('https://example.com/verify/x');
        $n = count($m);
        foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$x, $y]) {
            $t->assertTrue($m[$y][$x] && $m[$y][$x + 6] && $m[$y + 6][$x] && $m[$y + 6][$x + 6] && !$m[$y + 1][$x + 1] && $m[$y + 3][$x + 3], 'A finder pattern');
        }
        $t->assertTrue($m[6][8] && !$m[6][9] && $m[6][10], 'The timing pattern alternates');
        $t->assertTrue($m[$n - 8][8], 'The dark module');

        $svg = QrCode::svg('hello', 4, 'A "quoted" <label>');
        $t->assertContains('viewBox="0 0 29 29"', $svg, 'A version 1 code (21) plus a quiet zone of 4 each side');
        $t->assertContains('shape-rendering="crispEdges"', $svg);
        $t->assertFalse(str_contains($svg, '<label>') || str_contains($svg, '"quoted"'), 'The label is escaped');
    },

    'a student needs a student number; staff need an active account; staff get an ID number on first use' => function (TestRunner $t): void {
        try {
            $student = makeStudent();
            $t->assertTrue(EIdService::ineligibleReason($student) !== null, 'A student without a number has no e-ID');
            $t->assertEquals(404, statusOf(static fn () => EIdService::card($student)));

            $teacher = makeUser(User::ROLE_TEACHER);
            $t->assertNull(EIdService::ineligibleReason($teacher));
            $card = EIdService::card($teacher);
            $t->assertTrue(preg_match('/^EMP-\d{4}-\d{5}$/', $card['idNumber']) === 1, 'A staff ID number is generated');
            $t->assertEquals('staff', $card['kind']);
            $t->assertTrue(preg_match('/^[a-f0-9]{24}$/', $card['code']) === 1, 'A 24-character random code');
            $t->assertContains('/verify/' . $card['code'], $card['verifyUrl'], 'The QR holds the verification address');
            $t->assertContains('<svg', $card['qrSvg']);
            $t->assertTrue($card['valid'], 'An active staff account is valid');

            $again = EIdService::card($teacher);
            $t->assertEquals($card['code'], $again['code'], 'The same card on the next visit');
            $t->assertEquals($card['idNumber'], $again['idNumber'], 'And the same number');
            $t->assertEquals(1, (int) Database::scalar('SELECT COUNT(*) FROM e_ids WHERE user_id = ?', [$teacher->id]));

            $disabled = makeUser(User::ROLE_CASHIER, 'TestPass123', User::STATUS_DISABLED);
            $t->assertTrue(EIdService::ineligibleReason($disabled) !== null, 'A disabled account has no e-ID');
        } finally {
            deleteTestUsers();
        }
    },

    'a student\'s e-ID uses the student number, and the public page shows only the minimum' => function (TestRunner $t): void {
        $year = null;
        try {
            [$student, $year] = eidEnrolledStudent();
            $card = EIdService::card($student);
            $number = (string) Database::scalar('SELECT student_number FROM students WHERE user_id = ?', [$student->id]);
            $t->assertTrue($number !== '', 'The payment enrolled the student');
            $t->assertEquals($number, $card['idNumber'], 'The ID number is the student number');
            $t->assertEquals('student', $card['kind']);
            $t->assertTrue($card['valid'], 'Enrolled in the active school year');

            $result = EIdService::verify($card['code'], '203.0.113.10');
            $t->assertTrue($result !== null, 'A scan of a valid card is valid');
            $t->assertEquals($number, $result['idNumber']);
            $t->assertEquals($student->fullName(), $result['name']);
            $allowed = ['name', 'schoolYear', 'role', 'kind', 'idNumber', 'program', 'programCode', 'yearLevel', 'hasPhoto'];
            $t->assertEquals([], array_values(array_diff(array_keys($result), $allowed)), 'Nothing beyond name, photo, ID number, program, and year');
            $t->assertFalse(str_contains(json_encode($result), (string) $student->email), 'No email');

            $t->assertEquals(1, (int) Database::scalar('SELECT scan_count FROM e_ids WHERE user_id = ?', [$student->id]), 'The scan was counted');
            $t->assertTrue(Database::scalar('SELECT last_scanned_at FROM e_ids WHERE user_id = ?', [$student->id]) !== null);
        } finally {
            if ($year !== null) {
                eidRestoreYear($year);
            }
            Database::query("DELETE FROM e_id_lookups WHERE ip_address LIKE '203.0.113.%'");
            deleteTestUsers();
        }
    },

    'an unknown code, a malformed one, a disabled account, and an ended school year are all simply not valid' => function (TestRunner $t): void {
        $year = null;
        try {
            [$student, $year] = eidEnrolledStudent();
            $code = EIdService::card($student)['code'];
            $ip = '203.0.113.11';

            $t->assertNull(EIdService::verify(str_repeat('a', 24), $ip), 'An unknown code');
            $t->assertNull(EIdService::verify('not-a-code', $ip), 'A malformed code');
            $t->assertNull(EIdService::verify(strtoupper($code), $ip), 'Codes are lowercase hex');
            $t->assertTrue(EIdService::verify($code, $ip) !== null, 'The real code works');
            $scans = (int) Database::scalar('SELECT scan_count FROM e_ids WHERE user_id = ?', [$student->id]);

            // Not valid scans are not counted.
            Database::update('users', ['status' => User::STATUS_DISABLED], ['id' => $student->id]);
            $t->assertNull(EIdService::verify($code, $ip), 'A disabled account');
            $t->assertEquals($scans, (int) Database::scalar('SELECT scan_count FROM e_ids WHERE user_id = ?', [$student->id]), 'A failed scan is not counted');
            Database::update('users', ['status' => User::STATUS_ACTIVE], ['id' => $student->id]);
            $t->assertTrue(EIdService::verify($code, $ip) !== null, 'Active again');

            Database::update('academic_years', ['end_date' => date('Y-m-d', strtotime('-1 day'))], ['id' => $year['id']]);
            $t->assertNull(EIdService::verify($code, $ip), 'The school year has ended');
            Database::update('academic_years', ['end_date' => date('Y-m-d', strtotime('+1 year')), 'is_active' => 0], ['id' => $year['id']]);
            $t->assertNull(EIdService::verify($code, $ip), 'The year is no longer the active one');
        } finally {
            if ($year !== null) {
                eidRestoreYear($year);
            }
            Database::query("DELETE FROM e_id_lookups WHERE ip_address LIKE '203.0.113.%'");
            deleteTestUsers();
        }
    },

    'reissuing replaces the code at once; the registrar does it for students, the administrator for staff, nobody else' => function (TestRunner $t): void {
        $year = null;
        try {
            [$student, $year] = eidEnrolledStudent();
            $teacher = makeUser(User::ROLE_TEACHER);
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            $cashier = makeUser(User::ROLE_CASHIER);
            $ip = '203.0.113.12';

            $old = EIdService::card($student)['code'];
            $number = EIdService::card($student)['idNumber'];
            EIdService::card($teacher);

            $t->assertEquals(403, statusOf(static fn () => EIdService::reissue($student, $student->id)), 'A holder cannot reissue their own');
            $t->assertEquals(403, statusOf(static fn () => EIdService::reissue($cashier, $student->id)), 'Nor can the cashier');
            $t->assertEquals(403, statusOf(static fn () => EIdService::reissue($registrar, $teacher->id)), 'The registrar does not reissue staff cards');
            $t->assertEquals(403, statusOf(static fn () => EIdService::reissue($teacher, $teacher->id)), 'Staff cannot reissue their own');
            $t->assertEquals($old, eidCodeOf($student), 'Nothing changed');

            EIdService::reissue($registrar, $student->id);
            $new = eidCodeOf($student);
            $t->assertTrue($new !== $old, 'A new code');
            $t->assertNull(EIdService::verify($old, $ip), 'The old QR stops working at once');
            $t->assertTrue(EIdService::verify($new, $ip) !== null, 'The new one works');
            $t->assertEquals($number, EIdService::card($student)['idNumber'], 'The ID number stays');
            $t->assertEquals(1, EIdService::card($student)['reissueCount']);

            $teacherOld = eidCodeOf($teacher);
            EIdService::reissue($admin, $teacher->id);
            $t->assertTrue(eidCodeOf($teacher) !== $teacherOld, 'The administrator reissued a staff card');

            $audit = (string) Database::scalar("SELECT new_values FROM audit_logs WHERE action = 'eid.reissued' AND target_id = ? ORDER BY id DESC LIMIT 1", [$student->id]);
            $t->assertFalse(str_contains($audit, $new) || str_contains($audit, $old), 'The audit entry holds no code');
            $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'eid.reissued' AND target_id = ?", [$student->id]), 'The reissue is audited');
        } finally {
            if ($year !== null) {
                eidRestoreYear($year);
            }
            Database::query("DELETE FROM e_id_lookups WHERE ip_address LIKE '203.0.113.%'");
            deleteTestUsers();
        }
    },

    'the public page limits lookups per visitor per minute' => function (TestRunner $t): void {
        $ip = '203.0.113.' . random_int(100, 250);
        try {
            for ($i = 1; $i <= EIdService::LOOKUPS_PER_MINUTE; $i++) {
                EIdService::verify(str_repeat('b', 24), $ip);
            }
            $t->assertEquals(429, statusOf(static fn () => EIdService::verify(str_repeat('b', 24), $ip)), 'The next lookup is refused');
            $t->assertEquals(429, statusOf(static fn () => EIdService::photoFor(str_repeat('b', 24), $ip)), 'The photo lookups share the limit');
            $t->assertNull(EIdService::verify(str_repeat('b', 24), '203.0.113.' . random_int(1, 9)), 'Another visitor is not affected');
        } finally {
            Database::query("DELETE FROM e_id_lookups WHERE ip_address LIKE '203.0.113.%'");
        }
    },

    'the photo is on the card and on the public page only while the ID is valid' => function (TestRunner $t): void {
        try {
            $teacher = makeUser(User::ROLE_TEACHER);
            $ip = '203.0.113.13';
            $code = EIdService::card($teacher)['code'];

            $t->assertNull(EIdService::photoFor($code, $ip), 'No photo yet');
            $t->assertFalse(EIdService::card($teacher)['hasPhoto']);
            $t->assertNull(EIdService::card($teacher)['photoUri']);

            AvatarService::save($teacher, UploadedFile::forTesting(fixtureFile('png'), 'me.png'), false);
            $card = EIdService::card($teacher);
            $t->assertTrue($card['hasPhoto'] && str_starts_with((string) $card['photoUri'], 'data:image/png;base64,'), 'The photo is printed on the card');
            $photo = EIdService::photoFor($code, $ip);
            $t->assertTrue($photo !== null && $photo['mime'] === 'image/png', 'The public page can stream it for a valid code');

            Database::update('users', ['status' => User::STATUS_DISABLED], ['id' => $teacher->id]);
            $t->assertNull(EIdService::photoFor($code, $ip), 'Not for a disabled account');
            $t->assertNull(EIdService::photoFor(str_repeat('c', 24), $ip), 'Not for an unknown code');
            Database::update('users', ['status' => User::STATUS_ACTIVE], ['id' => $teacher->id]);
            AvatarService::save($teacher, null, true);
        } finally {
            Database::query("DELETE FROM e_id_lookups WHERE ip_address LIKE '203.0.113.%'");
            deleteTestUsers();
        }
    },

    'the e-ID routes: verification is public, the card needs a sign-in, reissue and other people\'s cards are the registrar\'s and administrator\'s' => function (TestRunner $t): void {
        $router = new Router();
        require BASE_PATH . '/routes/web.php';

        $found = [];
        foreach ($router->routes() as $route) {
            $pattern = $route['pattern'];
            if (str_starts_with($pattern, '/verify')) {
                $found['public'][] = $pattern;
                $t->assertFalse(in_array('auth', $route['middleware'], true), "{$pattern} needs no sign-in");
                $t->assertEquals('GET', $route['method'], 'Verification only reads');
            } elseif ($pattern === '/e-id' || $pattern === '/e-id/print') {
                $found['own'][] = $pattern;
                $t->assertTrue(in_array('auth', $route['middleware'], true), "{$pattern} needs a sign-in");
            } elseif (str_starts_with($pattern, '/registrar/students/{id}/e-id')) {
                $found['registrar'][] = $pattern;
                $t->assertTrue(in_array('role:' . User::ROLE_REGISTRAR, $route['middleware'], true) && in_array('can:students.manage', $route['middleware'], true), "{$pattern} is the registrar's");
            } elseif (str_starts_with($pattern, '/admin/users/{id}/e-id')) {
                $found['admin'][] = $pattern;
                $t->assertTrue(in_array('role:' . User::ROLE_SUPER_ADMIN, $route['middleware'], true) && in_array('can:users.manage', $route['middleware'], true), "{$pattern} is the administrator's");
            }
        }
        $t->assertEquals(3, count($found['public'] ?? []), 'The box, the result, and the photo');
        $t->assertEquals(2, count($found['own'] ?? []), 'The card and its printout');
        $t->assertEquals(3, count($found['registrar'] ?? []), 'View, print, reissue');
        $t->assertEquals(3, count($found['admin'] ?? []), 'View, print, reissue');
    },
];
