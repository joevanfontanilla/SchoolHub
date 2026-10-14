<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\UploadedFile;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\Catalog;
use App\Models\EnrollmentApplication as Application;
use App\Models\Student;
use App\Models\Term;
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

        // Everything is checked before anything is written, so the registrar fixes the form once.
        $errors = self::registerErrors($input);
        if ($errors !== []) {
            throw ValidationException::withErrors($errors);
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
     * Every problem with a new-account intake, in one pass: the account, the profile and guardian, the
     * enrollment choices, whether a section exists for them, and a likely duplicate of a registered student.
     *
     * @param array<string,mixed> $input
     * @return array<string,array<int,string>>
     */
    public static function registerErrors(array $input): array
    {
        $validator = Validator::make($input, [
            'first_name'      => 'required|max:100',
            'middle_name'     => 'nullable|max:100',
            'last_name'       => 'required|max:100',
            'suffix'          => 'nullable|max:20',
            'email'           => 'required|email|max:150|unique:users,email',
            'privacy_consent' => 'accepted',
        ], ['privacy_consent' => 'data privacy notice']);

        $errors = $validator->errors();
        $errors += StudentProfileService::errorsFor($input, true, null, "the applicant's");
        $errors += EnrollmentApplicationService::intakeErrors($input);
        $errors += self::sectionErrors($input, $errors);

        // With no guardian entered, flag the three fields themselves, not only a message above them.
        if (isset($errors['guardians']) && array_filter(array_keys($errors), static fn (string $key): bool => str_starts_with($key, 'guardians.')) === []) {
            foreach (['full_name' => 'Name', 'relationship' => 'Relationship', 'mobile' => 'Mobile number'] as $field => $label) {
                $errors["guardians.0.{$field}"] = ["{$label} is required."];
            }
        }

        // The same person may already be registered: offer the existing record before creating a second one.
        if (!isset($errors['first_name'], $errors['last_name'], $errors['birthdate']) && empty($input['confirm_new'])) {
            $matches = self::possibleDuplicates(trim((string) ($input['first_name'] ?? '')), trim((string) ($input['last_name'] ?? '')), trim((string) ($input['birthdate'] ?? '')));
            if ($matches !== []) {
                $names = implode('; ', array_map(static fn (array $m): string => $m['last_name'] . ', ' . $m['first_name'] . ($m['student_number'] ? ' (' . $m['student_number'] . ')' : ''), $matches));
                $errors['duplicate'] = ["A student with this name and date of birth is already registered: {$names}. Use Find an existing student to enroll them, or tick that this is a different person."];
            }
        }

        return $errors;
    }

    /**
     * An application on an account that already exists (a continuing or returning student): no new
     * account and no set-up link, only the enrollment choices.
     *
     * @param array<string,mixed> $input term_id, program_id, year_level_id, student_type, previous_school, last_school_year
     * @return int the DRAFT application's id
     * @throws ValidationException
     */
    public static function registerExisting(User $registrar, int $studentId, array $input): int
    {
        self::assertRegistrar($registrar);

        $student = Database::selectOne('SELECT id, user_id FROM students WHERE id = ?', [$studentId]);
        $user = $student !== null ? User::find((int) $student['user_id']) : null;
        if ($user === null) {
            throw HttpException::notFound('That student was not found.');
        }
        if (!in_array($user->status, [User::STATUS_ACTIVE, User::STATUS_PENDING], true)) {
            throw ValidationException::withMessage('This student\'s account is ' . strtolower($user->status) . ', so an application cannot be started for them.');
        }

        $errors = EnrollmentApplicationService::intakeErrors($input);
        $errors += self::sectionErrors($input, $errors);
        if (!isset($errors['term_id']) && Application::hasOpenApplicationForTerm($studentId, (int) $input['term_id'])) {
            $errors['term_id'] = ['This student already has an application for that term. Continue it, or cancel it first.'];
        }
        if ($errors !== []) {
            throw ValidationException::withErrors($errors);
        }

        $applicationId = EnrollmentApplicationService::createDraftFor($studentId, $registrar, $input, 'walk_in');
        AuditService::log('walkin.registered', 'enrollment', 'EnrollmentApplication', $applicationId, null, [
            'student_id' => $studentId, 'existing_account' => true,
        ], 'SUCCESS', null, $registrar->id, $registrar->role_name);

        return $applicationId;
    }

    /**
     * Students the registrar can enroll again, by name, student number, or email.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function findStudents(string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';

        return Database::select(
            "SELECT s.id, s.student_number, u.first_name, u.last_name, u.email, u.status, p.code AS program_code
               FROM students s
               JOIN users u ON u.id = s.user_id
          LEFT JOIN programs p ON p.id = s.program_id
              WHERE (CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR CONCAT(u.last_name, ', ', u.first_name) LIKE ? OR s.student_number LIKE ? OR u.email LIKE ?)
                AND u.status IN ('Active', 'Pending')
              ORDER BY u.last_name, u.first_name
              LIMIT 10",
            [$like, $like, $like, $like]
        );
    }

    /** One registered student, with the name and number shown on the page that starts their application. @return array<string,mixed>|null */
    public static function studentSummary(int $studentId): ?array
    {
        return Database::selectOne(
            'SELECT s.id, s.student_number, u.first_name, u.last_name, u.email, u.status, p.code AS program_code
               FROM students s JOIN users u ON u.id = s.user_id LEFT JOIN programs p ON p.id = s.program_id
              WHERE s.id = ?',
            [$studentId]
        );
    }

    /** Students already registered with this name and date of birth, at most three. @return array<int,array<string,mixed>> */
    public static function possibleDuplicates(string $firstName, string $lastName, string $birthdate): array
    {
        if ($firstName === '' || $lastName === '' || $birthdate === '') {
            return [];
        }

        return Database::select(
            'SELECT s.id, s.student_number, u.first_name, u.last_name
               FROM students s
               JOIN users u ON u.id = s.user_id
               JOIN student_profiles sp ON sp.student_id = s.id
              WHERE LOWER(u.first_name) = LOWER(?) AND LOWER(u.last_name) = LOWER(?) AND sp.birthdate = ?
              LIMIT 3',
            [$firstName, $lastName, $birthdate]
        );
    }

    /**
     * The applicant's photo, taken at the counter: saved as their profile photo, so the e-ID card works.
     *
     * @return bool true when a photo was saved, false when none was sent
     * @throws ValidationException
     */
    public static function savePhoto(User $registrar, int $applicationId, ?UploadedFile $file): bool
    {
        self::assertRegistrar($registrar);
        if ($file === null || $file->error() === UPLOAD_ERR_NO_FILE) {
            return false;
        }
        $application = self::draft($applicationId);
        $studentId = (int) $application['student_id'];

        try {
            $extension = DocumentUploadService::assertGenuineFile($file, StudentProfileService::PHOTO_EXTENSIONS, StudentProfileService::PHOTO_MAX_BYTES, 'a profile photo');
        } catch (ValidationException $e) {
            throw ValidationException::withErrors(['photo' => [$e->getMessage()]]);
        }

        $old = Student::profile($studentId)['photo_path'] ?? null;
        $path = 'uploads/profile-photos/' . bin2hex(random_bytes(16)) . '.' . ($extension === 'jpeg' ? 'jpg' : $extension);
        $file->moveTo(STORAGE_PATH . '/' . $path);

        try {
            Database::transaction(static function () use ($registrar, $applicationId, $studentId, $path): void {
                $changed = Database::update('student_profiles', ['photo_path' => $path], ['student_id' => $studentId]);
                if ($changed === 0) {
                    throw ValidationException::withMessage('This student\'s profile is not complete yet, so a photo cannot be saved. Complete the profile first.');
                }
                AuditService::log('walkin.photo_saved', 'enrollment', 'EnrollmentApplication', $applicationId, null, ['student_id' => $studentId],
                    'SUCCESS', null, $registrar->id, $registrar->role_name);
            });
        } catch (\Throwable $e) {
            @unlink(STORAGE_PATH . '/' . $path);
            throw $e;
        }
        if ($old !== null && $old !== $path) {
            @unlink(STORAGE_PATH . '/' . $old);
        }

        return true;
    }

    /**
     * A program, year level, and term with no section cannot be approved, so it is refused at the counter
     * instead of leaving a draft that goes nowhere.
     *
     * @param array<string,mixed>                $input
     * @param array<string,array<int,string>>    $errors problems already found with those three choices
     * @return array<string,array<int,string>>
     */
    private static function sectionErrors(array $input, array $errors): array
    {
        $term = (int) ($input['term_id'] ?? 0);
        $program = (int) ($input['program_id'] ?? 0);
        $year = (int) ($input['year_level_id'] ?? 0);
        if ($term <= 0 || $program <= 0 || $year <= 0 || isset($errors['term_id']) || isset($errors['program_id']) || isset($errors['year_level_id'])) {
            return [];
        }
        if (Catalog::sectionsForApplication(['term_id' => $term, 'program_id' => $program, 'year_level_id' => $year]) !== []) {
            return [];
        }

        $termRow = Term::find($term);
        $programCode = (string) Database::scalar('SELECT code FROM programs WHERE id = ?', [$program]);
        $yearName = (string) Database::scalar('SELECT name FROM year_levels WHERE id = ?', [$year]);

        return ['program_id' => ["No section exists for {$programCode}, {$yearName}, " . ($termRow !== null ? Term::label($termRow) : 'that term') . '. Create one under Registrar, Sections first, or choose another program or year.']];
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
     * Step 2 (submit): save the ticks, then move the DRAFT to SUBMITTED and straight on to UNDER_REVIEW as the registrar.
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
        // The registrar verified every document at the counter, so review starts at once: no separate click.
        EnrollmentStatusService::transition($applicationId, Application::UNDER_REVIEW, $registrar);
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
