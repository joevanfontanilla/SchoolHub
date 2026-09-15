<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\UploadedFile;
use App\Core\Validator;
use App\Core\ValidationException;
use App\Models\SystemSetting;
use App\Models\User;

/**
 * System settings edited by the Super Admin (docs/20).
 *
 * Only the settings defined here can be changed from the screen, each with its own
 * rule. A save writes only the values that changed and audits each change with its
 * old and new value. Terms, enrollment windows, fees, and requirements have their own
 * screens (registrar and cashier), so they are not repeated here.
 */
final class SettingsService
{
    public const LOGO_EXTENSIONS = ['jpg', 'jpeg', 'png'];
    public const LOGO_MAX_BYTES = 1024 * 1024;

    /**
     * group => [title, description, [key => [label, rule, help]]]
     * Rules: text:max | email | enum:A,B | int:min,max | bool
     */
    public const GROUPS = [
        'school' => ['School profile', 'Shown on the public website, printouts, and the portal.', [
            'school_name'    => ['School name', 'text:150', null],
            'school_tagline' => ['Tagline', 'text:150', 'Shown under the name on the home page.'],
            'school_address' => ['Address', 'text:255', null],
            'school_email'   => ['Contact email', 'email', null],
            'school_phone'   => ['Contact number', 'text:50', null],
            'office_hours'   => ['Office hours', 'text:150', null],
        ]],
        'enrollment' => ['Enrollment', 'How enrollment is confirmed (docs/26).', [
            'enrollment_payment_policy'   => ['Payment needed to enroll', 'enum:MINIMUM_DOWNPAYMENT,FULL,NONE', 'Down payment, full payment, or none before a student is officially enrolled.'],
            'minimum_downpayment_percent' => ['Minimum down payment (%)', 'int:0,100', 'Used with the down payment policy.'],
            'enrollment_open_message'     => ['Enrollment page message', 'text:500', 'Shown on the public enrollment page.'],
        ]],
        'academic' => ['Academic', 'Limits on class loads (docs/13).', [
            'max_units_per_term' => ['Maximum units per term', 'int:6,40', 'A curriculum with a heavier regular load for a term still allows that load.'],
        ]],
        'finance' => ['Finance', 'Online payment details (docs/14).', [
            'payment_instructions' => ['Where students send payments', 'textarea:2000', 'Shown to students on their account page. Replace the sample details with the school\'s real accounts.'],
        ]],
        'security' => ['Security', 'Sign-in protection and password links (docs/07).', [
            'max_login_attempts'         => ['Failed sign-ins before lockout', 'int:3,20', null],
            'lockout_minutes'            => ['Lockout length (minutes)', 'int:1,1440', null],
            'ip_max_attempts'            => ['Failed sign-ins per IP address before blocking', 'int:5,500', null],
            'ip_window_minutes'          => ['Counting window (minutes)', 'int:1,1440', null],
            'password_min_length'        => ['Minimum password length', 'int:8,64', 'Passwords also need a letter and a number.'],
            'reset_link_minutes'         => ['Reset link lifetime (minutes)', 'int:10,1440', null],
            'setup_link_hours'           => ['New account link lifetime (hours)', 'int:1,336', null],
            'registration_limit_per_hour' => ['Student sign-ups per IP address per hour', 'int:1,100', null],
        ]],
        'backups' => ['Backups', 'What a backup contains and how many are kept (docs/20).', [
            'backup_include_uploads' => ['Include uploaded files', 'bool', 'Requirement documents, payment proofs, and images.'],
            'backup_retention_count' => ['Backups to keep', 'int:1,365', 'Older completed backups are removed after each new one.'],
        ]],
    ];

    /**
     * @param array<string,mixed> $input
     * @return array<string,array{0:?string,1:string}> changed key => [old, new]
     */
    public static function update(User $admin, string $group, array $input, ?UploadedFile $logo = null): array
    {
        if (!$admin->isSuperAdmin()) {
            throw HttpException::forbidden('Only the Super Admin changes system settings.');
        }
        if (!isset(self::GROUPS[$group])) {
            throw HttpException::notFound('That settings group does not exist.');
        }
        [, , $fields] = self::GROUPS[$group];

        $validator = Validator::make([], []);
        $values = [];
        foreach ($fields as $key => [$label, $rule]) {
            $raw = $input[$key] ?? null;
            if (is_string($raw) && !mb_check_encoding($raw, 'UTF-8')) {
                $validator->addError($key, "{$label} contains characters that could not be read. Retype it.");
                continue;
            }
            [$type, $arg] = array_pad(explode(':', $rule, 2), 2, '');
            // A field missing from the request is left as it is (an unticked checkbox is sent as absent, so bool is the exception).
            if ($type !== 'bool' && !array_key_exists($key, $input)) {
                continue;
            }
            switch ($type) {
                case 'bool':
                    $values[$key] = !empty($raw) ? '1' : '0';
                    break;
                case 'int':
                    [$min, $max] = array_map('intval', explode(',', $arg));
                    $text = trim((string) $raw);
                    if (!preg_match('/^-?\d+$/', $text) || (int) $text < $min || (int) $text > $max) {
                        $validator->addError($key, "{$label} must be a whole number from {$min} to {$max}.");
                    }
                    $values[$key] = (string) (int) $text;
                    break;
                case 'enum':
                    $options = explode(',', $arg);
                    if (!in_array((string) $raw, $options, true)) {
                        $validator->addError($key, "Choose a valid option for {$label}.");
                    }
                    $values[$key] = (string) $raw;
                    break;
                case 'email':
                    $text = strtolower(trim((string) $raw));
                    if ($text === '' || !filter_var($text, FILTER_VALIDATE_EMAIL) || mb_strlen($text) > 150) {
                        $validator->addError($key, "{$label} must be a valid email address.");
                    }
                    $values[$key] = $text;
                    break;
                default: // text, textarea
                    $text = str_replace("\r\n", "\n", trim((string) $raw));
                    if ($text === '' && $key === 'school_name') {
                        $validator->addError($key, "{$label} is required.");
                    }
                    if (mb_strlen($text) > (int) $arg) {
                        $validator->addError($key, "{$label} may be at most {$arg} characters.");
                    }
                    if ($type === 'text' && str_contains($text, "\n")) {
                        $text = str_replace("\n", ' ', $text);
                    }
                    $values[$key] = $text;
            }
        }
        if ($group === 'security' && isset($values['ip_max_attempts'], $values['max_login_attempts']) && (int) $values['ip_max_attempts'] < (int) $values['max_login_attempts']) {
            $validator->addError('ip_max_attempts', 'The per-IP limit must be at least the per-account limit, or one mistyped account would block everyone on that network.');
        }
        if ($validator->fails()) {
            throw ValidationException::withErrors($validator->errors());
        }

        $newLogo = null;
        if ($group === 'school' && $logo !== null && $logo->error() !== UPLOAD_ERR_NO_FILE) {
            $extension = DocumentUploadService::assertGenuineFile($logo, self::LOGO_EXTENSIONS, self::LOGO_MAX_BYTES, 'the school logo');
            $newLogo = 'uploads/branding/logo-' . bin2hex(random_bytes(8)) . '.' . ($extension === 'jpeg' ? 'jpg' : $extension);
            $logo->moveTo(STORAGE_PATH . '/' . $newLogo);
        }
        $oldLogo = (string) SystemSetting::get('school_logo', '');
        if ($newLogo !== null) {
            $values['school_logo'] = $newLogo;
        } elseif ($group === 'school' && !empty($input['remove_logo'])) {
            $values['school_logo'] = '';
        }

        $all = SystemSetting::all();
        $changed = [];
        foreach ($values as $key => $value) {
            $old = $all[$key]['value'] ?? null;
            if ((string) $old !== $value) {
                $changed[$key] = [$old, $value];
            }
        }

        try {
            Database::transaction(static function () use ($admin, $group, $changed): void {
                foreach ($changed as $key => [, $new]) {
                    SystemSetting::set($key, $new, $admin->id);
                }
                if ($changed !== []) {
                    AuditService::log('settings.updated', 'administration', 'SystemSetting', null,
                        array_map(static fn ($pair) => $pair[0], $changed),
                        array_map(static fn ($pair) => $pair[1], $changed),
                        'SUCCESS', "Group: {$group}", $admin->id, $admin->role_name);
                }
            });
        } catch (\Throwable $e) {
            if ($newLogo !== null) {
                @unlink(STORAGE_PATH . '/' . $newLogo);
            }
            throw $e;
        }

        if (isset($changed['school_logo']) && $oldLogo !== '' && str_starts_with($oldLogo, 'uploads/branding/')) {
            @unlink(STORAGE_PATH . '/' . $oldLogo);
        }

        return $changed;
    }

    /** The stored logo file, if one is set and still present. */
    public static function logoPath(): ?string
    {
        $path = (string) SystemSetting::get('school_logo', '');
        return $path !== '' && str_starts_with($path, 'uploads/branding/') && is_file(STORAGE_PATH . '/' . $path) ? $path : null;
    }
}
