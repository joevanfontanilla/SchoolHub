<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\SystemSetting;
use App\Models\User;

/**
 * Whose name goes on the signature line of a form the registrar signs (docs/15):
 *   1. the Registrar who is signed in and printing it, with their own e-signature when they have one;
 *   2. otherwise (a student printing their own copy), the Registrar who confirmed that student's enrollment, or who reviewed the application;
 *   3. otherwise the school Registrar named in System Settings, with the signature image uploaded there.
 */
final class RegistrarSignerService
{
    /**
     * @return array{name:string,signatureUri:?string,source:string} source is account or settings
     */
    public static function resolve(?User $printer, ?int $enrollmentId = null): array
    {
        if ($printer !== null && $printer->isRegistrar()) {
            return self::of($printer);
        }

        if ($enrollmentId !== null) {
            $row = Database::selectOne(
                'SELECT e.enrolled_by, ea.reviewed_by FROM enrollments e JOIN enrollment_applications ea ON ea.id = e.application_id WHERE e.id = ?',
                [$enrollmentId]
            );
            foreach ([(int) ($row['enrolled_by'] ?? 0), (int) ($row['reviewed_by'] ?? 0)] as $userId) {
                $user = $userId > 0 ? User::find($userId) : null;
                if ($user !== null && $user->isRegistrar()) {
                    return self::of($user);
                }
            }
        }

        return [
            'name'         => trim((string) SystemSetting::get('registrar_name', '')),
            'signatureUri' => SettingsService::registrarSignatureUri(),
            'source'       => 'settings',
        ];
    }

    /** @return array{name:string,signatureUri:?string,source:string} */
    private static function of(User $registrar): array
    {
        return ['name' => $registrar->fullName(), 'signatureUri' => SignatureService::dataUri($registrar->id), 'source' => 'account'];
    }
}