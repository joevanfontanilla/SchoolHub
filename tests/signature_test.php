<?php
/**
 * E-signatures (docs/08): uploaded or drawn, private, embedded in the printouts, removable.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\Router;
use App\Core\UploadedFile;
use App\Models\User;
use App\Services\SignatureService;

/** A valid grayscale PNG of the given size, built by hand (the test machine has no image library). */
function pngBytes(int $width, int $height): string
{
    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    $rows = '';
    for ($y = 0; $y < $height; $y++) {
        $rows .= "\x00" . str_repeat("\xC0", $width);
    }

    return "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 0, 0, 0, 0))
        . $chunk('IDAT', gzcompress($rows))
        . $chunk('IEND', '');
}

function drawnUrl(int $width = 120, int $height = 40): string
{
    return 'data:image/png;base64,' . base64_encode(pngBytes($width, $height));
}

/** Remove every signature a test made, files included, before the users go. */
function dropSignatures(User ...$users): void
{
    foreach ($users as $user) {
        if (SignatureService::has($user->id)) {
            SignatureService::remove($user, $user->id);
        }
    }
}

return [
    'a drawn signature is saved privately, shown as a data URI, and replaced without leaving the old file' => function (TestRunner $t): void {
        $user = makeUser(User::ROLE_CASHIER);
        try {
            $t->assertFalse(SignatureService::has($user->id), 'None at first');
            $t->assertNull(SignatureService::dataUri($user->id));

            SignatureService::save($user, null, drawnUrl(), true);
            $first = SignatureService::path($user->id);
            $t->assertTrue($first !== null && str_starts_with($first, 'uploads/signatures/') && str_ends_with($first, '.png'), 'Stored under storage/uploads/signatures');
            $t->assertTrue(is_file(STORAGE_PATH . '/' . $first));
            $t->assertTrue(str_starts_with((string) SignatureService::dataUri($user->id), 'data:image/png;base64,'), 'Embedded as a data URI');
            $t->assertContains('/account/signature?v=', (string) SignatureService::currentUrl($user), 'The owner previews it through the account route');

            SignatureService::save($user, null, drawnUrl(200, 60), true);
            $second = SignatureService::path($user->id);
            $t->assertTrue($second !== $first, 'A new file');
            $t->assertFalse(is_file(STORAGE_PATH . '/' . $first), 'The old file is deleted');
            $t->assertEquals(2, (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'user.signature_updated' AND user_id = ?", [$user->id]), 'Both saves are audited');
            $newValues = (string) Database::scalar("SELECT new_values FROM audit_logs WHERE action = 'user.signature_updated' AND user_id = ? ORDER BY id DESC LIMIT 1", [$user->id]);
            $t->assertFalse(str_contains($newValues, 'base64') || str_contains($newValues, 'signatures/'), 'The audit entry holds no image or path');
        } finally {
            dropSignatures($user);
            deleteTestUsers();
        }
    },

    'an uploaded PNG or JPG is accepted; a fake image, a PDF, or a missing agreement is refused' => function (TestRunner $t): void {
        $user = makeUser(User::ROLE_TEACHER);
        try {
            $errors = fieldErrors(static fn () => SignatureService::save($user, UploadedFile::forTesting(fixtureFile('png'), 'sig.png'), '', false));
            $t->assertTrue(isset($errors['agree']), 'The agreement is required');
            $t->assertFalse(SignatureService::has($user->id), 'Nothing was saved');

            $errors = fieldErrors(static fn () => SignatureService::save($user, null, '', true));
            $t->assertTrue(isset($errors['signature']), 'Something must be drawn or uploaded');

            $errors = fieldErrors(static fn () => SignatureService::save($user, UploadedFile::forTesting(fixtureFile('fake_pdf'), 'sig.png'), '', true));
            $t->assertTrue(isset($errors['signature']), 'A text file renamed .png is refused');
            $errors = fieldErrors(static fn () => SignatureService::save($user, UploadedFile::forTesting(fixtureFile('pdf'), 'sig.pdf'), '', true));
            $t->assertTrue(isset($errors['signature']), 'A PDF is refused');

            SignatureService::save($user, UploadedFile::forTesting(fixtureFile('png'), 'sig.png'), '', true);
            $t->assertTrue(SignatureService::has($user->id), 'A real PNG is accepted');
        } finally {
            dropSignatures($user);
            deleteTestUsers();
        }
    },

    'a drawing that is not a small real PNG is refused' => function (TestRunner $t): void {
        $user = makeUser(User::ROLE_REGISTRAR);
        try {
            $bad = [
                'not a data url'      => 'hello',
                'a jpeg data url'     => 'data:image/jpeg;base64,' . base64_encode("\xFF\xD8\xFF\xE0 not really"),
                'png header, no body' => 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\nbroken"),
                'invalid base64'      => 'data:image/png;base64,@@@@',
                'too many pixels'     => drawnUrl(2100, 20),
                'too small'           => drawnUrl(5, 5),
            ];
            foreach ($bad as $label => $url) {
                $errors = fieldErrors(static fn () => SignatureService::save($user, null, $url, true));
                $t->assertTrue(isset($errors['signature']), "Refused: {$label}");
            }
            $t->assertFalse(SignatureService::has($user->id), 'Nothing was saved');
        } finally {
            dropSignatures($user);
            deleteTestUsers();
        }
    },

    'the owner removes their own signature; a Super Admin can remove anyone\'s; nobody else can' => function (TestRunner $t): void {
        $owner = makeUser(User::ROLE_CASHIER);
        $other = makeUser(User::ROLE_TEACHER);
        $admin = makeUser(User::ROLE_SUPER_ADMIN);
        try {
            SignatureService::save($owner, null, drawnUrl(), true);
            $path = SignatureService::path($owner->id);

            $t->assertEquals(403, statusOf(static fn () => SignatureService::remove($other, $owner->id)), 'Another user cannot');
            $t->assertTrue(SignatureService::has($owner->id), 'Still there');

            SignatureService::remove($admin, $owner->id);
            $t->assertFalse(SignatureService::has($owner->id), 'The administrator removed it');
            $t->assertFalse(is_file(STORAGE_PATH . '/' . $path), 'The file is gone');
            $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'user.signature_removed' AND target_id = ?", [$owner->id]), 'Removal is audited');

            SignatureService::save($owner, null, drawnUrl(), true);
            SignatureService::remove($owner, $owner->id);
            $t->assertFalse(SignatureService::has($owner->id), 'The owner removed their own');
        } finally {
            dropSignatures($owner, $other);
            deleteTestUsers();
        }
    },

    'a printout shows the preparer\'s signature above their name, and the blank line when they have none' => function (TestRunner $t): void {
        $signed = makeUser(User::ROLE_CASHIER);
        $unsigned = makeUser(User::ROLE_CASHIER);
        try {
            SignatureService::save($signed, null, drawnUrl(), true);

            $with = partial('signature-image', ['userId' => $signed->id, 'name' => 'Test Cashier']);
            $t->assertContains('<img src="data:image/png;base64,', $with, 'The image is embedded');
            $t->assertContains('alt="Signature of Test Cashier"', $with);
            $t->assertEquals('', trim(partial('signature-image', ['userId' => $unsigned->id, 'name' => 'x'])), 'Nothing for an unsigned user');
            $t->assertEquals('', trim(partial('signature-image', ['userId' => null, 'name' => 'x'])), 'Nothing without a user');

            $block = partial('cashier/prepared-by', ['user' => $signed]);
            $t->assertContains('data:image/png;base64,', $block, 'The Prepared by block carries it');
            $t->assertFalse(str_contains(partial('cashier/prepared-by', ['user' => $unsigned]), 'data:image'), 'And not for an unsigned user');

            foreach (['print/receipt', 'print/voucher', 'print/collections', 'print/day-close', 'print/class-grades'] as $view) {
                $t->assertContains("partial('signature-image'", (string) file_get_contents(BASE_PATH . "/app/Views/{$view}.php"), "{$view} prints the signature");
            }
        } finally {
            dropSignatures($signed, $unsigned);
            deleteTestUsers();
        }
    },

    'the signature routes need a sign-in, and removing someone else\'s is the administrator\'s alone' => function (TestRunner $t): void {
        $router = new Router();
        require BASE_PATH . '/routes/web.php';

        $found = [];
        foreach ($router->routes() as $route) {
            if (str_starts_with($route['pattern'], '/account/signature')) {
                $found[] = $route['pattern'];
                $t->assertTrue(in_array('auth', $route['middleware'], true), $route['pattern'] . ' needs a sign-in');
            }
            if ($route['pattern'] === '/admin/users/{id}/signature/remove') {
                $found[] = $route['pattern'];
                $t->assertEquals('POST', $route['method']);
                $t->assertTrue(in_array('role:' . User::ROLE_SUPER_ADMIN, $route['middleware'], true) && in_array('can:users.manage', $route['middleware'], true), 'The administrator with users.manage');
            }
        }
        $t->assertEquals(4, count($found), 'Save, remove, and preview for the owner, and the administrator\'s removal');
    },
];
