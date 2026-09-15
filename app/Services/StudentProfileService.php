<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\UploadedFile;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\Student;
use App\Models\User;

/**
 * The student's own profile and guardians (docs/08).
 */
final class StudentProfileService
{
    public const SEXES           = ['Male', 'Female'];
    public const CIVIL_STATUSES  = ['Single', 'Married', 'Widowed', 'Separated'];

    /** A profile photo: a small JPG or PNG, stored under storage/ and served only through a route. */
    public const PHOTO_EXTENSIONS = ['jpg', 'jpeg', 'png'];
    public const PHOTO_MAX_BYTES  = 2 * 1024 * 1024;

    /** Profile fields that must be filled before an application can be submitted. */
    private const REQUIRED = [
        'birthdate'         => 'date of birth',
        'sex'               => 'sex',
        'civil_status'      => 'civil status',
        'mobile'            => 'mobile number',
        'barangay'          => 'barangay',
        'city_municipality' => 'city or municipality',
        'province'          => 'province',
        'nationality'       => 'nationality',
    ];

    private const PROFILE_FIELDS = [
        'birthdate', 'sex', 'civil_status', 'mobile', 'telephone', 'street', 'barangay',
        'city_municipality', 'province', 'postal_code', 'nationality', 'religion',
    ];

    /**
     * @param array<string,mixed> $input
     * @throws ValidationException
     */
    public static function update(User $user, array $input, ?UploadedFile $photo = null): void
    {
        $student = RegistrationService::ensureStudentRecord($user);
        $studentId = (int) $student['id'];

        self::save($studentId, $user->id, $input, !self::nameLocked($studentId), $user, $photo);
    }

    /**
     * Validate and save a profile. Shared by the student's own profile page and
     * the registrar's record correction, so both follow the same rules.
     *
     * @param array<string,mixed> $input
     * @param bool $allowNameEdit students lose this once an application is submitted;
     *                            the registrar always has it (docs/04 "manage student records")
     * @throws ValidationException
     */
    public static function save(int $studentId, int $ownerUserId, array $input, bool $allowNameEdit, User $actor, ?UploadedFile $photo = null): void
    {
        $nameLocked = !$allowNameEdit;
        $user = $actor;

        $rules = [
            'birthdate'         => 'required|date|before_today',
            'sex'               => 'required|in:' . implode(',', self::SEXES),
            'civil_status'      => 'required|in:' . implode(',', self::CIVIL_STATUSES),
            'mobile'            => 'required|ph_mobile',
            'telephone'         => 'nullable|max:20',
            'street'            => 'nullable|max:150',
            'barangay'          => 'required|max:100',
            'city_municipality' => 'required|max:100',
            'province'          => 'required|max:100',
            'postal_code'       => 'nullable|max:10',
            'nationality'       => 'required|max:50',
            'religion'          => 'nullable|max:50',
        ];
        if (!$nameLocked) {
            $rules += [
                'first_name'  => 'required|max:100',
                'middle_name' => 'nullable|max:100',
                'last_name'   => 'required|max:100',
            ];
        }

        $validator = Validator::make($input, $rules, [
            'city_municipality' => 'City or municipality',
            'mobile'            => 'Mobile number',
            'birthdate'         => 'Date of birth',
        ]);
        $errors = $validator->errors();

        $guardians = self::normalizeGuardians($input['guardians'] ?? []);
        $errors += self::guardianErrors($guardians);

        // A photo is optional. When one is sent, it must be a genuine small JPG or PNG; the error
        // is attached to the photo field so the rest of the form is preserved and shown again.
        $uploaded = $photo !== null && $photo->error() !== UPLOAD_ERR_NO_FILE;
        if ($uploaded) {
            try {
                $extension = DocumentUploadService::assertGenuineFile($photo, self::PHOTO_EXTENSIONS, self::PHOTO_MAX_BYTES, 'a profile photo');
            } catch (ValidationException $e) {
                $errors['photo'] = [$e->getMessage()];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withErrors($errors);
        }

        // Store the new photo before the transaction; remove it again if the write fails, and
        // remove the replaced one only after it succeeds (docs/10, the announcement image pattern).
        $before = Student::profile($studentId);
        $oldPhoto = $before['photo_path'] ?? null;
        $newPhotoPath = null;
        if ($uploaded) {
            $newPhotoPath = 'uploads/profile-photos/' . bin2hex(random_bytes(16)) . '.' . ($extension === 'jpeg' ? 'jpg' : $extension);
            $photo->moveTo(STORAGE_PATH . '/' . $newPhotoPath);
        }
        $removePhoto = $oldPhoto !== null && ($newPhotoPath !== null || !empty($input['remove_photo']));

        try {
            self::write($user, $ownerUserId, $studentId, $input, $guardians, $nameLocked, $before, $newPhotoPath, $removePhoto);
        } catch (\Throwable $e) {
            if ($newPhotoPath !== null) {
                @unlink(STORAGE_PATH . '/' . $newPhotoPath);
            }
            throw $e;
        }

        // The old file is superseded only once the new row is committed.
        if ($oldPhoto !== null && ($newPhotoPath !== null || $removePhoto)) {
            @unlink(STORAGE_PATH . '/' . $oldPhoto);
        }
    }

    /**
     * The one transaction that writes the profile, name, guardians, photo, and audit entry.
     *
     * @param array<string,mixed>              $input
     * @param array<int,array<string,mixed>>   $guardians
     * @param array<string,mixed>|null         $before   the profile before this save, for the change list
     */
    private static function write(User $user, int $ownerUserId, int $studentId, array $input, array $guardians, bool $nameLocked, ?array $before, ?string $newPhotoPath, bool $removePhoto): void
    {
        Database::transaction(static function () use ($user, $ownerUserId, $studentId, $input, $guardians, $nameLocked, $before, $newPhotoPath, $removePhoto): void {
            $profile = [];
            foreach (self::PROFILE_FIELDS as $field) {
                $value = trim((string) ($input[$field] ?? ''));
                $profile[$field] = $value === '' ? null : $value;
            }
            $profile['mobile'] = preg_replace('/[\s-]/', '', (string) $profile['mobile']);

            if ($newPhotoPath !== null) {
                $profile['photo_path'] = $newPhotoPath;
            } elseif ($removePhoto) {
                $profile['photo_path'] = null;
            }

            Database::update('student_profiles', $profile, ['student_id' => $studentId]);

            $nameChanged = false;
            if (!$nameLocked) {
                // The STUDENT's account, never the actor's: when the registrar corrects
                // a record, $user is the registrar.
                $oldName = Database::selectOne('SELECT first_name, middle_name, last_name FROM users WHERE id = ?', [$ownerUserId]);
                $newName = [
                    'first_name'  => trim((string) $input['first_name']),
                    'middle_name' => trim((string) ($input['middle_name'] ?? '')) ?: null,
                    'last_name'   => trim((string) $input['last_name']),
                ];
                $nameChanged = $oldName != $newName;
                Database::update('users', $newName, ['id' => $ownerUserId]);
            }

            // Guardians are contact details the student maintains, so the set is
            // replaced as a whole on each save.
            Database::query('DELETE FROM guardians WHERE student_id = ?', [$studentId]);
            foreach ($guardians as $guardian) {
                Database::insert('guardians', $guardian + ['student_id' => $studentId]);
            }

            // Record WHICH fields changed, not their values — the audit log is not a
            // second copy of personal data.
            $changed = array_keys(array_filter(
                $profile,
                static fn ($value, $field) => (string) ($before[$field] ?? '') !== (string) ($value ?? ''),
                ARRAY_FILTER_USE_BOTH
            ));
            // The stored path is random, so record only that the photo changed, by name.
            $changed = array_map(static fn ($field) => $field === 'photo_path' ? 'photo' : $field, $changed);
            if ($nameChanged) {
                $changed[] = 'name';
            }
            AuditService::log(
                $user->id === $ownerUserId ? 'student.profile_updated' : 'student.record_corrected',
                'students',
                'Student',
                $studentId,
                null,
                ['fields_changed' => $changed, 'guardians_saved' => count($guardians)],
                'SUCCESS',
                null,
                $user->id,
                $user->role_name
            );
        });
    }

    /**
     * The student's photo for streaming, or null when there is none or the file is gone. The MIME
     * type is derived from the stored extension, which this service controls (JPG or PNG only).
     *
     * @return array{path:string,mime:string}|null
     */
    public static function photo(int $studentId): ?array
    {
        $path = (string) (Student::profile($studentId)['photo_path'] ?? '');
        if ($path === '' || !is_file(STORAGE_PATH . '/' . $path)) {
            return null;
        }
        $mime = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'png' ? 'image/png' : 'image/jpeg';
        return ['path' => $path, 'mime' => $mime];
    }

    /**
     * Stream a student's photo inline, or throw 404 when there is none. The caller is responsible
     * for authorizing access to this student (the owner, or staff who may view the record).
     *
     * @throws \App\Core\HttpException
     */
    public static function streamPhoto(int $studentId): void
    {
        $photo = self::photo($studentId);
        if ($photo === null) {
            throw \App\Core\HttpException::notFound('No photo has been uploaded.');
        }
        FileStreamer::send($photo['path'], 'photo.' . pathinfo($photo['path'], PATHINFO_EXTENSION), $photo['mime'], true);
    }

    /**
     * Human names of the profile items still missing. Empty means complete.
     *
     * @return array<int,string>
     */
    public static function missingFields(int $studentId): array
    {
        $profile = Student::profile($studentId);
        $missing = [];

        foreach (self::REQUIRED as $field => $label) {
            if (trim((string) ($profile[$field] ?? '')) === '') {
                $missing[] = $label;
            }
        }

        $hasEmergencyContact = (int) Database::scalar(
            'SELECT COUNT(*) FROM guardians WHERE student_id = ? AND is_emergency_contact = 1',
            [$studentId]
        ) > 0;
        if (!$hasEmergencyContact) {
            $missing[] = 'an emergency contact';
        }

        return $missing;
    }

    /**
     * Once an application has gone to the registrar, the legal name is the one
     * under review and can no longer be changed from the profile.
     */
    public static function nameLocked(int $studentId): bool
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM enrollment_applications
              WHERE student_id = ? AND application_status NOT IN ('DRAFT', 'CANCELLED')",
            [$studentId]
        ) > 0;
    }

    /**
     * @param mixed $raw guardians[i][field] from the form
     * @return array<int,array<string,mixed>>
     */
    private static function normalizeGuardians(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $rows = [];
        foreach (array_values($raw) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $clean = [
                'full_name'            => trim((string) ($row['full_name'] ?? '')),
                'relationship'         => trim((string) ($row['relationship'] ?? '')),
                'mobile'               => preg_replace('/[\s-]/', '', trim((string) ($row['mobile'] ?? ''))),
                'email'                => trim((string) ($row['email'] ?? '')) ?: null,
                'address'              => trim((string) ($row['address'] ?? '')) ?: null,
                'is_emergency_contact' => !empty($row['is_emergency_contact']) ? 1 : 0,
            ];
            // A row left completely blank is simply ignored.
            if ($clean['full_name'] === '' && $clean['relationship'] === '' && $clean['mobile'] === '') {
                continue;
            }
            $rows[] = $clean;
        }

        return array_slice($rows, 0, 5);
    }

    /**
     * @param array<int,array<string,mixed>> $guardians
     * @return array<string,array<int,string>>
     */
    private static function guardianErrors(array $guardians): array
    {
        $errors = [];

        foreach ($guardians as $index => $guardian) {
            $v = Validator::make($guardian, [
                'full_name'    => 'required|max:150',
                'relationship' => 'required|max:50',
                'mobile'       => 'required|ph_mobile',
                'email'        => 'nullable|email|max:150',
                'address'      => 'nullable|max:255',
            ], ['full_name' => 'Name', 'mobile' => 'Mobile number']);

            foreach ($v->errors() as $field => $messages) {
                $errors["guardians.{$index}.{$field}"] = $messages;
            }
        }

        if ($guardians === [] || !in_array(1, array_column($guardians, 'is_emergency_contact'), true)) {
            $errors['guardians'] = ['Add at least one guardian and mark them as your emergency contact.'];
        }

        return $errors;
    }
}
