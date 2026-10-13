<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\Student;
use App\Models\User;

/**
 * A user changing their own name on My Account (docs/08). Every role may; the email and username stay
 * with the Super Admin because they are the sign-in identifier and where password links are sent.
 *
 * A student's legal name is locked once an application is with the registrar (docs/09), the same rule
 * as the student Profile page. A change is audited with the old and the new name, because names appear
 * on receipts, statements, and the audit log itself.
 */
final class AccountNameService
{
    public const FIELDS = ['first_name', 'middle_name', 'last_name', 'suffix'];

    /** Why this user cannot change their name now, or null when they can. */
    public static function lockedReason(User $user): ?string
    {
        if (!$user->isStudent()) {
            return null;
        }
        $student = Student::findByUserId($user->id);
        if ($student !== null && StudentProfileService::nameLocked((int) $student['id'])) {
            return 'Your name is locked because an application is with the registrar. Ask the registrar\'s office to correct it.';
        }

        return null;
    }

    /**
     * Save the user's own name. Returns false when nothing changed (and writes nothing).
     *
     * @param array<string,mixed> $input first_name, middle_name, last_name, suffix; anything else is ignored
     * @throws ValidationException
     */
    public static function update(User $user, array $input): bool
    {
        $locked = self::lockedReason($user);
        if ($locked !== null) {
            throw ValidationException::withMessage($locked);
        }

        $clean = [];
        foreach (self::FIELDS as $field) {
            // Trim, and collapse runs of spaces so "Ana   Maria" is stored as "Ana Maria".
            $clean[$field] = trim((string) preg_replace('/\s+/u', ' ', (string) ($input[$field] ?? '')));
        }

        $validator = Validator::make($clean, [
            'first_name'  => 'required|max:100',
            'middle_name' => 'nullable|max:100',
            'last_name'   => 'required|max:100',
            'suffix'      => 'nullable|max:20',
        ], ['first_name' => 'First name', 'middle_name' => 'Middle name', 'last_name' => 'Last name']);
        if ($validator->fails()) {
            throw ValidationException::withErrors($validator->errors());
        }

        $new = [
            'first_name'  => $clean['first_name'],
            'middle_name' => $clean['middle_name'] !== '' ? $clean['middle_name'] : null,
            'last_name'   => $clean['last_name'],
            'suffix'      => $clean['suffix'] !== '' ? $clean['suffix'] : null,
        ];

        return Database::transaction(static function () use ($user, $new): bool {
            $old = Database::selectOne('SELECT first_name, middle_name, last_name, suffix FROM users WHERE id = ? FOR UPDATE', [$user->id]);
            if ($old == $new) {
                return false;
            }

            Database::update('users', $new, ['id' => $user->id]);
            AuditService::log('account.name_changed', 'auth', 'User', $user->id, $old, $new, 'SUCCESS', null, $user->id, $user->role_name);

            return true;
        });
    }
}
