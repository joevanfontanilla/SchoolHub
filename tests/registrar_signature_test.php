<?php
/**
 * The Registrar's name on the forms the registrar signs (docs/15): the Registrar who prints it, else the one who enrolled the student, else the
 * school Registrar from settings, with the signature image that belongs to that person, and the dry seal notice on the certificate.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\UploadedFile;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\RegistrarSignerService;
use App\Services\SettingsService;

/** The school settings the form posts, so a test can change one field. @return array<string,string> */
function registrarSettingsForm(array $overrides = []): array
{
    return array_merge([
        'school_name' => (string) SystemSetting::get('school_name', 'Lyceum'), 'school_tagline' => '', 'school_address' => (string) SystemSetting::get('school_address', 'Baler'),
        'school_email' => (string) SystemSetting::get('school_email', 'info@schoolhub.test'), 'school_phone' => (string) SystemSetting::get('school_phone', '0'), 'office_hours' => '',
        'president_name' => (string) SystemSetting::get('president_name', ''), 'president_title' => (string) SystemSetting::get('president_title', ''),
        'registrar_name' => (string) SystemSetting::get('registrar_name', ''),
    ], $overrides);
}

return [
    'the school Registrar\'s name is a setting, set up with a default, and changed by the administrator only' => function (TestRunner $t): void {
        try {
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            $original = (string) SystemSetting::get('registrar_name', '');
            $t->assertTrue($original !== '', 'A name is set up for the fallback');

            SettingsService::update($admin, 'school', registrarSettingsForm(['registrar_name' => 'Test Registrar Name']));
            $t->assertEquals('Test Registrar Name', SystemSetting::get('registrar_name', ''));
            $t->assertEquals(403, statusOf(static fn () => SettingsService::update(makeUser(User::ROLE_REGISTRAR), 'school', registrarSettingsForm(['registrar_name' => 'Someone Else']))), 'Not even the registrar changes it');
            $t->assertTrue(isset(fieldErrors(static fn () => SettingsService::update($admin, 'school', registrarSettingsForm(['registrar_name' => str_repeat('x', 101)])))['registrar_name']), 'Within 100 characters');

            SettingsService::update($admin, 'school', registrarSettingsForm(['registrar_name' => $original]));
        } finally {
            deleteTestUsers();
        }
    },

    'the name is the signed-in Registrar\'s own, so another Registrar who prints gets their own name, and vice versa' => function (TestRunner $t): void {
        try {
            $first = makeUser(User::ROLE_REGISTRAR);
            $second = makeUser(User::ROLE_REGISTRAR);
            Database::update('users', ['first_name' => 'Alma', 'last_name' => 'Firstregistrar'], ['id' => $first->id]);
            Database::update('users', ['first_name' => 'Berta', 'last_name' => 'Secondregistrar'], ['id' => $second->id]);
            $first = User::find($first->id);
            $second = User::find($second->id);

            $a = RegistrarSignerService::resolve($first);
            $b = RegistrarSignerService::resolve($second);
            $t->assertEquals('Alma Firstregistrar', $a['name']);
            $t->assertEquals('Berta Secondregistrar', $b['name']);
            $t->assertEquals('account', $a['source']);

            actingAs($first);
            $t->assertContains('Alma Firstregistrar', partial('registrar-signature', ['label' => 'Registrar']));
            $t->assertFalse(str_contains(partial('registrar-signature', ['label' => 'Registrar']), 'Berta'), 'Never the other Registrar');
            actingAs($second);
            $t->assertContains('Berta Secondregistrar', partial('registrar-signature', ['label' => 'Registrar']));
            $t->assertFalse(str_contains(partial('registrar-signature', ['label' => 'Registrar']), 'Alma'), 'And the other way round');
            actingAs(null);
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a student\'s own copy names the Registrar who enrolled them; with none to name, or for a teacher\'s print, the school Registrar from settings' => function (TestRunner $t): void {
        try {
            $e = enrolledStudent();
            Database::update('users', ['first_name' => 'Cora', 'last_name' => 'Enrollingregistrar'], ['id' => $e['registrar']->id]);
            $student = User::find($e['student']->id);

            $own = RegistrarSignerService::resolve($student, $e['enrollment_id']);
            $t->assertEquals('Cora Enrollingregistrar', $own['name'], 'The Registrar who confirmed the enrollment');
            $t->assertEquals('account', $own['source']);

            // Enrolled by someone who is not a registrar (the cashier's payment confirmed it), and the application reviewed by one: the reviewer.
            $cashier = makeUser(User::ROLE_CASHIER);
            Database::update('enrollments', ['enrolled_by' => $cashier->id], ['id' => $e['enrollment_id']]);
            $t->assertEquals('Cora Enrollingregistrar', RegistrarSignerService::resolve($student, $e['enrollment_id'])['name'], 'The registrar who reviewed the application');

            // No registrar anywhere: the settings name.
            Database::update('enrollment_applications', ['reviewed_by' => $cashier->id], ['id' => $e['application_id']]);
            $fallback = RegistrarSignerService::resolve($student, $e['enrollment_id']);
            $t->assertEquals('settings', $fallback['source']);
            $t->assertEquals(trim((string) SystemSetting::get('registrar_name', '')), $fallback['name']);

            // A teacher printing the class grade sheet has no enrollment: the settings name too.
            $teacher = makeUser(User::ROLE_TEACHER);
            $t->assertEquals('settings', RegistrarSignerService::resolve($teacher)['source']);
            $t->assertEquals('settings', RegistrarSignerService::resolve(null)['source']);
        } finally {
            deleteTestUsers();
        }
    },

    'the signature image is the signer\'s own: a Registrar\'s e-signature, or the settings image when it falls back' => function (TestRunner $t): void {
        try {
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            $original = (string) SystemSetting::get('registrar_name', '');
            $registrar = makeUser(User::ROLE_REGISTRAR);

            // The settings image is used for the fallback only.
            SettingsService::update($admin, 'school', registrarSettingsForm(['registrar_name' => 'Jury Grace Test-Nortez']), null, null, UploadedFile::forTesting(fixtureFile('png'), 'sign.png'));
            $teacher = makeUser(User::ROLE_TEACHER);
            actingAs($teacher);
            $html = partial('registrar-signature', ['label' => 'Received by the Registrar']);
            $t->assertContains('Jury Grace Test-Nortez', $html, 'A teacher prints the school Registrar');
            $t->assertContains('<img', $html, 'With the image from settings');
            $t->assertContains('Received by the Registrar', $html);

            // A Registrar with no e-signature of their own gets no image, not the settings one.
            actingAs($registrar);
            $html = partial('registrar-signature', ['label' => 'Registrar']);
            $t->assertFalse(str_contains($html, '<img'), 'The settings signature is never put under another person\'s name');

            // A Registrar with an e-signature of their own: it prints above their own name.
            $signed = makeUser(User::ROLE_REGISTRAR);
            $path = 'uploads/signatures/' . bin2hex(random_bytes(8)) . '.png';
            @mkdir(STORAGE_PATH . '/uploads/signatures', 0777, true);
            file_put_contents(STORAGE_PATH . '/' . $path, (string) file_get_contents(fixtureFile('png')));
            Database::update('users', ['signature_path' => $path], ['id' => $signed->id]);
            try {
                actingAs(User::find($signed->id));
                $t->assertContains('<img', partial('registrar-signature', ['label' => 'Registrar']), 'Their own e-signature');
            } finally {
                Database::update('users', ['signature_path' => null], ['id' => $signed->id]);
                @unlink(STORAGE_PATH . '/' . $path);
            }

            actingAs(null);
            SettingsService::update($admin, 'school', registrarSettingsForm(['registrar_name' => 'Jury Grace Test-Nortez', 'remove_registrar_signature' => '1']));
            $t->assertNull(SettingsService::registrarSignatureUri(), 'The settings image can be removed');
            SettingsService::update($admin, 'school', registrarSettingsForm(['registrar_name' => '']));
            actingAs($teacher);
            $t->assertContains('&nbsp;', partial('registrar-signature', ['label' => 'Registrar']), 'Blank when there is no name anywhere');
            actingAs(null);
            SettingsService::update($admin, 'school', registrarSettingsForm(['registrar_name' => $original]));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the certificate carries the dry seal notice, and the forms use the signature block' => function (TestRunner $t): void {
        $note = partial('dry-seal-note');
        $t->assertContains('Not valid without the school\'s dry seal', $note);
        $t->assertContains('not a legitimate copy', $note);
        $t->assertContains('Dry seal here', $note, 'A marked place for the seal');

        $cor = (string) file_get_contents(BASE_PATH . '/app/Views/print/cor.php');
        $t->assertContains("partial('dry-seal-note')", $cor, 'On the Certificate of Registration');
        $t->assertContains("'enrollmentId' => (int) \$enrollment['id']", $cor, 'So a student\'s own copy can name the registrar who enrolled them');
        $t->assertFalse(str_contains($cor, '>Registrar</div>'), 'No bare "Registrar" line any more');
        $t->assertContains("partial('registrar-signature'", (string) file_get_contents(BASE_PATH . '/app/Views/print/class-grades.php'));
        $t->assertContains('registrar_signature', (string) file_get_contents(BASE_PATH . '/app/Views/admin/settings/index.php'));
    },
];
