<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\User;

/**
 * Who may open an uploaded requirement document (docs/17 section 10).
 *
 *   Student      only their own documents
 *   Registrar    any (they verify requirements)
 *   Super Admin  any
 *   Teacher      never
 *   Cashier      never
 */
final class DocumentAccessPolicy
{
    public const ALLOW     = 'allow';
    /** A student asking for someone else's file: respond as if it does not exist. */
    public const NOT_FOUND = 'not_found';
    public const FORBIDDEN = 'forbidden';

    /**
     * @return array{0:string,1:?array<string,mixed>} decision, and the file row when allowed
     */
    public static function decide(User $user, int $fileId): array
    {
        $file = Database::selectOne(
            'SELECT f.*, er.application_id, s.user_id AS owner_user_id
               FROM enrollment_requirement_files f
               JOIN enrollment_requirements er ON er.id = f.enrollment_requirement_id
               JOIN enrollment_applications ea ON ea.id = er.application_id
               JOIN students s                 ON s.id  = ea.student_id
              WHERE f.id = ?',
            [$fileId]
        );

        if ($user->isStudent()) {
            return ($file !== null && (int) $file['owner_user_id'] === $user->id)
                ? [self::ALLOW, $file]
                : [self::NOT_FOUND, null];
        }

        if ($user->isRegistrar() || $user->isSuperAdmin()) {
            return $file !== null ? [self::ALLOW, $file] : [self::NOT_FOUND, null];
        }

        // Teachers and cashiers are refused whether or not the file exists.
        return [self::FORBIDDEN, null];
    }
}
