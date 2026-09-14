<?php
/**
 * Student self-registration (docs/09, docs/07).
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Models\Student;
use App\Models\User;
use App\Services\RegistrationService;

/** @return array<string,mixed> */
function registrationInput(array $overrides = []): array
{
    $tag = bin2hex(random_bytes(3));
    return array_merge([
        'first_name'            => 'Juan',
        'last_name'             => 'Dela Cruz',
        'email'                 => TEST_PREFIX . $tag . '@test.local',
        'password'              => 'Enroll2026',
        'password_confirmation' => 'Enroll2026',
        'privacy_consent'       => '1',
        RegistrationService::HONEYPOT => '',
    ], $overrides);
}

/** A test-reserved IP (TEST-NET-3), so no real sign-up limit is affected. */
function registrationIp(): string
{
    $ip = '203.0.113.' . random_int(1, 254);
    $_SERVER['REMOTE_ADDR'] = $ip;
    return $ip;
}

return [
    'a valid sign-up creates an active student applicant with consent recorded' => function (TestRunner $t): void {
        try {
            $user = RegistrationService::register(registrationInput(), registrationIp(), 'test');

            $t->assertEquals(User::ROLE_STUDENT, $user->role_name);
            $t->assertEquals(User::STATUS_ACTIVE, $user->status, 'New students are Active immediately (docs/07)');

            $student = Student::findByUserId($user->id);
            $t->assertNotNull($student, 'A student record must be created');
            $t->assertEquals(Student::STATUS_APPLICANT, $student['status']);
            $t->assertNull($student['student_number'], 'The student number is issued only at enrollment confirmation');

            $consent = Database::scalar('SELECT privacy_consented_at FROM users WHERE id = ?', [$user->id]);
            $t->assertNotNull($consent, 'Privacy consent must be timestamped');
            $t->assertTrue(App\Core\Auth::check(), 'The new student is signed in');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a duplicate email is rejected' => function (TestRunner $t): void {
        try {
            $input = registrationInput();
            RegistrationService::register($input, registrationIp(), 'test');

            $t->assertThrows(
                static fn () => RegistrationService::register($input, registrationIp(), 'test'),
                'Please correct'
            );
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a weak password is rejected' => function (TestRunner $t): void {
        try {
            $caught = null;
            try {
                RegistrationService::register(
                    registrationInput(['password' => 'password', 'password_confirmation' => 'password']),
                    registrationIp(),
                    'test'
                );
            } catch (ValidationException $e) {
                $caught = $e;
            }
            $t->assertNotNull($caught, 'Registration should fail');
            $t->assertTrue(isset($caught->errors()['password']), 'The error belongs to the password field');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'sign-up without privacy consent is rejected' => function (TestRunner $t): void {
        try {
            $caught = null;
            try {
                RegistrationService::register(registrationInput(['privacy_consent' => '']), registrationIp(), 'test');
            } catch (ValidationException $e) {
                $caught = $e;
            }
            $t->assertNotNull($caught);
            $t->assertTrue(isset($caught->errors()['privacy_consent']), 'Consent is required (RA 10173)');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a filled honeypot field blocks the sign-up and creates nothing' => function (TestRunner $t): void {
        try {
            $input = registrationInput([RegistrationService::HONEYPOT => 'http://spam.example']);
            $t->assertThrows(static fn () => RegistrationService::register($input, registrationIp(), 'test'));

            $count = (int) Database::scalar('SELECT COUNT(*) FROM users WHERE email = ?', [$input['email']]);
            $t->assertEquals(0, $count, 'No account may be created');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'one network is limited to five sign-ups an hour' => function (TestRunner $t): void {
        try {
            $ip = registrationIp();
            for ($i = 0; $i < 5; $i++) {
                RegistrationService::register(registrationInput(), $ip, 'test');
                actingAs(null);
            }

            $status = null;
            try {
                RegistrationService::register(registrationInput(), $ip, 'test');
            } catch (HttpException $e) {
                $status = $e->status();
            }
            $t->assertEquals(429, $status, 'The sixth sign-up from the same IP must be refused');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the generated username comes from the email and never collides' => function (TestRunner $t): void {
        try {
            $email = TEST_PREFIX . 'same@test.local';
            $first = RegistrationService::register(registrationInput(['email' => $email]), registrationIp(), 'test');
            actingAs(null);
            $second = RegistrationService::register(
                registrationInput(['email' => TEST_PREFIX . 'same@other.local']),
                registrationIp(),
                'test'
            );

            $t->assertEquals(TEST_PREFIX . 'same', $first->username);
            $t->assertTrue($second->username !== $first->username, 'A taken username gets a suffix');
            $t->assertTrue(str_starts_with($second->username, TEST_PREFIX . 'same'));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },
];
