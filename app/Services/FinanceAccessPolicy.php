<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Models\User;

/**
 * Who may open a receipt, statement of account, or payment proof (docs/15):
 *   - the student it belongs to — for any other student it does not exist (404);
 *   - the staff roles named for that kind of document;
 *   - nobody else (403).
 */
final class FinanceAccessPolicy
{
    /**
     * @param array<int,string> $staffRoles
     * @throws HttpException
     */
    public static function check(?User $user, ?int $ownerUserId, bool $exists, string $what, array $staffRoles): void
    {
        if ($user === null) {
            throw HttpException::unauthorized('Please sign in.');
        }
        if ($user->isStudent()) {
            if (!$exists || $ownerUserId !== $user->id) {
                throw HttpException::notFound("That {$what} was not found.");
            }
            return;
        }
        if (!in_array($user->role_name, $staffRoles, true)) {
            throw HttpException::forbidden("You are not allowed to open a {$what}.");
        }
        if (!$exists) {
            throw HttpException::notFound("That {$what} was not found.");
        }
    }
}
