<?php
/**
 * The marriage certificate (docs/10): a document asked only of married students, required like the others, and kept in step with the profile
 * while an application is open.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\UploadedFile;
use App\Models\EnrollmentApplication as Application;
use App\Models\RequirementType;
use App\Models\User;
use App\Services\DocumentUploadService;
use App\Services\EnrollmentApplicationService as Applications;
use App\Services\StudentProfileService;

function marriedStudent(): User
{
    $student = makeStudent();
    StudentProfileService::update($student, array_merge(completeProfileInput(), ['civil_status' => 'Married']));

    return User::find($student->id);
}

function setCivilStatus(User $student, string $status): void
{
    StudentProfileService::update($student, array_merge(completeProfileInput(), ['civil_status' => $status]));
}

/** @return array<string,mixed>|null the application's marriage certificate row */
function marriageRow(int $applicationId): ?array
{
    foreach (Application::requirements($applicationId) as $requirement) {
        if ($requirement['code'] === 'marriage_certificate') {
            return $requirement;
        }
    }

    return null;
}

function uploadPdf(User $student, int $applicationId, int $requirementId): void
{
    DocumentUploadService::upload($student, $applicationId, $requirementId, UploadedFile::forTesting(fixtureFile('pdf'), 'scan.pdf'));
}

return [
    'only a married student\'s document list has the marriage certificate, required, for every student type' => function (TestRunner $t): void {
        try {
            $single = makeStudent();
            $id = Applications::createDraft($single, applicationInput('New'));
            $t->assertNull(marriageRow($id), 'A single student is not asked');

            foreach (['New', 'Old', 'Transferee', 'Summer'] as $type) {
                $married = marriedStudent();
                $id = Applications::createDraft($married, applicationInput($type));
                $row = marriageRow($id);
                $t->assertNotNull($row, "A married {$type} student is asked");
                $t->assertEquals(1, (int) $row['is_required'], 'And it is required');
                $t->assertEquals('NOT_SUBMITTED', $row['status']);
            }

            foreach (['Widowed', 'Separated'] as $status) {
                $student = makeStudent();
                setCivilStatus($student, $status);
                $t->assertNull(marriageRow(Applications::createDraft($student, applicationInput('New'))), "{$status} is not asked");
            }
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a married student cannot submit without it, and can with it' => function (TestRunner $t): void {
        try {
            $student = marriedStudent();
            $id = Applications::createDraft($student, applicationInput('New'));
            foreach (Application::requirements($id) as $requirement) {
                if ((int) $requirement['is_required'] === 1 && $requirement['code'] !== 'marriage_certificate') {
                    uploadPdf($student, $id, (int) $requirement['id']);
                }
            }
            $t->assertThrows(static fn () => Applications::submit($student, $id), 'Marriage');

            uploadPdf($student, $id, (int) marriageRow($id)['id']);
            Applications::submit($student, $id);
            $t->assertEquals('SUBMITTED', Application::find($id)['application_status']);
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the list follows the profile while the application is open, and an upload is never thrown away' => function (TestRunner $t): void {
        try {
            $student = makeStudent();
            $id = Applications::createDraft($student, applicationInput('New'));
            $t->assertNull(marriageRow($id));

            setCivilStatus($student, 'Married');
            $row = marriageRow($id);
            $t->assertNotNull($row, 'Becoming married adds it to an open application');
            $t->assertEquals(1, (int) $row['is_required']);
            $t->assertNotNull(Database::selectOne("SELECT id FROM audit_logs WHERE action = 'enrollment.requirement_added' AND target_id = ?", [$id]), 'Audited');

            setCivilStatus($student, 'Single');
            $t->assertNull(marriageRow($id), 'Changing back removes it when nothing was uploaded');

            setCivilStatus($student, 'Married');
            uploadPdf($student, $id, (int) marriageRow($id)['id']);
            setCivilStatus($student, 'Single');
            $t->assertNotNull(marriageRow($id), 'An uploaded certificate stays');

            // Saving the same status again adds nothing twice.
            setCivilStatus($student, 'Married');
            setCivilStatus($student, 'Married');
            $count = 0;
            foreach (Application::requirements($id) as $requirement) {
                $count += $requirement['code'] === 'marriage_certificate' ? 1 : 0;
            }
            $t->assertEquals(1, $count, 'Never duplicated');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'an approved application is never changed by a later change of civil status' => function (TestRunner $t): void {
        try {
            [$student, , $id] = approvedApplication();
            setCivilStatus($student, 'Married');
            $t->assertNull(marriageRow($id), 'An approved application keeps its list');
            $t->assertEquals('APPROVED', Application::find($id)['application_status']);
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the public page says who it is asked of, the forms say so when Married is chosen, and the type is set up' => function (TestRunner $t): void {
        $t->assertEquals('Married applicants', RequirementType::appliesLabel(['applies_to' => 'All', 'applies_to_civil_status' => 'Married']));
        $t->assertEquals('New and transferee students', RequirementType::appliesLabel(['applies_to' => 'New,Transferee', 'applies_to_civil_status' => null]), 'Other documents read as before');
        $t->assertEquals('All applicants', RequirementType::appliesLabel(['applies_to' => 'All']));

        $type = Database::selectOne("SELECT is_required, applies_to, applies_to_civil_status, is_active FROM requirement_types WHERE code = 'marriage_certificate'");
        $t->assertNotNull($type, 'The type exists');
        $t->assertEquals('Married', $type['applies_to_civil_status']);
        $t->assertEquals('All', $type['applies_to']);

        foreach (['student/profile', 'registrar/walk-in/create'] as $view) {
            $source = (string) file_get_contents(BASE_PATH . "/app/Views/{$view}.php");
            $t->assertContains('data-civil-note="Married"', $source, "{$view} has the note");
            $t->assertContains('marriage certificate', $source);
        }
        $js = (string) file_get_contents(BASE_PATH . '/public/assets/app.js');
        $t->assertContains('data-civil-note', $js, 'The script shows it when Married is chosen');
    },
];
