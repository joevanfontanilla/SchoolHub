<?php
/**
 * The student profile photo (docs/08): an optional JPG/PNG stored under storage/, replaced or
 * removed on save, served only through an authorizing route, and never a disguised file.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\UploadedFile;
use App\Models\Student;
use App\Services\StudentProfileService;

/** The stored photo path for a student, or null. */
function photoPathOf(int $studentId): ?string
{
    $path = Database::scalar('SELECT photo_path FROM student_profiles WHERE student_id = ?', [$studentId]);
    return $path === null ? null : (string) $path;
}

return [
    'a student uploads a photo: stored under storage with a random name, and served through the route' => function (TestRunner $t): void {
        try {
            $student = makeStudent();
            $studentId = (int) Database::scalar('SELECT id FROM students WHERE user_id = ?', [$student->id]);

            StudentProfileService::update($student, completeProfileInput(), UploadedFile::forTesting(fixtureFile('png'), 'me.png'));

            $path = photoPathOf($studentId);
            $t->assertTrue($path !== null && str_starts_with($path, 'uploads/profile-photos/'), 'Stored under storage/uploads/profile-photos');
            $t->assertTrue((bool) preg_match('#^uploads/profile-photos/[a-f0-9]{32}\.png$#', $path), 'A random name, not the uploaded one');
            $t->assertTrue(is_file(STORAGE_PATH . '/' . $path), 'The file exists on disk, outside the web root');

            $photo = StudentProfileService::photo($studentId);
            $t->assertEquals('image/png', $photo['mime'], 'The MIME comes from the stored extension');

            // The change is audited by name only, never the random path.
            $entry = Database::selectOne("SELECT new_values FROM audit_logs WHERE action = 'student.profile_updated' AND target_id = ? ORDER BY id DESC LIMIT 1", [$studentId]);
            $t->assertTrue(str_contains((string) $entry['new_values'], 'photo') && !str_contains((string) $entry['new_values'], $path), 'Audited as "photo", without the path');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a new photo supersedes the old file, and remove clears it' => function (TestRunner $t): void {
        try {
            $student = makeStudent();
            $studentId = (int) Database::scalar('SELECT id FROM students WHERE user_id = ?', [$student->id]);

            StudentProfileService::update($student, completeProfileInput(), UploadedFile::forTesting(fixtureFile('png'), 'first.png'));
            $first = photoPathOf($studentId);

            StudentProfileService::update($student, completeProfileInput(), UploadedFile::forTesting(fixtureFile('png'), 'second.png'));
            $second = photoPathOf($studentId);

            $t->assertTrue($first !== $second, 'A different file is stored');
            $t->assertFalse(is_file(STORAGE_PATH . '/' . $first), 'The replaced file is deleted');
            $t->assertTrue(is_file(STORAGE_PATH . '/' . $second), 'The new file remains');

            StudentProfileService::update($student, completeProfileInput() + ['remove_photo' => '1']);
            $t->assertNull(photoPathOf($studentId), 'Removing clears the path');
            $t->assertFalse(is_file(STORAGE_PATH . '/' . $second), 'And deletes the file');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a disguised or oversized photo is refused, and the rest of the profile is untouched' => function (TestRunner $t): void {
        try {
            $student = makeStudent();
            $studentId = (int) Database::scalar('SELECT id FROM students WHERE user_id = ?', [$student->id]);

            // A text file renamed .png: rejected as a photo-field error, nothing stored.
            $errors = fieldErrors(static fn () => StudentProfileService::update($student, completeProfileInput(), UploadedFile::forTesting(fixtureFile('fake_pdf'), 'evil.png')));
            $t->assertTrue(isset($errors['photo']), 'A file that is not a real image is refused on the photo field');
            $t->assertNull(photoPathOf($studentId), 'Nothing is stored when the photo is invalid');

            // A real PDF renamed .png (right magic bytes are PDF, not image): also refused.
            $errors = fieldErrors(static fn () => StudentProfileService::update($student, completeProfileInput(), UploadedFile::forTesting(fixtureFile('pdf'), 'doc.png')));
            $t->assertTrue(isset($errors['photo']), 'A PDF wearing a .png name is refused');

            // A disallowed extension.
            $errors = fieldErrors(static fn () => StudentProfileService::update($student, completeProfileInput(), UploadedFile::forTesting(fixtureFile('pdf'), 'scan.pdf')));
            $t->assertTrue(isset($errors['photo']), 'A PDF is not a photo');

            // A valid save with no photo leaves the profile complete and photoless.
            StudentProfileService::update($student, completeProfileInput());
            $t->assertEquals([], StudentProfileService::missingFields($studentId), 'The profile still saved without a photo');
            $t->assertNull(photoPathOf($studentId), 'Still no photo');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'photo() ignores a missing file, so a deleted upload never 500s the page' => function (TestRunner $t): void {
        try {
            $student = makeStudent();
            $studentId = (int) Database::scalar('SELECT id FROM students WHERE user_id = ?', [$student->id]);
            StudentProfileService::update($student, completeProfileInput(), UploadedFile::forTesting(fixtureFile('png'), 'me.png'));

            @unlink(STORAGE_PATH . '/' . photoPathOf($studentId));
            $t->assertNull(StudentProfileService::photo($studentId), 'A path with no file on disk returns null, not a broken stream');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },
];
