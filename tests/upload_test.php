<?php
/**
 * Requirement document uploads (docs/10, CLAUDE.md upload rules).
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\UploadedFile;
use App\Models\EnrollmentApplication as App;
use App\Services\DocumentUploadService as Uploads;
use App\Services\EnrollmentApplicationService as Applications;

/** @return array{0:App\Models\User,1:int,2:int} student, application id, a required requirement id */
function uploadFixture(): array
{
    $student = makeStudent();
    $id = Applications::createDraft($student, applicationInput());
    $requirementId = (int) Database::scalar(
        'SELECT id FROM enrollment_requirements WHERE application_id = ? AND is_required = 1 ORDER BY id LIMIT 1',
        [$id]
    );
    return [$student, $id, $requirementId];
}

return [
    'a genuine PDF is stored outside the web root under a random name' => function (TestRunner $t): void {
        try {
            [$student, $id, $requirementId] = uploadFixture();
            $source = fixtureFile('pdf');

            $fileId = Uploads::upload($student, $id, $requirementId, UploadedFile::forTesting($source, 'Maria Birth Cert.pdf'));
            $file = Database::selectOne('SELECT * FROM enrollment_requirement_files WHERE id = ?', [$fileId]);

            $t->assertTrue(str_starts_with($file['storage_path'], 'uploads/requirements/'), 'Stored under storage/uploads');
            $t->assertTrue(is_file(STORAGE_PATH . '/' . $file['storage_path']), 'The file exists on disk');
            $t->assertFalse(str_contains($file['stored_filename'], 'Maria'), 'The uploaded name is never the stored name');
            $t->assertTrue((bool) preg_match('/^[a-f0-9]{32}\.pdf$/', $file['stored_filename']));
            $t->assertEquals(hash_file('sha256', $source), $file['checksum']);
            $t->assertEquals('application/pdf', $file['mime_type']);
            $t->assertEquals(App::REQ_SUBMITTED, Database::scalar('SELECT status FROM enrollment_requirements WHERE id = ?', [$requirementId]));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a genuine PNG is accepted' => function (TestRunner $t): void {
        try {
            [$student, $id, $requirementId] = uploadFixture();
            $fileId = Uploads::upload($student, $id, $requirementId, UploadedFile::forTesting(fixtureFile('png'), 'photo.png'));
            $t->assertEquals('image/png', Database::scalar('SELECT mime_type FROM enrollment_requirement_files WHERE id = ?', [$fileId]));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a text file renamed to .pdf is rejected' => function (TestRunner $t): void {
        try {
            [$student, $id, $requirementId] = uploadFixture();
            $t->assertThrows(
                static fn () => Uploads::upload($student, $id, $requirementId, UploadedFile::forTesting(fixtureFile('fake_pdf'), 'birth.pdf')),
                'not a real PDF'
            );
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a PDF renamed to .png is rejected' => function (TestRunner $t): void {
        try {
            [$student, $id, $requirementId] = uploadFixture();
            $t->assertThrows(
                static fn () => Uploads::upload($student, $id, $requirementId, UploadedFile::forTesting(fixtureFile('pdf'), 'photo.png')),
                'not a real PNG'
            );
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a disallowed extension is rejected' => function (TestRunner $t): void {
        try {
            [$student, $id, $requirementId] = uploadFixture();
            $t->assertThrows(
                static fn () => Uploads::upload($student, $id, $requirementId, UploadedFile::forTesting(fixtureFile('pdf'), 'birth.php')),
                'Upload a'
            );
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a file over the size limit is rejected' => function (TestRunner $t): void {
        try {
            [$student, $id, $requirementId] = uploadFixture();
            $t->assertThrows(
                static fn () => Uploads::upload($student, $id, $requirementId, UploadedFile::forTesting(fixtureFile('big_pdf'), 'huge.pdf')),
                'The limit'
            );
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'an empty file and a failed upload are rejected' => function (TestRunner $t): void {
        try {
            [$student, $id, $requirementId] = uploadFixture();
            $t->assertThrows(
                static fn () => Uploads::upload($student, $id, $requirementId, UploadedFile::forTesting(fixtureFile('empty'), 'e.pdf')),
                'empty'
            );
            $t->assertThrows(
                static fn () => Uploads::upload($student, $id, $requirementId, UploadedFile::forTesting(fixtureFile('pdf'), 'p.pdf', UPLOAD_ERR_PARTIAL)),
                'partly uploaded'
            );
            $t->assertThrows(static fn () => Uploads::upload($student, $id, $requirementId, null), 'choose a file');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a replacement supersedes the previous file without deleting it' => function (TestRunner $t): void {
        try {
            [$student, $id, $requirementId] = uploadFixture();
            $first = Uploads::upload($student, $id, $requirementId, UploadedFile::forTesting(fixtureFile('pdf'), 'v1.pdf'));
            $second = Uploads::upload($student, $id, $requirementId, UploadedFile::forTesting(fixtureFile('pdf'), 'v2.pdf'));

            $rows = Database::select('SELECT id, is_current, storage_path FROM enrollment_requirement_files WHERE enrollment_requirement_id = ? ORDER BY id', [$requirementId]);
            $t->assertEquals(2, count($rows), 'Both files are kept');
            $t->assertEquals(0, (int) $rows[0]['is_current'], 'The first is no longer current');
            $t->assertEquals(1, (int) $rows[1]['is_current'], 'The second is current');
            $t->assertTrue(is_file(STORAGE_PATH . '/' . $rows[0]['storage_path']), 'The superseded file is still on disk');
            $t->assertTrue($first !== $second);
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'uploads are locked once the application is submitted' => function (TestRunner $t): void {
        try {
            [$student, $id, $requirementId] = uploadFixture();
            uploadAllRequired($student, $id);
            Applications::submit($student, $id);

            $t->assertThrows(
                static fn () => Uploads::upload($student, $id, $requirementId, UploadedFile::forTesting(fixtureFile('pdf'), 'late.pdf')),
                'cannot be changed while'
            );
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a verified document cannot be replaced' => function (TestRunner $t): void {
        try {
            [$student, $id, $requirementId] = uploadFixture();
            Uploads::upload($student, $id, $requirementId, UploadedFile::forTesting(fixtureFile('pdf'), 'ok.pdf'));
            Database::update('enrollment_requirements', ['status' => 'VERIFIED'], ['id' => $requirementId]);
            Database::update('enrollment_applications', ['application_status' => App::REQUIREMENTS_INCOMPLETE], ['id' => $id]);

            $t->assertThrows(
                static fn () => Uploads::upload($student, $id, $requirementId, UploadedFile::forTesting(fixtureFile('pdf'), 'again.pdf')),
                'already been verified'
            );
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the audit entry records the upload without the file name' => function (TestRunner $t): void {
        try {
            [$student, $id, $requirementId] = uploadFixture();
            Uploads::upload($student, $id, $requirementId, UploadedFile::forTesting(fixtureFile('pdf'), 'Maria-Santos-Birth.pdf'));

            $entry = (string) Database::scalar(
                "SELECT new_values FROM audit_logs WHERE action = 'requirement.uploaded' AND target_id = ? ORDER BY id DESC LIMIT 1",
                [$requirementId]
            );
            $t->assertTrue($entry !== '', 'An audit entry is written');
            $t->assertFalse(str_contains($entry, 'Maria'), 'The original file name (personal data) is not in the audit log');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },
];
