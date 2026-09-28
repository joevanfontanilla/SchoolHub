<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\Student;
use App\Models\SystemSetting;
use App\Models\User;

/**
 * Student self-registration (docs/09 step 1).
 *
 * Sign-up is open without email verification (decided for Phase 2). The
 * protections in its place: a per-IP hourly limit, a honeypot field that real
 * people never see, and a required data privacy consent (RA 10173).
 */
final class RegistrationService
{
    /** The honeypot input name. Hidden from people; bots tend to fill it. */
    public const HONEYPOT = 'website';

    /**
     * @param array<string,mixed> $input
     * @throws ValidationException
     * @throws HttpException 429 when the IP has reached its sign-up limit
     */
    public static function register(array $input, string $ip, string $userAgent): User
    {
        if (trim((string) ($input[self::HONEYPOT] ?? '')) !== '') {
            AuditService::log('student.registration_blocked', 'auth', null, null, null, null, 'FAILURE', 'Honeypot field filled', null, 'Guest');
            // Deliberately vague: do not teach a bot which check it tripped.
            throw ValidationException::withMessage('We could not create your account. Please try again.');
        }

        $limit = (int) SystemSetting::get('registration_limit_per_hour', 5);
        $recent = (int) Database::scalar(
            "SELECT COUNT(*) FROM audit_logs
              WHERE action = 'student.registered' AND ip_address = ? AND created_at > ?",
            [$ip, date('Y-m-d H:i:s', time() - 3600)]
        );
        if ($recent >= $limit) {
            AuditService::log('student.registration_blocked', 'auth', null, null, null, null, 'FAILURE', 'IP sign-up limit reached', null, 'Guest');
            throw new HttpException(429, 'Too many accounts have been created from this network recently. Please try again in an hour.');
        }

        $input['email'] = strtolower(trim((string) ($input['email'] ?? '')));

        $validator = Validator::make($input, [
            'first_name'      => 'required|max:100',
            'middle_name'     => 'nullable|max:100',
            'last_name'       => 'required|max:100',
            'suffix'          => 'nullable|max:20',
            'email'           => 'required|email|max:150|unique:users,email',
            'password'        => 'required|confirmed',
            'privacy_consent' => 'accepted',
        ], [
            'privacy_consent' => 'data privacy notice',
        ]);

        $passwordProblem = AuthService::passwordPolicyProblem((string) ($input['password'] ?? ''));
        if ($passwordProblem !== null) {
            $validator->addError('password', str_replace('Your new password', 'Your password', $passwordProblem));
        }

        if ($validator->fails()) {
            throw ValidationException::withErrors($validator->errors());
        }

        $roleId = (int) Database::scalar('SELECT id FROM roles WHERE name = ?', [User::ROLE_STUDENT]);

        $userId = Database::transaction(static function () use ($input, $roleId): int {
            $userId = Database::insert('users', [
                'role_id'              => $roleId,
                'username'             => self::availableUsername($input['email']),
                'email'                => $input['email'],
                'password_hash'        => password_hash((string) $input['password'], PASSWORD_DEFAULT),
                'first_name'           => trim((string) $input['first_name']),
                'middle_name'          => trim((string) ($input['middle_name'] ?? '')) ?: null,
                'last_name'            => trim((string) $input['last_name']),
                'suffix'               => trim((string) ($input['suffix'] ?? '')) ?: null,
                'status'               => User::STATUS_ACTIVE,
                'must_change_password' => 0,
                'privacy_consented_at' => date('Y-m-d H:i:s'),
            ]);

            self::createStudentRecord($userId);

            return $userId;
        });

        $user = User::find($userId);

        AuditService::log('student.registered', 'auth', 'User', $userId, null, null, 'SUCCESS', null, $userId, User::ROLE_STUDENT);

        Auth::login($user);

        return $user;
    }

    /**
     * The student record for a Student-role user, creating it if missing.
     *
     * Accounts made by an administrator (or the demo-account script) have no
     * student row yet; this gives them one on first use.
     *
     * @return array<string,mixed>
     */
    public static function ensureStudentRecord(User $user): array
    {
        if (!$user->isStudent()) {
            throw HttpException::forbidden();
        }

        $student = Student::findByUserId($user->id);
        if ($student !== null) {
            return $student;
        }

        Database::transaction(static fn () => self::createStudentRecord($user->id));

        return Student::findByUserId($user->id);
    }

    private static function createStudentRecord(int $userId): void
    {
        $studentId = Database::insert('students', [
            'user_id'      => $userId,
            'student_type' => 'New',
            'status'       => Student::STATUS_APPLICANT,
        ]);
        Database::insert('student_profiles', ['student_id' => $studentId]);
    }

    /** A username from the email's local part, with a numeric suffix if taken. */
    private static function availableUsername(string $email): string
    {
        $base = strtolower((string) preg_replace('/[^a-z0-9._-]/i', '', strstr($email, '@', true) ?: ''));
        $base = substr($base !== '' ? $base : 'student', 0, 40);

        $candidate = $base;
        for ($attempt = 0; $attempt < 20; $attempt++) {
            if ((int) Database::scalar('SELECT COUNT(*) FROM users WHERE username = ?', [$candidate]) === 0) {
                return $candidate;
            }
            $candidate = $base . random_int(100, 9999);
        }

        return $base . bin2hex(random_bytes(4));
    }
}
