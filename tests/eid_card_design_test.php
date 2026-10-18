<?php
/**
 * The redesigned e-ID card (docs/29): portrait CR80, the personal details on the card back, the President from settings, blood type on the
 * profile, and nothing new on the public verification page.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\UploadedFile;
use App\Models\User;
use App\Services\EIdService;
use App\Services\SettingsService;
use App\Services\StudentProfileService;

/** An enrolled student with a full profile and an emergency contact, ready for the card. @return array<string,mixed> */
function cardStudent(): array
{
    $e = enrolledStudent();
    Database::update('student_profiles', [
        'blood_type' => 'O+', 'street' => '', 'barangay' => 'Galintuja', 'city_municipality' => 'Maria Aurora', 'province' => 'Aurora', 'birthdate' => '2007-01-16',
    ], ['student_id' => $e['student_id']]);
    Database::update('users', ['first_name' => 'Juvirena', 'middle_name' => 'Pascual', 'last_name' => 'Pascual'], ['id' => $e['student']->id]);

    return $e;
}

return [
    'a student\'s card carries the ribbon name, address, birthday, blood type, emergency contact, the grid, and the President' => function (TestRunner $t): void {
        try {
            $e = cardStudent();
            $card = EIdService::card(User::find($e['student']->id));

            $t->assertEquals('PASCUAL, JUVIRENA P.', $card['ribbonName'], 'LAST, FIRST M. as on the ribbon');
            $t->assertEquals('GALINTUJA, MARIA AURORA, AURORA', $card['address']);
            $t->assertEquals('JANUARY 16, 2007', $card['birthday']);
            $t->assertEquals('O+', $card['bloodType']);
            $t->assertNotNull($card['emergency'], 'The guardian on the profile is the emergency contact');
            $t->assertTrue($card['emergency']['mobile'] !== '');

            $t->assertEquals(4, count($card['grid']), 'Four school years');
            $t->assertTrue(preg_match('/^\d{4}-\d{4}$/', $card['grid'][0]['year']) === 1);
            $t->assertEquals((int) substr($card['grid'][0]['year'], 0, 4) + 1, (int) substr($card['grid'][1]['year'], 0, 4), 'One school year after another');
            $marked = 0;
            foreach ($card['grid'] as $row) {
                $marked += (int) $row['marks'][1] + (int) $row['marks'][2];
            }
            $t->assertTrue($marked >= 1, 'The semester the student is enrolled in is marked');

            $t->assertTrue($card['president']['name'] !== '' && $card['president']['title'] !== '', 'The President comes from settings');
            $t->assertContains('<svg', $card['qrSvg'], 'The QR is still on the card');
            $t->assertContains('/verify/' . $card['code'], $card['verifyUrl'], 'And it holds the same kind of address as before');
        } finally {
            deleteTestUsers();
        }
    },

    'a staff card has the ribbon name and the President but none of the personal details' => function (TestRunner $t): void {
        try {
            $teacher = makeUser(User::ROLE_TEACHER);
            $card = EIdService::card($teacher);
            $t->assertTrue($card['ribbonName'] !== '');
            $t->assertTrue(isset($card['president']));
            foreach (['address', 'birthday', 'bloodType', 'emergency', 'grid'] as $key) {
                $t->assertFalse(array_key_exists($key, $card), "A staff card has no {$key}");
            }
        } finally {
            deleteTestUsers();
        }
    },

    'the public verification page shows nothing of the new personal details' => function (TestRunner $t): void {
        try {
            $e = cardStudent();
            $user = User::find($e['student']->id);
            $card = EIdService::card($user);
            $result = EIdService::verify($card['code'], '127.0.0.1');
            $t->assertNotNull($result);
            foreach (['address', 'birthday', 'bloodType', 'emergency', 'grid', 'ribbonName'] as $key) {
                $t->assertFalse(array_key_exists($key, $result), "The public result has no {$key}");
            }
            $json = (string) json_encode($result);
            foreach (['GALINTUJA', 'JANUARY 16', 'O+'] as $secret) {
                $t->assertFalse(str_contains(mb_strtoupper($json), $secret), "{$secret} is not in the public result");
            }
        } finally {
            deleteTestUsers();
        }
    },

    'blood type is an optional profile field, validated and saved' => function (TestRunner $t): void {
        try {
            $student = makeStudent();
            StudentProfileService::update($student, array_merge(completeProfileInput(), ['blood_type' => 'AB-']));
            $t->assertEquals('AB-', Database::scalar('SELECT sp.blood_type FROM student_profiles sp JOIN students s ON s.id = sp.student_id WHERE s.user_id = ?', [$student->id]));

            $errors = fieldErrors(static fn () => StudentProfileService::update($student, array_merge(completeProfileInput(), ['blood_type' => 'Z+'])));
            $t->assertTrue(isset($errors['blood_type']), 'An unknown blood type is refused');

            StudentProfileService::update($student, array_merge(completeProfileInput(), ['blood_type' => '']));
            $t->assertNull(Database::scalar('SELECT sp.blood_type FROM student_profiles sp JOIN students s ON s.id = sp.student_id WHERE s.user_id = ?', [$student->id]), 'Optional: it can be cleared');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the President\'s name, title, and signature are settings the administrator changes' => function (TestRunner $t): void {
        try {
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            $before = [
                'name' => (string) App\Models\SystemSetting::get('president_name', ''), 'title' => (string) App\Models\SystemSetting::get('president_title', ''),
                'signature' => (string) App\Models\SystemSetting::get('president_signature', ''),
            ];
            $school = [
                'school_name' => App\Models\SystemSetting::get('school_name', 'Lyceum'), 'school_tagline' => '', 'school_address' => App\Models\SystemSetting::get('school_address', 'Baler'),
                'school_email' => App\Models\SystemSetting::get('school_email', 'info@schoolhub.test'), 'school_phone' => App\Models\SystemSetting::get('school_phone', '0'), 'office_hours' => '',
            ];

            SettingsService::update($admin, 'school', array_merge($school, ['president_name' => 'Dr. Test President', 'president_title' => 'President']));
            $t->assertEquals('Dr. Test President', App\Models\SystemSetting::get('president_name', ''));

            $signature = UploadedFile::forTesting(fixtureFile('png'), 'sign.png');
            SettingsService::update($admin, 'school', array_merge($school, ['president_name' => 'Dr. Test President', 'president_title' => 'President']), null, $signature);
            $path = (string) App\Models\SystemSetting::get('president_signature', '');
            $t->assertTrue($path !== '' && is_file(STORAGE_PATH . '/' . $path), 'The signature is stored');
            $t->assertContains('data:image/png;base64,', (string) SettingsService::presidentSignatureUri(), 'And printed on the card as a data URI');

            SettingsService::update($admin, 'school', array_merge($school, ['president_name' => 'Dr. Test President', 'president_title' => 'President', 'remove_president_signature' => '1']));
            $t->assertNull(SettingsService::presidentSignatureUri(), 'It can be removed');
            $t->assertFalse(is_file(STORAGE_PATH . '/' . $path), 'And the file goes with it');

            $t->assertEquals(403, statusOf(static fn () => SettingsService::update(makeUser(User::ROLE_REGISTRAR), 'school', $school)), 'Only the administrator');

            // Put the settings back as they were.
            SettingsService::update($admin, 'school', array_merge($school, ['president_name' => $before['name'], 'president_title' => $before['title']]));
        } finally {
            deleteTestUsers();
        }
    },

    'the card is portrait CR80 on the screen and the print page, and the forms ask for blood type' => function (TestRunner $t): void {
        $card = (string) file_get_contents(BASE_PATH . '/app/Views/partials/e-id-card.php');
        $t->assertContains('width:54mm;height:85.6mm', $card, 'Portrait CR80');
        $t->assertContains('NON-TRANSFERABLE', $card);
        $t->assertContains('SCAN TO VERIFY', $card);
        $t->assertContains("\$card['qrSvg']", $card, 'The QR is still printed');
        $t->assertContains('IN CASE OF EMERGENCY, PLEASE NOTIFY:', $card);
        $t->assertContains("assets/images/campus.jpg", $card, 'The school building is the front background');

        $layout = (string) file_get_contents(BASE_PATH . '/app/Views/layouts/card.php');
        $t->assertContains('size: 54mm 85.6mm', $layout, 'The print page is the card');
        $t->assertContains('.screen-only', $layout, 'The check marks are not printed');

        foreach (['student/profile', 'registrar/walk-in/create', 'registrar/students/show'] as $view) {
            $t->assertContains("partial('blood-type-select'", (string) file_get_contents(BASE_PATH . "/app/Views/{$view}.php"), "{$view} asks for blood type");
        }
        $t->assertContains('president_signature', (string) file_get_contents(BASE_PATH . '/app/Views/admin/settings/index.php'), 'The settings page takes the signature');
    },
];
