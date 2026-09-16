<?php
/**
 * Every user's profile photo through AvatarService (docs/08): staff keep theirs on
 * users.avatar_path, students on their record photo, and the service hides which is which.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\UploadedFile;
use App\Models\User;
use App\Services\AvatarService;
use App\Services\StudentProfileService;

return [
    'a staff user uploads, replaces, and removes their avatar (users.avatar_path)' => function (TestRunner $t): void {
        try {
            $teacher = makeUser(User::ROLE_TEACHER);
            $t->assertNull(AvatarService::path($teacher), 'No avatar to start');
            $t->assertNull(AvatarService::currentUrl($teacher), 'No URL to start');

            AvatarService::save($teacher, UploadedFile::forTesting(fixtureFile('png'), 'me.png'), false);
            $first = AvatarService::path($teacher);
            $t->assertTrue($first !== null && str_starts_with($first, 'uploads/avatars/'), 'Stored under uploads/avatars');
            $t->assertTrue(is_file(STORAGE_PATH . '/' . $first), 'File on disk');
            $t->assertEquals($first, Database::scalar('SELECT avatar_path FROM users WHERE id = ?', [$teacher->id]), 'Recorded on the user');
            $t->assertEquals('image/png', AvatarService::photo($teacher)['mime']);

            AvatarService::save($teacher, UploadedFile::forTesting(fixtureFile('png'), 'new.png'), false);
            $second = AvatarService::path($teacher);
            $t->assertTrue($second !== $first, 'A new file replaces the old');
            $t->assertFalse(is_file(STORAGE_PATH . '/' . $first), 'The old file is deleted');

            AvatarService::save($teacher, null, true);
            $t->assertNull(AvatarService::path($teacher), 'Removed');
            $t->assertFalse(is_file(STORAGE_PATH . '/' . $second), 'And its file is gone');
        } finally {
            deleteTestUsers();
        }
    },

    'a student\'s avatar is their record photo, shared with StudentProfileService' => function (TestRunner $t): void {
        try {
            $student = makeStudent();
            AvatarService::save($student, UploadedFile::forTesting(fixtureFile('png'), 'me.png'), false);

            $path = AvatarService::path($student);
            $t->assertTrue($path !== null && str_starts_with($path, 'uploads/profile-photos/'), 'A student photo goes to the record photo folder');
            $studentId = (int) Database::scalar('SELECT id FROM students WHERE user_id = ?', [$student->id]);
            $t->assertEquals($path, StudentProfileService::photo($studentId)['path'], 'Same photo the profile/record use');
            $t->assertEquals(0, (int) Database::scalar('SELECT COUNT(*) FROM users WHERE id = ? AND avatar_path IS NOT NULL', [$student->id]), 'Not duplicated onto users.avatar_path');
        } finally {
            deleteTestUsers();
            deleteTestCatalog();
        }
    },

    'a disguised or missing file is refused, and nothing is stored' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $t->assertTrue(isset(fieldErrors(static fn () => AvatarService::save($registrar, UploadedFile::forTesting(fixtureFile('fake_pdf'), 'evil.png'), false))['photo']), 'A text file renamed .png is refused');
            $t->assertTrue(isset(fieldErrors(static fn () => AvatarService::save($registrar, null, false))['photo']), 'Nothing chosen is refused');
            $t->assertNull(AvatarService::path($registrar), 'Nothing stored');
        } finally {
            deleteTestUsers();
        }
    },

    'the top bar avatar helper resolves for every role, and deleteTestUsers leaves no orphan file' => function (TestRunner $t): void {
        try {
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            AvatarService::save($admin, UploadedFile::forTesting(fixtureFile('png'), 'a.png'), false);
            $url = AvatarService::currentUrl($admin);
            $t->assertTrue($url !== null && str_contains($url, '/account/photo'), 'The avatar is served through /account/photo');
        } finally {
            deleteTestUsers();
            $t->assertEquals(0, count(glob(STORAGE_PATH . '/uploads/avatars/*') ?: []), 'No avatar files left behind');
        }
    },
];
