<?php
/**
 * Who may reach an application and its documents (docs/17 section 10).
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\UploadedFile;
use App\Models\User;
use App\Services\DocumentAccessPolicy as Policy;
use App\Services\DocumentUploadService as Uploads;
use App\Services\EnrollmentApplicationService as Applications;

/** @return array{0:User,1:int,2:int,3:int} owner, application id, requirement id, file id */
function accessFixture(): array
{
    $owner = makeStudent();
    $id = Applications::createDraft($owner, applicationInput());
    $requirementId = (int) App\Core\Database::scalar('SELECT id FROM enrollment_requirements WHERE application_id = ? LIMIT 1', [$id]);
    $fileId = Uploads::upload($owner, $id, $requirementId, UploadedFile::forTesting(fixtureFile('pdf'), 'doc.pdf'));
    return [$owner, $id, $requirementId, $fileId];
}

return [
    'the owner may open their own document' => function (TestRunner $t): void {
        try {
            [$owner, , , $fileId] = accessFixture();
            [$decision] = Policy::decide($owner, $fileId);
            $t->assertEquals(Policy::ALLOW, $decision);
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'another student gets not-found for the application, the upload route, and the document' => function (TestRunner $t): void {
        try {
            [, $id, $requirementId, $fileId] = accessFixture();
            $intruder = makeStudent();

            $t->assertEquals(404, statusOf(static fn () => Applications::ownedApplication($intruder, $id)));
            $t->assertEquals(404, statusOf(static fn () => Uploads::upload($intruder, $id, $requirementId, UploadedFile::forTesting(fixtureFile('pdf'), 'x.pdf'))));
            $t->assertEquals(404, statusOf(static fn () => Applications::submit($intruder, $id)));

            [$decision] = Policy::decide($intruder, $fileId);
            $t->assertEquals(Policy::NOT_FOUND, $decision, 'Another student\'s document must look like it does not exist');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'teachers and cashiers are always refused' => function (TestRunner $t): void {
        try {
            [, , , $fileId] = accessFixture();

            foreach ([User::ROLE_TEACHER, User::ROLE_CASHIER] as $role) {
                [$decision] = Policy::decide(makeUser($role), $fileId);
                $t->assertEquals(Policy::FORBIDDEN, $decision, "{$role} must never open student documents");
            }
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the registrar and Super Admin may open documents' => function (TestRunner $t): void {
        try {
            [, , , $fileId] = accessFixture();

            foreach ([User::ROLE_REGISTRAR, User::ROLE_SUPER_ADMIN] as $role) {
                [$decision, $file] = Policy::decide(makeUser($role), $fileId);
                $t->assertEquals(Policy::ALLOW, $decision, "{$role} may open documents");
                $t->assertNotNull($file);
            }
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a staff role gets not-found for a file id that does not exist' => function (TestRunner $t): void {
        try {
            [$decision] = Policy::decide(makeUser(User::ROLE_REGISTRAR), 999999999);
            $t->assertEquals(Policy::NOT_FOUND, $decision);
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },
];
