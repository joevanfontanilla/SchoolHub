<?php
/**
 * The Certificate of Registration (docs/08): assembled only for an enrolled term, from the
 * student's enrollment, enlisted subjects, and assessment.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Services\RegistrationDocumentService;

return [
    'a student with no enrollment has no certificate' => function (TestRunner $t): void {
        try {
            $student = makeStudent();
            $studentId = (int) Database::scalar('SELECT id FROM students WHERE user_id = ?', [$student->id]);
            $t->assertNull(RegistrationDocumentService::certificate($studentId), 'No enrolled term, no COR');
        } finally {
            deleteTestUsers();
            deleteTestCatalog();
        }
    },

    'an enrolled student gets a certificate for that term, with the right structure' => function (TestRunner $t): void {
        try {
            $enrolled = enrolledStudent();
            $studentId = (int) $enrolled['student_id'];

            $cor = RegistrationDocumentService::certificate($studentId);
            $t->assertTrue($cor !== null, 'The enrolled student has a COR');
            $t->assertEquals((int) $enrolled['section_id'], (int) $cor['enrollment']['section_id'], 'For the enrolled section');
            foreach (['enrollment', 'classes', 'units', 'assessment', 'items', 'adjustments', 'payments'] as $key) {
                $t->assertTrue(array_key_exists($key, $cor), "The COR includes {$key}");
            }
            $t->assertEquals(array_sum(array_map(static fn ($c) => (float) $c['units'], $cor['classes'])), $cor['units'], 'Units are the sum of enlisted subjects');

            // Asking for a term the student is not enrolled in falls back to their latest enrolled term.
            $latest = RegistrationDocumentService::certificate($studentId, 999999);
            $t->assertEquals((int) $cor['enrollment']['id'], (int) $latest['enrollment']['id'], 'An unknown term id falls back to the latest enrolled term');
        } finally {
            deleteTestUsers();
            deleteTestCatalog();
        }
    },
];
