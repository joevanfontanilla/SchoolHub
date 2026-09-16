<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Student requests for official documents (docs/08). Written only through
 * CredentialRequestService.
 */
final class CredentialRequest
{
    /** Document types a student may request: code => label. */
    public const TYPES = [
        'TRANSCRIPT' => 'Transcript of Records (TOR)',
        'GOOD_MORAL' => 'Certificate of Good Moral Character',
        'ENROLLMENT' => 'Certificate of Enrollment',
        'GRADES'     => 'Copy of Grades',
        'OTHER'      => 'Other document',
    ];

    /** Statuses in order; the last two are terminal. */
    public const STATUSES = ['REQUESTED', 'PROCESSING', 'READY', 'RELEASED', 'REJECTED', 'CANCELLED'];
    public const OPEN_STATUSES = ['REQUESTED', 'PROCESSING', 'READY'];

    public const STATUS_LABELS = [
        'REQUESTED'  => 'Requested',
        'PROCESSING' => 'Processing',
        'READY'      => 'Ready for pickup',
        'RELEASED'   => 'Released',
        'REJECTED'   => 'Rejected',
        'CANCELLED'  => 'Cancelled',
    ];

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM credential_requests WHERE id = ?', [$id]);
    }

    /**
     * A student's own requests, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forStudent(int $studentId): array
    {
        return Database::select(
            'SELECT * FROM credential_requests WHERE student_id = ? ORDER BY requested_at DESC, id DESC',
            [$studentId]
        );
    }

    /**
     * The registrar queue, optionally filtered by status; open requests first, oldest first
     * within that so the longest-waiting is on top.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function queue(string $status = ''): array
    {
        $where = '';
        $bindings = [];
        if ($status !== '' && in_array($status, self::STATUSES, true)) {
            $where = 'WHERE cr.status = ?';
            $bindings[] = $status;
        } elseif ($status === 'OPEN') {
            $in = implode(',', array_fill(0, count(self::OPEN_STATUSES), '?'));
            $where = "WHERE cr.status IN ({$in})";
            $bindings = self::OPEN_STATUSES;
        }

        return Database::select(
            "SELECT cr.*, st.student_number, CONCAT(u.first_name, ' ', u.last_name) AS student_name,
                    p.code AS program_code
               FROM credential_requests cr
               JOIN students st ON st.id = cr.student_id
               JOIN users u     ON u.id = st.user_id
          LEFT JOIN programs p  ON p.id = st.program_id
              {$where}
              ORDER BY FIELD(cr.status, 'REQUESTED','PROCESSING','READY','RELEASED','REJECTED','CANCELLED'), cr.requested_at",
            $bindings
        );
    }

    /** Count of requests still needing the registrar's attention (for a dashboard badge). */
    public static function openCount(): int
    {
        $in = implode(',', array_fill(0, count(self::OPEN_STATUSES), '?'));
        return (int) Database::scalar("SELECT COUNT(*) FROM credential_requests WHERE status IN ({$in})", self::OPEN_STATUSES);
    }

    public static function typeLabel(string $type): string
    {
        return self::TYPES[$type] ?? $type;
    }
}
