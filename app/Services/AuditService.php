<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;

/**
 * Writes audit_logs entries (docs/19).
 *
 * Append-only: this class deliberately exposes no update or delete method, and
 * no other code writes to audit_logs.
 */
final class AuditService
{
    /** Values with these names are stripped before anything is recorded. */
    private const REDACTED = [
        'password', 'password_hash', 'password_confirmation', 'current_password',
        'new_password', 'token', 'token_hash', '_token', 'remember_token',
        'file', 'file_contents',
    ];

    public static function log(
        string $action,
        string $module,
        ?string $targetType = null,
        ?int $targetId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        string $result = 'SUCCESS',
        ?string $remarks = null,
        ?int $userId = null,
        ?string $actorRole = null
    ): void {
        $request = new Request();

        try {
            Database::insert('audit_logs', [
                'user_id'     => $userId ?? Auth::id(),
                'actor_role'  => $actorRole ?? Auth::roleName() ?? 'Guest',
                'action'      => $action,
                'module'      => $module,
                'target_type' => $targetType,
                'target_id'   => $targetId,
                'old_values'  => self::encode($oldValues),
                'new_values'  => self::encode($newValues),
                'ip_address'  => $request->ip(),
                'user_agent'  => $request->userAgent(),
                'result'      => $result,
                'remarks'     => $remarks,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // An audit write must never take down the action it is recording,
            // but it must not vanish silently either.
            @file_put_contents(
                STORAGE_PATH . '/logs/audit-failures.log',
                sprintf("[%s] %s | %s\n", date('c'), $action, $e->getMessage()),
                FILE_APPEND
            );
        }
    }

    private static function encode(?array $values): ?string
    {
        if ($values === null || $values === []) {
            return null;
        }

        foreach ($values as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACTED, true)) {
                unset($values[$key]);
            }
        }

        return $values === [] ? null : json_encode($values, JSON_UNESCAPED_UNICODE);
    }
}
