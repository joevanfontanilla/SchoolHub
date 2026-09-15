<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Services\FinanceService;

/**
 * Administrative reports for the Super Admin (docs/04, docs/19, docs/20): the account
 * review, sign-in and activity summaries, and system usage. Read-only. Reports count and
 * summarize; they never show passwords, audit values, or document contents.
 */
final class AdminReport
{
    /** An account unused this long is flagged for review. */
    public const INACTIVE_DAYS = 90;

    public const STATUSES = [User::STATUS_ACTIVE, User::STATUS_PENDING, User::STATUS_LOCKED, User::STATUS_DISABLED];

    // Review flags on an account.
    public const FLAG_NEVER_SIGNED_IN = 'never_signed_in';
    public const FLAG_INACTIVE        = 'inactive';
    public const FLAG_LOCKED          = 'locked';
    public const FLAG_SETUP_EXPIRED   = 'setup_expired';

    public const FLAG_LABELS = [
        self::FLAG_NEVER_SIGNED_IN => 'Never signed in',
        self::FLAG_INACTIVE        => 'No sign-in for ' . self::INACTIVE_DAYS . ' days',
        self::FLAG_LOCKED          => 'Locked',
        self::FLAG_SETUP_EXPIRED   => 'Set-up link expired or unused',
    ];

    /**
     * Every account with its review flags, and counts by role and status. A disabled
     * account is already out of use and carries no flags.
     *
     * @return array{rows:array<int,array<string,mixed>>,matrix:array<string,array<string,int>>,flags:array<string,int>,total:int}
     */
    public static function accounts(?string $role = null, ?string $status = null, bool $flaggedOnly = false): array
    {
        $rows = Database::select(
            "SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.status, u.last_login_at, u.locked_until,
                    u.failed_login_attempts, u.created_at, r.name AS role_name,
                    (SELECT COUNT(*) FROM password_reset_tokens prt
                      WHERE prt.user_id = u.id AND prt.purpose = 'SETUP' AND prt.used_at IS NULL AND prt.expires_at > NOW()) AS open_setup_links
               FROM users u JOIN roles r ON r.id = u.role_id
              ORDER BY r.id, u.last_name, u.first_name, u.username"
        );

        $inactiveBefore = date('Y-m-d H:i:s', time() - self::INACTIVE_DAYS * 86400);
        $now = date('Y-m-d H:i:s');
        $matrix = [];
        $flagCounts = array_fill_keys(array_keys(self::FLAG_LABELS), 0);
        $listed = [];
        foreach ($rows as $row) {
            $flags = [];
            if ($row['status'] !== User::STATUS_DISABLED) {
                if ($row['status'] === User::STATUS_PENDING) {
                    if ((int) $row['open_setup_links'] === 0) {
                        $flags[] = self::FLAG_SETUP_EXPIRED;
                    }
                } elseif ($row['last_login_at'] === null) {
                    $flags[] = self::FLAG_NEVER_SIGNED_IN;
                } elseif ($row['last_login_at'] < $inactiveBefore) {
                    $flags[] = self::FLAG_INACTIVE;
                }
                if ($row['status'] === User::STATUS_LOCKED || ($row['locked_until'] !== null && $row['locked_until'] > $now)) {
                    $flags[] = self::FLAG_LOCKED;
                }
            }
            $row['flags'] = $flags;

            // Counts cover every account; the filters narrow only the list.
            $matrix[$row['role_name']] ??= array_fill_keys(self::STATUSES, 0);
            $matrix[$row['role_name']][$row['status']] = ($matrix[$row['role_name']][$row['status']] ?? 0) + 1;
            foreach ($flags as $flag) {
                $flagCounts[$flag]++;
            }

            if (($role === null || $row['role_name'] === $role) && ($status === null || $row['status'] === $status) && (!$flaggedOnly || $flags !== [])) {
                $listed[] = $row;
            }
        }

        return ['rows' => $listed, 'matrix' => $matrix, 'flags' => $flagCounts, 'total' => count($rows)];
    }

    /**
     * Sign-in attempts over a date range: totals, each day, and where failures come from.
     *
     * @return array{total:int,success:int,failed:int,accounts:int,ips:int,days:array<int,array<string,mixed>>,ip_failures:array<int,array<string,mixed>>,login_failures:array<int,array<string,mixed>>}
     */
    public static function signIns(string $from, string $to): array
    {
        $range = 'attempted_at >= ? AND attempted_at < DATE_ADD(?, INTERVAL 1 DAY)';
        $bindings = [$from, $to];

        $totals = Database::selectOne(
            "SELECT COUNT(*) AS total, SUM(result = 'SUCCESS') AS success, SUM(result <> 'SUCCESS') AS failed,
                    COUNT(DISTINCT CASE WHEN result = 'SUCCESS' THEN user_id END) AS accounts, COUNT(DISTINCT ip_address) AS ips
               FROM login_logs WHERE {$range}",
            $bindings
        ) ?? [];

        $byDay = [];
        foreach (Database::select(
            "SELECT DATE(attempted_at) AS day, SUM(result = 'SUCCESS') AS success, SUM(result <> 'SUCCESS') AS failed
               FROM login_logs WHERE {$range} GROUP BY DATE(attempted_at)",
            $bindings
        ) as $row) {
            $byDay[$row['day']] = ['success' => (int) $row['success'], 'failed' => (int) $row['failed']];
        }
        $days = [];
        for ($day = new \DateTime($from); $day->format('Y-m-d') <= $to; $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $days[] = ['day' => $key] + ($byDay[$key] ?? ['success' => 0, 'failed' => 0]);
        }

        $ipFailures = Database::select(
            "SELECT ip_address, SUM(result <> 'SUCCESS') AS failed, SUM(result = 'SUCCESS') AS success,
                    COUNT(DISTINCT username_attempted) AS logins, MAX(attempted_at) AS last_attempt
               FROM login_logs WHERE {$range}
              GROUP BY ip_address HAVING failed > 0
              ORDER BY failed DESC, last_attempt DESC LIMIT 10",
            $bindings
        );

        $loginFailures = Database::select(
            "SELECT l.username_attempted, SUM(l.result <> 'SUCCESS') AS failed, SUM(l.result = 'SUCCESS') AS success,
                    COUNT(DISTINCT l.ip_address) AS ips, MAX(l.attempted_at) AS last_attempt, MAX(l.user_id) AS user_id, MAX(u.status) AS status
               FROM login_logs l LEFT JOIN users u ON u.id = l.user_id
              WHERE l.attempted_at >= ? AND l.attempted_at < DATE_ADD(?, INTERVAL 1 DAY)
              GROUP BY l.username_attempted HAVING failed > 0
              ORDER BY failed DESC, last_attempt DESC LIMIT 10",
            $bindings
        );

        return [
            'total'          => (int) ($totals['total'] ?? 0),
            'success'        => (int) ($totals['success'] ?? 0),
            'failed'         => (int) ($totals['failed'] ?? 0),
            'accounts'       => (int) ($totals['accounts'] ?? 0),
            'ips'            => (int) ($totals['ips'] ?? 0),
            'days'           => $days,
            'ip_failures'    => $ipFailures,
            'login_failures' => $loginFailures,
        ];
    }

    /**
     * Audit log activity over a date range: counts by module, role, person, and action.
     * Only counts: the values recorded in each entry stay in the audit log itself.
     *
     * @return array{total:int,failures:int,modules:array<int,array<string,mixed>>,roles:array<int,array<string,mixed>>,actors:array<int,array<string,mixed>>,actions:array<int,array<string,mixed>>,days:array<int,array<string,mixed>>}
     */
    public static function activity(string $from, string $to): array
    {
        $range = 'a.created_at >= ? AND a.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
        $bindings = [$from, $to];

        // One pass over the period at the finest grouping the report needs; the totals and
        // the module, role, and action counts are added up from it. A period of a busy
        // month holds tens of thousands of entries, and each separate GROUP BY scanned them all.
        $total = 0;
        $failures = 0;
        $modules = [];
        $roles = [];
        $actions = [];
        foreach (Database::select(
            "SELECT a.module, a.action, a.actor_role, a.result <> 'SUCCESS' AS failed, COUNT(*) AS entries
               FROM audit_logs a WHERE {$range}
              GROUP BY a.module, a.action, a.actor_role, failed",
            $bindings
        ) as $row) {
            $entries = (int) $row['entries'];
            $failed = (int) $row['failed'] === 1 ? $entries : 0;
            $total += $entries;
            $failures += $failed;
            foreach ([[&$modules, $row['module'], ['module' => $row['module']]], [&$roles, $row['actor_role'], ['actor_role' => $row['actor_role']]],
                      [&$actions, $row['action'] . "\0" . $row['module'], ['action' => $row['action'], 'module' => $row['module']]]] as [&$bucket, $key, $fields]) {
                $bucket[$key] ??= $fields + ['entries' => 0, 'failures' => 0];
                $bucket[$key]['entries'] += $entries;
                $bucket[$key]['failures'] += $failed;
            }
            unset($bucket);
        }
        $byEntries = static function (array $rows, string $name, int $limit): array {
            usort($rows, static fn (array $a, array $b) => [$b['entries'], $a[$name]] <=> [$a['entries'], $b[$name]]);
            return array_slice($rows, 0, $limit);
        };

        $byDay = array_column(Database::select(
            "SELECT DATE(a.created_at) AS day, COUNT(*) AS entries FROM audit_logs a WHERE {$range} GROUP BY DATE(a.created_at)",
            $bindings
        ), 'entries', 'day');
        $days = [];
        for ($day = new \DateTime($from); $day->format('Y-m-d') <= $to; $day->modify('+1 day')) {
            $days[] = ['day' => $day->format('Y-m-d'), 'entries' => (int) ($byDay[$day->format('Y-m-d')] ?? 0)];
        }

        $actors = Database::select(
            "SELECT a.user_id, a.actor_role, COUNT(*) AS entries, SUM(a.result <> 'SUCCESS') AS failures, MAX(a.created_at) AS last_entry
               FROM audit_logs a
              WHERE {$range} AND a.user_id IS NOT NULL
              GROUP BY a.user_id, a.actor_role
              ORDER BY entries DESC LIMIT 10",
            $bindings
        );
        // Names for at most ten people, looked up after grouping rather than joined into it.
        $ids = array_values(array_unique(array_map(static fn (array $row) => (int) $row['user_id'], $actors)));
        $people = [];
        if ($ids !== []) {
            $in = implode(', ', array_fill(0, count($ids), '?'));
            foreach (Database::select("SELECT id, username, CONCAT(first_name, ' ', last_name) AS actor_name FROM users WHERE id IN ({$in})", $ids) as $person) {
                $people[(int) $person['id']] = $person;
            }
        }
        foreach ($actors as &$actor) {
            $actor += ['username' => $people[(int) $actor['user_id']]['username'] ?? null, 'actor_name' => $people[(int) $actor['user_id']]['actor_name'] ?? null];
        }
        unset($actor);

        return [
            'total'    => $total,
            'failures' => $failures,
            'modules'  => $byEntries(array_values($modules), 'module', 100),
            'roles'    => $byEntries(array_values($roles), 'actor_role', 100),
            'actors'   => $actors,
            'actions'  => $byEntries(array_values($actions), 'action', 15),
            'days'     => $days,
        ];
    }
    /**
     * How much the system holds: accounts, the recent terms' enrollment and collections,
     * communication, and storage.
     *
     * @return array<string,mixed>
     */
    public static function usage(): array
    {
        $roles = array_map('intval', array_column(Database::select(
            'SELECT r.name, COUNT(u.id) AS accounts FROM roles r LEFT JOIN users u ON u.id IS NOT NULL AND u.role_id = r.id GROUP BY r.id ORDER BY r.id'
        ), 'accounts', 'name'));

        $terms = Database::select(
            "SELECT t.id, t.name, ay.code AS academic_year_code, t.start_date,
                    (SELECT COUNT(*) FROM enrollment_applications ea WHERE ea.term_id = t.id AND ea.application_status <> 'DRAFT') AS applications,
                    (SELECT COUNT(*) FROM enrollments e WHERE e.term_id = t.id AND e.status = 'ENROLLED') AS enrolled,
                    (SELECT COUNT(*) FROM class_schedules cs WHERE cs.term_id = t.id AND cs.status = 'ACTIVE') AS classes,
                    (SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN fee_assessments fa ON fa.id = p.fee_assessment_id
                      JOIN enrollment_applications ea ON ea.id = fa.application_id WHERE ea.term_id = t.id) AS collected
               FROM terms t JOIN academic_years ay ON ay.id = t.academic_year_id
              WHERE t.start_date <= DATE_ADD(CURDATE(), INTERVAL 1 YEAR)
              ORDER BY t.start_date DESC LIMIT 6"
        );
        foreach ($terms as &$term) {
            $term['collected_cents'] = FinanceService::cents($term['collected']);
        }
        unset($term);

        $communication = Database::selectOne(
            "SELECT (SELECT COUNT(*) FROM announcements WHERE status = 'PUBLISHED') AS announcements,
                    (SELECT COUNT(*) FROM notifications) AS notifications,
                    (SELECT COUNT(*) FROM notifications WHERE state = 'UNREAD') AS unread"
        ) ?? [];

        $records = Database::selectOne(
            "SELECT (SELECT COUNT(*) FROM enrollment_requirement_files) AS requirement_files,
                    (SELECT COALESCE(SUM(file_size), 0) FROM enrollment_requirement_files) AS requirement_bytes,
                    (SELECT COUNT(*) FROM payment_submissions) AS proofs,
                    (SELECT COALESCE(SUM(file_size), 0) FROM payment_submissions) AS proof_bytes,
                    (SELECT COUNT(*) FROM audit_logs) AS audit_entries,
                    (SELECT COUNT(*) FROM login_logs) AS sign_in_attempts,
                    (SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE()) AS database_bytes"
        ) ?? [];

        return [
            'roles'         => $roles,
            'terms'         => $terms,
            'communication' => array_map('intval', $communication),
            'records'       => array_map('intval', $records),
            'storage'       => self::storage(),
        ];
    }

    /**
     * Files and bytes in each storage folder, read from the disk.
     *
     * @return array<string,array{files:int,bytes:int}>
     */
    public static function storage(): array
    {
        $result = [];
        foreach (['uploads', 'backups', 'logs'] as $folder) {
            $files = 0;
            $bytes = 0;
            $path = STORAGE_PATH . '/' . $folder;
            if (is_dir($path)) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    if ($file->isFile() && $file->getFilename() !== '.gitkeep') {
                        $files++;
                        $bytes += $file->getSize();
                    }
                }
            }
            $result[$folder] = ['files' => $files, 'bytes' => $bytes];
        }
        return $result;
    }
}
