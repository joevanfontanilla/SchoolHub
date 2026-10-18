<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Models\User;

/**
 * E-ID cards and their public QR verification (docs/29). A student (with a student number) and every
 * staff member can have one. The QR holds a random code, never a student number; the public page shows
 * only whether the ID is valid and the minimum needed to match the card to the person.
 */
final class EIdService
{
    public const LOOKUPS_PER_MINUTE = 30;
    private const CODE_PATTERN = '/^[a-f0-9]{24}$/';

    /** The reason this user cannot have an e-ID, or null when they can. */
    public static function ineligibleReason(User $user): ?string
    {
        if ($user->isStudent()) {
            return self::studentNumber($user->id) === null
                ? 'A student number is issued when the student is enrolled. The e-ID is available after that.'
                : null;
        }

        return $user->status === User::STATUS_ACTIVE ? null : 'Only an active account has an e-ID.';
    }

    /**
     * Everything the card and its printout show, creating the e-ID on first use.
     *
     * @return array<string,mixed>
     */
    public static function card(User $user, ?User $issuer = null): array
    {
        $reason = self::ineligibleReason($user);
        if ($reason !== null) {
            throw HttpException::notFound($reason);
        }

        $eid = self::ensure($user, $issuer ?? $user);
        $details = self::details($user, $eid);
        $url = absolute_url('/verify/' . $eid['code']);

        return $details + [
            'code'         => $eid['code'],
            'verifyUrl'    => $url,
            'qrSvg'        => QrCode::svg($url, 4, 'QR code to verify ' . $user->fullName() . '\'s e-ID'),
            'signatureUri' => SignatureService::dataUri($user->id),
            'hasPhoto'     => AvatarService::path($user) !== null,
            'photoUri'     => self::photoUri($user),
            'valid'        => self::isValid($user),
            'scanCount'    => (int) $eid['scan_count'],
            'lastScanned'  => $eid['last_scanned_at'],
            'reissueCount' => (int) $eid['reissue_count'],
        ];
    }

    /**
     * Replace the code, so the old QR stops working at once. The registrar does this for a student and the
     * Super Admin for staff; a holder cannot reissue their own.
     */
    public static function reissue(User $actor, int $userId): void
    {
        $target = User::find($userId);
        if ($target === null) {
            throw HttpException::notFound('That person was not found.');
        }
        $allowed = $target->isStudent() ? $actor->isRegistrar() : $actor->isSuperAdmin();
        if (!$allowed) {
            throw HttpException::forbidden($target->isStudent() ? 'Only the registrar can reissue a student\'s e-ID.' : 'Only the administrator can reissue a staff member\'s e-ID.');
        }
        if (self::ineligibleReason($target) !== null) {
            throw HttpException::notFound('That person has no e-ID yet.');
        }

        Database::transaction(static function () use ($actor, $target): void {
            $eid = self::ensure($target, $actor);
            Database::update('e_ids', [
                'code'          => self::newCode(),
                'issued_at'     => date('Y-m-d H:i:s'),
                'issued_by'     => $actor->id,
                'reissue_count' => (int) $eid['reissue_count'] + 1,
            ], ['id' => (int) $eid['id']]);
            AuditService::log('eid.reissued', 'auth', 'User', $target->id, null, ['reissue_count' => (int) $eid['reissue_count'] + 1],
                'SUCCESS', null, $actor->id, $actor->role_name);
        });
    }

    /** True when the account is active and, for a student, enrolled in the active school year that has not ended. */
    public static function isValid(User $user): bool
    {
        if ($user->status !== User::STATUS_ACTIVE) {
            return false;
        }
        if (!$user->isStudent()) {
            return true;
        }

        return self::enrolledNow($user->id);
    }

    /** Whether this student is enrolled in the active school year, which has not ended. */
    public static function enrolledNow(int $userId): bool
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM enrollments en
               JOIN students s ON s.id = en.student_id
               JOIN terms t ON t.id = en.term_id
               JOIN academic_years ay ON ay.id = t.academic_year_id
              WHERE s.user_id = ? AND en.status = 'ENROLLED' AND ay.is_active = 1 AND ay.end_date >= CURDATE()",
            [$userId]
        ) > 0;
    }

    /** The student a registrar's e-ID page is about. @throws HttpException */
    public static function studentUser(int $studentId): User
    {
        $userId = Database::scalar('SELECT user_id FROM students WHERE id = ?', [$studentId]);
        $user = $userId !== null ? User::find((int) $userId) : null;
        if ($user === null) {
            throw HttpException::notFound('That student was not found.');
        }

        return $user;
    }

    /** The staff member the administrator's e-ID page is about; a student is the registrar's. @throws HttpException */
    public static function staffUser(int $userId): User
    {
        $user = User::find($userId);
        if ($user === null || $user->isStudent()) {
            throw HttpException::notFound('That staff member was not found.');
        }

        return $user;
    }

    // -- The public side ------------------------------------------------------------------

    /**
     * Look a code up for the public page. Returns the minimum needed to match the card to the person when
     * the ID is valid, and null for an unknown code, a disabled account, or an expired ID (the page shows the
     * same thing for all three). A valid lookup is counted.
     *
     * @return array<string,mixed>|null
     * @throws HttpException 429 when this visitor has looked up too many codes this minute
     */
    public static function verify(string $code, string $ip): ?array
    {
        self::throttle($ip);

        $found = self::find($code);
        if ($found === null || !self::isValid($found['user'])) {
            return null;
        }

        Database::query('UPDATE e_ids SET scan_count = scan_count + 1, last_scanned_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), (int) $found['eid']['id']]);

        return self::details($found['user'], $found['eid']) + ['hasPhoto' => AvatarService::path($found['user']) !== null];
    }

    /** The photo to stream for a valid code: path and mime, or null. @return array{path:string,mime:string}|null */
    public static function photoFor(string $code, string $ip): ?array
    {
        self::throttle($ip);

        $found = self::find($code);
        if ($found === null || !self::isValid($found['user'])) {
            return null;
        }

        return AvatarService::photo($found['user']);
    }

    // -- Helpers ---------------------------------------------------------------------------

    /** @return array{user:User,eid:array<string,mixed>}|null */
    private static function find(string $code): ?array
    {
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            return null;
        }
        $eid = Database::selectOne('SELECT * FROM e_ids WHERE code = ?', [$code]);
        $user = $eid !== null ? User::find((int) $eid['user_id']) : null;

        return $eid !== null && $user !== null ? ['user' => $user, 'eid' => $eid] : null;
    }

    /**
     * The fields shared by the card and the public page.
     *
     * @param array<string,mixed> $eid
     * @return array<string,mixed>
     */
    private static function details(User $user, array $eid): array
    {
        $year = Database::selectOne('SELECT code FROM academic_years WHERE is_active = 1 ORDER BY id DESC LIMIT 1');
        $base = ['name' => $user->fullName(), 'schoolYear' => $year['code'] ?? null, 'role' => $user->role_name];

        if (!$user->isStudent()) {
            return $base + ['kind' => 'staff', 'idNumber' => (string) $eid['staff_number'], 'program' => null, 'yearLevel' => null];
        }

        $student = Database::selectOne(
            'SELECT s.student_number, p.code AS program_code, p.name AS program_name, yl.name AS year_level
               FROM students s
          LEFT JOIN programs p ON p.id = s.program_id
          LEFT JOIN year_levels yl ON yl.id = s.year_level_id
              WHERE s.user_id = ?',
            [$user->id]
        ) ?? [];

        return $base + [
            'kind'      => 'student',
            'idNumber'  => (string) ($student['student_number'] ?? ''),
            'program'   => $student['program_name'] ?? null,
            'programCode' => $student['program_code'] ?? null,
            'yearLevel' => $student['year_level'] ?? null,
        ];
    }

    /** @return array<string,mixed> the e-ID row, created (with a staff number for staff) when missing */
    private static function ensure(User $user, User $issuer): array
    {
        $existing = Database::selectOne('SELECT * FROM e_ids WHERE user_id = ?', [$user->id]);
        if ($existing !== null) {
            return $existing;
        }

        return Database::transaction(static function () use ($user, $issuer): array {
            $locked = Database::selectOne('SELECT * FROM e_ids WHERE user_id = ? FOR UPDATE', [$user->id]);
            if ($locked !== null) {
                return $locked;
            }
            $id = Database::insert('e_ids', [
                'user_id'      => $user->id,
                'code'         => self::newCode(),
                'staff_number' => $user->isStudent() ? null : SequenceService::next('staff_id', 'EMP'),
                'issued_at'    => date('Y-m-d H:i:s'),
                'issued_by'    => $issuer->id,
            ]);
            AuditService::log('eid.issued', 'auth', 'User', $user->id, null, null, 'SUCCESS', null, $issuer->id, $issuer->role_name);

            return Database::selectOne('SELECT * FROM e_ids WHERE id = ?', [$id]) ?? [];
        });
    }

    /** The person's photo as a data URI to print on the card, or null. */
    private static function photoUri(User $user): ?string
    {
        $photo = AvatarService::photo($user);
        $bytes = $photo !== null ? (string) @file_get_contents(STORAGE_PATH . '/' . $photo['path']) : '';

        return $bytes === '' ? null : 'data:' . $photo['mime'] . ';base64,' . base64_encode($bytes);
    }

    private static function newCode(): string
    {
        return bin2hex(random_bytes(12));
    }

    private static function studentNumber(int $userId): ?string
    {
        $number = Database::scalar('SELECT student_number FROM students WHERE user_id = ?', [$userId]);

        return $number !== null && $number !== '' ? (string) $number : null;
    }

    /** Count this visitor's lookups this minute and refuse past the limit, so codes cannot be guessed by trial. */
    private static function throttle(string $ip): void
    {
        $minute = date('Y-m-d H:i:00');
        Database::query(
            'INSERT INTO e_id_lookups (ip_address, minute, lookups) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE lookups = lookups + 1',
            [mb_substr($ip, 0, 45), $minute]
        );
        if ((int) Database::scalar('SELECT lookups FROM e_id_lookups WHERE ip_address = ? AND minute = ?', [mb_substr($ip, 0, 45), $minute]) > self::LOOKUPS_PER_MINUTE) {
            throw new HttpException(429, 'Too many lookups. Wait a minute and try again.');
        }
        // Old minutes are of no use; clear them now and then.
        if (random_int(1, 50) === 1) {
            Database::query('DELETE FROM e_id_lookups WHERE minute < ?', [date('Y-m-d H:i:00', time() - 3600)]);
        }
    }
}
