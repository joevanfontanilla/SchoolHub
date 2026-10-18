<?php
/**
 * Walk-in enrollment entry points for the registrar (docs/09): the Enroll for a term button on a student's record and the dashboard shortcut.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Models\User;
use App\Services\WalkInService;

return [
    'a student enrolled for the open term cannot be enrolled again from the record, and the reason is given' => function (TestRunner $t): void {
        try {
            $e = enrolledStudent();
            $option = WalkInService::enrollOption($e['student_id']);
            $t->assertFalse($option['can'], 'Already enrolled');
            $t->assertTrue($option['reason'] !== null && $option['reason'] !== '', 'A reason is shown');
        } finally {
            deleteTestUsers();
        }
    },

    'a student with an open application for the term is not offered the button, and one without is' => function (TestRunner $t): void {
        try {
            $e = enrolledStudent();
            // Withdraw the enrollment so only the application question remains.
            Database::update('enrollments', ['status' => 'WITHDRAWN'], ['id' => $e['enrollment_id']]);
            Database::update('enrollment_applications', ['application_status' => 'DRAFT'], ['id' => $e['application_id']]);
            $blocked = WalkInService::enrollOption($e['student_id']);
            $t->assertFalse($blocked['can'], 'An open application blocks it');

            Database::update('enrollment_applications', ['application_status' => 'CANCELLED'], ['id' => $e['application_id']]);
            $free = WalkInService::enrollOption($e['student_id']);
            $t->assertTrue($free['can'], 'Nothing in the way: the button shows');
            $t->assertTrue(count($free['terms']) >= 1, 'With the terms it can be used for');
        } finally {
            deleteTestUsers();
        }
    },

    'a disabled account cannot be started from the record' => function (TestRunner $t): void {
        try {
            $e = enrolledStudent();
            Database::update('enrollments', ['status' => 'WITHDRAWN'], ['id' => $e['enrollment_id']]);
            Database::update('users', ['status' => User::STATUS_DISABLED], ['id' => $e['student']->id]);
            $option = WalkInService::enrollOption($e['student_id']);
            $t->assertFalse($option['can']);
            $t->assertContains('account', (string) $option['reason']);
        } finally {
            deleteTestUsers();
        }
    },

    'the button and the dashboard shortcut are in the views, and the rule is written down' => function (TestRunner $t): void {
        $show = (string) file_get_contents(BASE_PATH . '/app/Views/registrar/students/show.php');
        $t->assertContains('Enroll for a term', $show);
        $t->assertContains("'/registrar/walk-in/student/'", $show);
        $dash = (string) file_get_contents(BASE_PATH . '/app/Views/dashboards/registrar.php');
        $t->assertContains('Walk-in enrollment', $dash);
        $t->assertContains("'/registrar/walk-in'", $dash);
        $t->assertContains('Enroll for a term, from the record', (string) file_get_contents(BASE_PATH . '/docs/09_ENROLLMENT_SYSTEM.md'));
    },
];
