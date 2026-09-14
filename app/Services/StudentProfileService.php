<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
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
    public static function update(User $user, array $input): void
    {
        $student = RegistrationService::ensureStudentRecord($user);
        $studentId = (int) $student['id'];
        $nameLocked = self::nameLocked($studentId);

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

        if ($errors !== []) {
            throw ValidationException::withErrors($errors);
        }

        Database::transaction(static function () use ($user, $studentId, $input, $guardians, $nameLocked): void {
            $profile = [];
            foreach (self::PROFILE_FIELDS as $field) {
                $value = trim((string) ($input[$field] ?? ''));
                $profile[$field] = $value === '' ? null : $value;
            }
            $profile['mobile'] = preg_replace('/[\s-]/', '', (string) $profile['mobile']);

            $before = Student::profile($studentId);
            Database::update('student_profiles', $profile, ['student_id' => $studentId]);

            if (!$nameLocked) {
                Database::update('users', [
                    'first_name'  => trim((string) $input['first_name']),
                    'middle_name' => trim((string) ($input['middle_name'] ?? '')) ?: null,
                    'last_name'   => trim((string) $input['last_name']),
                ], ['id' => $user->id]);
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
            AuditService::log('student.profile_updated', 'students', 'Student', $studentId, null, [
                'fields_changed'  => $changed,
                'guardians_saved' => count($guardians),
            ], 'SUCCESS', null, $user->id, $user->role_name);
        });
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
