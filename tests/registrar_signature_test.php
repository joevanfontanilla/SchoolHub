<?php
/**
 * The Registrar's name on the forms the registrar signs (docs/15): from settings, the same whoever prints, with an optional signature image.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\UploadedFile;
use App\Models\SystemSetting;
use App\Models\User;
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
    'the Registrar\'s name is a school setting, set up with a default, and changed by the administrator only' => function (TestRunner $t): void {
        try {
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            $original = (string) SystemSetting::get('registrar_name', '');
            $t->assertTrue($original !== '', 'A name is set up for the registrar');

            SettingsService::update($admin, 'school', registrarSettingsForm(['registrar_name' => 'Test Registrar Name']));
            $t->assertEquals('Test Registrar Name', SystemSetting::get('registrar_name', ''));
            $t->assertEquals(403, statusOf(static fn () => SettingsService::update(makeUser(User::ROLE_REGISTRAR), 'school', registrarSettingsForm(['registrar_name' => 'Someone Else']))), 'Not even the registrar changes it');
            $t->assertTrue(isset(fieldErrors(static fn () => SettingsService::update($admin, 'school', registrarSettingsForm(['registrar_name' => str_repeat('x', 101)])))['registrar_name']), 'Within 100 characters');

            SettingsService::update($admin, 'school', registrarSettingsForm(['registrar_name' => $original]));
        } finally {
            deleteTestUsers();
        }
    },

    'the signature block prints the name on the line, with the signature above it when one is uploaded, and the same for whoever prints' => function (TestRunner $t): void {
        try {
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            $original = (string) SystemSetting::get('registrar_name', '');
            SettingsService::update($admin, 'school', registrarSettingsForm(['registrar_name' => 'Jury Grace Test-Nortez']));

            foreach ([User::ROLE_REGISTRAR, User::ROLE_STUDENT, User::ROLE_TEACHER] as $role) {
                actingAs(makeUser($role));
                $html = partial('registrar-signature', ['label' => 'Registrar']);
                $t->assertContains('Jury Grace Test-Nortez', $html, "Printed when a {$role} prints it");
                $t->assertContains('Registrar', $html);
                $t->assertFalse(str_contains($html, '<img'), 'No signature image until one is uploaded');
            }
            actingAs(null);

            SettingsService::update($admin, 'school', registrarSettingsForm(['registrar_name' => 'Jury Grace Test-Nortez']), null, null, UploadedFile::forTesting(fixtureFile('png'), 'sign.png'));
            $t->assertContains('data:image/png;base64,', (string) SettingsService::registrarSignatureUri());
            $html = partial('registrar-signature', ['label' => 'Received by the Registrar']);
            $t->assertContains('<img', $html, 'The signature image goes above the name');
            $t->assertContains('Received by the Registrar', $html, 'With the label the form gives it');

            $path = (string) SystemSetting::get('registrar_signature', '');
            SettingsService::update($admin, 'school', registrarSettingsForm(['registrar_name' => 'Jury Grace Test-Nortez', 'remove_registrar_signature' => '1']));
            $t->assertNull(SettingsService::registrarSignatureUri(), 'It can be removed');
            $t->assertFalse($path !== '' && is_file(STORAGE_PATH . '/' . $path), 'And the file goes with it');

            // With no name set the line is blank, as it was.
            SettingsService::update($admin, 'school', registrarSettingsForm(['registrar_name' => '']));
            $t->assertContains('&nbsp;', partial('registrar-signature', ['label' => 'Registrar']), 'Blank without a name');

            SettingsService::update($admin, 'school', registrarSettingsForm(['registrar_name' => $original]));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the Certificate of Registration and the class grade sheet use it, and the settings page takes the signature' => function (TestRunner $t): void {
        foreach (['print/cor', 'print/class-grades'] as $view) {
            $source = (string) file_get_contents(BASE_PATH . "/app/Views/{$view}.php");
            $t->assertContains("partial('registrar-signature'", $source, "{$view} prints the registrar's name");
        }
        $cor = (string) file_get_contents(BASE_PATH . '/app/Views/print/cor.php');
        $t->assertFalse(str_contains($cor, '>Registrar</div>'), 'No bare "Registrar" line any more');
        $t->assertContains('registrar_signature', (string) file_get_contents(BASE_PATH . '/app/Views/admin/settings/index.php'));
    },
];
