<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\EnrollmentApplication as Application;
use App\Models\User;

/**
 * Walk-in enrollment (docs/09): the registrar enrolls an applicant who comes to the office.
 *
 * It creates the same records the applicant would have made online (account, profile, DRAFT
 * application) and then hands over to the ordinary workflow: status changes still go through
 * EnrollmentStatusService, with the registrar as the actor (docs/26 rules 2 and 10).
 */
final class WalkInService
{
    /**
     * Step 1: the account, the student record and profile, one guardian, and a DRAFT application.
     * All or nothing, so a closed term or an incomplete profile leaves no account behind.
     *
     * @param array<string,mixed> $input
     * @return array{application_id:int,user_id:int,token:string,expires_at:string}
     * @throws ValidationException
     */
    public static function register(User $registrar, array $input): array
    {
        self::assertRegistrar($registrar);

        $input['email'] = strtolower(trim((string) ($input['email'] ?? '')));

        $validator = Validator::make($input, [
            'first_name'      => 'required|max:100',
            'middle_name'     => 'nullable|max:100',
            'last_name'       => 'required|max:100',
            'suffix'          => 'nullable|max:20',
            'email'           => 'required|email|max:150|unique:users,email',
            'privacy_consent' => 'accepted',
        ], ['privacy_consent' => 'data privacy notice']);
        if ($validator->fails()) {
            throw ValidationException::withErrors($validator->errors());
        }

        $created = Database::transaction(static function () use ($registrar, $input): array {
            $userId = Database::insert('users', [
                'role_id'              => (int) Database::scalar('SELECT id FROM roles WHERE name = ?', [User::ROLE_STUDENT]),
                'username'             => RegistrationService::availableUsername($input['email']),
                'email'                => $input['email'],
                // No one knows this password: the applicant chooses their own through the set-up link.
                'password_hash'        => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                'first_name'           => trim((string) $input['first_name']),
                'middle_name'          => trim((string) ($input['middle_name'] ?? '')) ?: null,
                'last_name'            => trim((string) $input['last_name']),
                'suffix'               => trim((string) ($input['suffix'] ?? '')) ?: null,
                'status'               => User::STATUS_PENDING,
                'must_change_password' => 0,
                'privacy_consented_at' => date('Y-m-d H:i:s'),
            ]);
            $student = RegistrationService::ensureStudentRecord(User::find($userId));

            // The same validation and writes as the student's own profile page.
            StudentProfileService::save((int) $student['id'], $userId, $input, true, $registrar);

            $applicationId = EnrollmentApplicationService::createDraftFor((int) $student['id'], $registrar, $input, 'walk_in');

            AuditService::log('walkin.registered', 'enrollment', 'EnrollmentApplication', $applicationId, null, [
                'student_id' => (int) $student['id'],
            ], 'SUCCESS', null, $registrar->id, $registrar->role_name);

            return ['application_id' => $applicationId, 'user_id' => $userId];
        });

        $link = PasswordResetService::issue(User::find($created['user_id']), PasswordResetService::SETUP, $registrar);

        return $created + ['token' => $link['token'], 'expires_at' => $link['expires_at']];
    }

    /**
     * Step 2 (save): record which documents were received in person. A ticked document is
     * verified at once, with no file; unticking one the registrar ticked earlier undoes it.
     *
     * @param array<int,int|string> $receivedIds enrollment_requirements ids that were ticked
     */
    public static function recordDocuments(User $registrar, int $applicationId, array $receivedIds): void
    {
        self::assertRegistrar($registrar);
        $ticked = array_map('intval', $receivedIds);

        Database::transaction(static function () use ($registrar, $applicationId, $ticked): void {
            $application = self::draft($applicationId, true);
            $now = date('Y-m-d H:i:s');

            foreach (Application::requirements((int) $application['id']) as $requirement) {
                $id = (int) $requirement['id'];
                $received = (int) $requirement['received_in_person'] === 1;
                $want = in_array($id, $ticked, true);

                if ($want && !$received && $requirement['status'] === Application::REQ_NOT_SUBMITTED) {
                    Database::update('enrollment_requirements', [
                        'status'             => Application::REQ_VERIFIED,
                        'verified_by'        => $registrar->id,
                        'verified_at'        => $now,
                        'received_in_person' => 1,
                    ], ['id' => $id]);
                    AuditService::log('walkin.document_received', 'requirements', 'EnrollmentRequirement', $id, null,
                        ['requirement' => $requirement['code'], 'received' => true], 'SUCCESS', null, $registrar->id, $registrar->role_name);
                } elseif (!$want && $received) {
                    Database::update('enrollment_requirements', [
                        'status'             => Application::REQ_NOT_SUBMITTED,
                        'verified_by'        => null,
                        'verified_at'        => null,
                        'received_in_person' => 0,
                    ], ['id' => $id]);
                    AuditService::log('walkin.document_received', 'requirements', 'EnrollmentRequirement', $id, null,
                        ['requirement' => $requirement['code'], 'received' => false], 'SUCCESS', null, $registrar->id, $registrar->role_name);
                }
            }
        });
    }

    /**
     * Step 2 (submit): save the ticks, then move the DRAFT to SUBMITTED as the registrar.
     * The ticks are kept even when the submission is refused, so the registrar can fix the
     * one missing item and submit again.
     *
     * @param array<int,int|string> $receivedIds
     * @throws ValidationException
     */
    public static function submit(User $registrar, int $applicationId, array $receivedIds): void
    {
        self::recordDocuments($registrar, $applicationId, $receivedIds);

        // The status service's own message talks about uploading; at the counter it is a tick.
        $missing = Database::select(
            "SELECT rt.name
               FROM enrollment_requirements er
               JOIN requirement_types rt ON rt.id = er.requirement_type_id
              WHERE er.application_id = ? AND er.is_required = 1 AND er.status <> 'VERIFIED'
              ORDER BY rt.sort_order",
            [$applicationId]
        );
        if ($missing !== []) {
            throw ValidationException::withMessage('Tick the required documents received in person first: ' . implode(', ', array_column($missing, 'name')) . '.');
        }

        EnrollmentStatusService::transition($applicationId, Application::SUBMITTED, $registrar);
    }

    /** Cancel a walk-in that was never submitted (docs/26 rule 10). */
    public static function cancel(User $registrar, int $applicationId): void
    {
        self::assertRegistrar($registrar);
        self::draft($applicationId);
        EnrollmentStatusService::transition($applicationId, Application::CANCELLED, $registrar);
    }

    /**
     * A new set-up link, while the applicant has not yet set a password. Invalidates earlier ones.
     *
     * @return array{token:string,expires_at:string}
     */
    public static function reissueLink(User $registrar, int $applicationId): array
    {
        self::assertRegistrar($registrar);
        $applicant = self::applicant(self::walkIn($applicationId));
        if ($applicant->status !== User::STATUS_PENDING) {
            throw ValidationException::withMessage('This applicant has already set a password, so there is no set-up link to issue.');
        }

        return PasswordResetService::issue($applicant, PasswordResetService::SETUP, $registrar);
    }

    /** Email a link just shown to the registrar to the applicant's own address. */
    public static function emailLink(User $registrar, int $applicationId, string $token): User
    {
        self::assertRegistrar($registrar);
        $applicant = self::applicant(self::walkIn($applicationId));
        $found = PasswordResetService::find($token);
        if ($found === null || $found['user']->id !== $applicant->id) {
            throw ValidationException::withMessage('That link is no longer valid. Issue a new one and email it from there.');
        }
        try {
            PasswordResetService::emailLink($applicant, $token, $found['purpose'], $found['expires_at']);
        } catch (\RuntimeException $e) {
            AuditService::log('password.link_email_failed', 'auth', 'User', $applicant->id, null, null, 'FAILURE', substr($e->getMessage(), 0, 250), $registrar->id, $registrar->role_name);
            throw ValidationException::withMessage('The email could not be sent: ' . $e->getMessage() . ' The link still works; copy it instead.');
        }
        AuditService::log('password.link_emailed', 'auth', 'User', $applicant->id, null, ['purpose' => $found['purpose']], 'SUCCESS', null, $registrar->id, $registrar->role_name);

        return $applicant;
    }

    /**
     * The walk-in still being entered, for the registrar's step-2 page.
     *
     * @return array<string,mixed> the application row
     * @throws HttpException 404 when it is not a walk-in draft
     */
    public static function draft(int $applicationId, bool $lock = false): array
    {
        $application = Database::selectOne(
            'SELECT * FROM enrollment_applications WHERE id = ? AND source = ? AND application_status = ?' . ($lock ? ' FOR UPDATE' : ''),
            [$applicationId, 'walk_in', Application::DRAFT]
        );
        if ($application === null) {
            throw HttpException::notFound('That walk-in is not in progress.');
        }

        return $application;
    }

    /**
     * A walk-in application at any stage. The set-up link is for the applicant's account, which can
     * still be waiting for a first password long after the application was submitted.
     *
     * @return array<string,mixed>
     * @throws HttpException 404 when it is not a walk-in
     */
    public static function walkIn(int $applicationId): array
    {
        $application = Database::selectOne(
            'SELECT * FROM enrollment_applications WHERE id = ? AND source = ?',
            [$applicationId, 'walk_in']
        );
        if ($application === null) {
            throw HttpException::notFound('That walk-in was not found.');
        }

        return $application;
    }

    /** @param array<string,mixed> $application */
    public static function applicant(array $application): User
    {
        $userId = (int) Database::scalar('SELECT user_id FROM students WHERE id = ?', [$application['student_id']]);

        return User::find($userId) ?? throw HttpException::notFound('That applicant was not found.');
    }

    /**
     * Walk-ins started but not yet submitted, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function unfinished(): array
    {
        return Database::select(
            "SELECT ea.id, ea.application_number, ea.created_at, u.first_name, u.last_name, p.code AS program_code
               FROM enrollment_applications ea
               JOIN students s ON s.id = ea.student_id
               JOIN users u    ON u.id = s.user_id
               JOIN programs p ON p.id = ea.program_id
              WHERE ea.source = 'walk_in' AND ea.application_status = 'DRAFT'
              ORDER BY ea.created_at DESC
              LIMIT 20"
        );
    }

    private static function assertRegistrar(User $user): void
    {
        if (!$user->isRegistrar()) {
            throw HttpException::forbidden('Only the registrar can register a walk-in.');
        }
    }
}
