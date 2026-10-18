<?php
/**
 * Student types (docs/09): New student, Old student, Transferee, Summer, and nothing else.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Models\EnrollmentApplication as Application;
use App\Models\Student;
use App\Services\EnrollmentApplicationService as Applications;

return [
    'the types are New, Old, Transferee and Summer, each with a plain label' => function (TestRunner $t): void {
        $t->assertEquals(['New', 'Old', 'Transferee', 'Summer'], Student::TYPES);
        $t->assertEquals(['New' => 'New student', 'Old' => 'Old student', 'Transferee' => 'Transferee', 'Summer' => 'Summer'], Student::TYPE_LABELS);
        $t->assertEquals(Student::TYPES, array_keys(Student::TYPE_LABELS), 'The list and the labels never drift apart');
    },

    'an Old or a Summer application is accepted without a previous school, and is asked for no admission documents' => function (TestRunner $t): void {
        try {
            foreach (['Old', 'Summer'] as $type) {
                $student = makeStudent();
                $id = Applications::createDraft($student, applicationInput($type));
                $t->assertEquals($type, Application::find($id)['student_type']);
                $codes = array_column(Application::requirements($id), 'code');
                $t->assertFalse(in_array('id_photo', $codes, true), "{$type} is asked for no photo");
                $t->assertEquals([], $codes, "{$type} is already admitted, so no admission documents");
                $t->assertFalse(in_array('transcript', $codes, true), "{$type} needs no transcript");
                $t->assertFalse(in_array('report_card', $codes, true), "{$type} needs no report card");
            }
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'Continuing and Returning are no longer accepted' => function (TestRunner $t): void {
        try {
            foreach (['Continuing', 'Returning', 'Whatever'] as $type) {
                $student = makeStudent();
                $errors = fieldErrors(static fn () => Applications::createDraft($student, applicationInput($type)));
                $t->assertTrue(isset($errors['student_type']), "{$type} is refused");
            }
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the migration turns Continuing and Returning into Old, and the two forms offer only the four types' => function (TestRunner $t): void {
        $sql = (string) file_get_contents(BASE_PATH . '/database/migrations/040_student_types_old_summer.sql');
        $up = explode('-- @down', $sql)[0];
        $t->assertContains("UPDATE enrollment_applications SET student_type = 'Old' WHERE student_type IN ('Continuing', 'Returning')", $up);
        $t->assertContains("UPDATE students SET student_type = 'Old' WHERE student_type IN ('Continuing', 'Returning')", $up);
        $t->assertEquals(0, (int) Database::scalar("SELECT COUNT(*) FROM enrollment_applications WHERE student_type IN ('Continuing', 'Returning')"), 'None left after the migration');
        $t->assertEquals(0, (int) Database::scalar("SELECT COUNT(*) FROM students WHERE student_type IN ('Continuing', 'Returning')"));

        foreach (['student/enrollment/apply', 'registrar/walk-in/create', 'registrar/walk-in/existing'] as $view) {
            $source = (string) file_get_contents(BASE_PATH . "/app/Views/{$view}.php");
            $t->assertContains('Student::TYPE_LABELS', $source, "{$view} offers the labelled types");
            $t->assertFalse(stripos($source, 'continuing') !== false || stripos($source, 'returning') !== false, "{$view} no longer mentions the old types");
        }
    },
];
