<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Announcements (docs/16).
 *
 * Visibility is always filtered in the query, never only in the view. Who an
 * announcement reaches is defined ONCE, in audienceSql(), and used both to list
 * announcements for a user and to write the notifications at publish time, so the
 * people notified are exactly the people who can open it.
 */
final class Announcement
{
    public const DRAFT     = 'DRAFT';
    public const PUBLISHED = 'PUBLISHED';
    public const ARCHIVED  = 'ARCHIVED';

    public const CATEGORIES = ['General', 'Enrollment', 'Academic', 'Finance', 'Events', 'Advisory'];

    public const TARGET_ROLE       = 'ROLE';
    public const TARGET_PROGRAM    = 'PROGRAM';
    public const TARGET_YEAR_LEVEL = 'YEAR_LEVEL';

    /**
     * SQL condition: announcement $a is live, i.e. published, due, and not expired.
     * Binds two values: now, now.
     */
    public static function liveSql(string $a = 'a'): string
    {
        return "{$a}.status = 'PUBLISHED' AND {$a}.published_at IS NOT NULL AND {$a}.published_at <= ?
                AND ({$a}.expires_at IS NULL OR {$a}.expires_at > ?)";
    }

    /**
     * SQL condition: user $u is in the audience of announcement $a. Binds nothing.
     *
     *   EVERYONE  every active user (a temporarily locked account still counts; disabled
     *             and not-yet-activated accounts do not).
     *   TARGETED  an active user whose role is one of the ROLE targets. For students
     *             only, PROGRAM targets (if any) must include their program AND
     *             YEAR_LEVEL targets (if any) must include their year level. Program and
     *             year level come from the student's latest application that was not
     *             rejected or cancelled.
     */
    public static function audienceSql(string $a = 'a', string $u = 'u'): string
    {
        $current = static fn (string $column): string =>
            "(SELECT ea.{$column} FROM enrollment_applications ea JOIN students s ON s.id = ea.student_id
               WHERE s.user_id = {$u}.id AND ea.application_status NOT IN ('REJECTED', 'CANCELLED')
               ORDER BY ea.created_at DESC, ea.id DESC LIMIT 1)";
        $narrow = static fn (string $type, string $column): string =>
            "(NOT EXISTS (SELECT 1 FROM announcement_targets tn WHERE tn.announcement_id = {$a}.id AND tn.target_type = '{$type}')
              OR EXISTS (SELECT 1 FROM announcement_targets tn WHERE tn.announcement_id = {$a}.id AND tn.target_type = '{$type}'
                          AND tn.target_id = " . $current($column) . '))';

        return "({$u}.status IN ('Active', 'Locked') AND (
                    {$a}.audience = 'EVERYONE'
                    OR ({$a}.audience = 'TARGETED'
                        AND EXISTS (SELECT 1 FROM announcement_targets tr WHERE tr.announcement_id = {$a}.id AND tr.target_type = 'ROLE' AND tr.target_id = {$u}.role_id)
                        AND ({$u}.role_id <> (SELECT id FROM roles WHERE name = 'Student')
                             OR (" . $narrow(self::TARGET_PROGRAM, 'program_id') . ' AND ' . $narrow(self::TARGET_YEAR_LEVEL, 'year_level_id') . ')))
                ))';
    }

    private static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    // -- Public website ---------------------------------------------------------------

    /** Live and flagged public. For the public website. */
    public static function publicList(int $limit = 20, int $offset = 0): array
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        return Database::select(
            'SELECT a.id, a.title, a.slug, a.content, a.category, a.image_path, a.image_alt, a.is_urgent, a.published_at
               FROM announcements a
              WHERE a.is_public = 1 AND ' . self::liveSql() . "
              ORDER BY a.is_urgent DESC, a.published_at DESC, a.id DESC
              LIMIT {$limit} OFFSET {$offset}",
            [self::now(), self::now()]
        );
    }

    public static function publicCount(): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM announcements a WHERE a.is_public = 1 AND ' . self::liveSql(), [self::now(), self::now()]);
    }

    public static function findPublicBySlug(string $slug): ?array
    {
        return Database::selectOne(
            'SELECT a.*, u.first_name, u.last_name
               FROM announcements a
          LEFT JOIN users u ON u.id = a.author_user_id
              WHERE a.slug = ? AND a.is_public = 1 AND ' . self::liveSql(),
            [$slug, self::now(), self::now()]
        );
    }

    // -- Signed-in readers -------------------------------------------------------------

    /**
     * Live announcements whose audience includes this user, urgent first, then newest.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forUser(User $user, int $limit = 10, int $offset = 0, ?string $category = null): array
    {
        [$where, $bindings] = self::forUserWhere($user, $category);
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        return Database::select(
            "SELECT a.id, a.title, a.slug, a.content, a.category, a.image_path, a.image_alt, a.audience, a.is_urgent, a.is_public, a.published_at, a.expires_at
               FROM announcements a
              WHERE {$where}
              ORDER BY a.is_urgent DESC, a.published_at DESC, a.id DESC
              LIMIT {$limit} OFFSET {$offset}",
            $bindings
        );
    }

    public static function countForUser(User $user, ?string $category = null): int
    {
        [$where, $bindings] = self::forUserWhere($user, $category);
        return (int) Database::scalar("SELECT COUNT(*) FROM announcements a WHERE {$where}", $bindings);
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private static function forUserWhere(User $user, ?string $category): array
    {
        $where = self::liveSql() . ' AND EXISTS (SELECT 1 FROM users u WHERE u.id = ? AND ' . self::audienceSql() . ')';
        $bindings = [self::now(), self::now(), $user->id];
        if ($category !== null && $category !== '') {
            $where .= ' AND a.category = ?';
            $bindings[] = $category;
        }
        return [$where, $bindings];
    }

    /** Whether this user may read this announcement now (live and in its audience). */
    public static function isVisibleTo(int $announcementId, User $user): bool
    {
        return Database::scalar(
            'SELECT 1 FROM announcements a JOIN users u ON u.id = ?
              WHERE a.id = ? AND ' . self::liveSql() . ' AND ' . self::audienceSql(),
            [$user->id, $announcementId, self::now(), self::now()]
        ) !== null;
    }

    /**
     * Urgent live announcements for this user that they have not dismissed: the portal banner.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function urgentFor(User $user, int $limit = 3): array
    {
        $limit = max(1, $limit);
        return Database::select(
            'SELECT a.id, a.title, a.category, a.published_at, a.expires_at
               FROM announcements a JOIN users u ON u.id = ?
              WHERE a.is_urgent = 1 AND ' . self::liveSql() . ' AND ' . self::audienceSql() . "
                AND NOT EXISTS (SELECT 1 FROM announcement_dismissals d WHERE d.announcement_id = a.id AND d.user_id = u.id)
              ORDER BY a.published_at DESC, a.id DESC
              LIMIT {$limit}",
            [$user->id, self::now(), self::now()]
        );
    }

    // -- Management ------------------------------------------------------------------

    /** @return array<string,mixed>|null with author and publisher names */
    public static function find(int $id): ?array
    {
        return Database::selectOne(
            "SELECT a.*, CONCAT(au.first_name, ' ', au.last_name) AS author_name, CONCAT(pb.first_name, ' ', pb.last_name) AS publisher_name
               FROM announcements a
          LEFT JOIN users au ON au.id = a.author_user_id
          LEFT JOIN users pb ON pb.id = a.published_by
              WHERE a.id = ?",
            [$id]
        );
    }

    /**
     * Every announcement for the managers, with a derived display state.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function manageList(string $state = '', string $q = ''): array
    {
        $now = self::now();
        $stateSql = "CASE
                WHEN a.status = 'DRAFT' THEN 'DRAFT'
                WHEN a.status = 'ARCHIVED' THEN 'ARCHIVED'
                WHEN a.published_at > ? THEN 'SCHEDULED'
                WHEN a.expires_at IS NOT NULL AND a.expires_at <= ? THEN 'EXPIRED'
                ELSE 'PUBLISHED' END";
        $where = ['1 = 1'];
        // The state expression appears in the SELECT list first, then possibly in WHERE.
        $bindings = [$now, $now];
        if (in_array($state, ['DRAFT', 'SCHEDULED', 'PUBLISHED', 'EXPIRED', 'ARCHIVED'], true)) {
            $where[] = "{$stateSql} = ?";
            array_push($bindings, $now, $now, $state);
        }
        if ($q !== '') {
            $where[] = '(a.title LIKE ? OR a.content LIKE ?)';
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            array_push($bindings, $like, $like);
        }

        return Database::select(
            "SELECT a.id, a.title, a.category, a.audience, a.is_public, a.is_urgent, a.status, a.published_at, a.expires_at,
                    a.recipient_count, a.updated_at, {$stateSql} AS state, CONCAT(au.first_name, ' ', au.last_name) AS author_name
               FROM announcements a
          LEFT JOIN users au ON au.id = a.author_user_id
              WHERE " . implode(' AND ', $where) . '
              ORDER BY (a.status = \'DRAFT\') DESC, COALESCE(a.published_at, a.updated_at) DESC, a.id DESC
              LIMIT 300',
            $bindings
        );
    }

    /** The display state of one announcement row: DRAFT, SCHEDULED, PUBLISHED, EXPIRED, or ARCHIVED. */
    public static function state(array $announcement): string
    {
        $now = self::now();
        return match (true) {
            $announcement['status'] === self::DRAFT    => 'DRAFT',
            $announcement['status'] === self::ARCHIVED => 'ARCHIVED',
            $announcement['published_at'] !== null && $announcement['published_at'] > $now => 'SCHEDULED',
            $announcement['expires_at'] !== null && $announcement['expires_at'] <= $now     => 'EXPIRED',
            default => 'PUBLISHED',
        };
    }

    /** @return array{roles:array<int,int>,programs:array<int,int>,year_levels:array<int,int>} */
    public static function targets(int $id): array
    {
        $targets = ['roles' => [], 'programs' => [], 'year_levels' => []];
        foreach (Database::select('SELECT target_type, target_id FROM announcement_targets WHERE announcement_id = ? ORDER BY target_id', [$id]) as $row) {
            $key = match ($row['target_type']) {
                self::TARGET_ROLE    => 'roles',
                self::TARGET_PROGRAM => 'programs',
                default              => 'year_levels',
            };
            $targets[$key][] = (int) $row['target_id'];
        }
        return $targets;
    }

    /** @return array<int,array{id:int,name:string}> the groups an announcement can target */
    public static function roleOptions(): array
    {
        return Database::select('SELECT id, name FROM roles ORDER BY id');
    }

    /** @return array<int,array{id:int,name:string}> */
    public static function yearLevelOptions(): array
    {
        return Database::select('SELECT id, name FROM year_levels ORDER BY sequence');
    }

    /** "Everyone", or e.g. "Students (BSA, BSTM · First Year), Teachers". */
    public static function audienceLabel(array $announcement): string
    {
        return self::audienceLabels([$announcement])[(int) $announcement['id']] ?? '';
    }

    /**
     * Audience labels for many announcements at once (the targets and names are read
     * once for the whole list, not per row).
     *
     * @param array<int,array<string,mixed>> $announcements rows with id and audience
     * @return array<int,string> id => label
     */
    public static function audienceLabels(array $announcements): array
    {
        $labels = [];
        $targetedIds = [];
        foreach ($announcements as $announcement) {
            if ($announcement['audience'] === 'EVERYONE') {
                $labels[(int) $announcement['id']] = 'Everyone';
            } else {
                $targetedIds[] = (int) $announcement['id'];
            }
        }
        if ($targetedIds === []) {
            return $labels;
        }

        $in = implode(',', array_fill(0, count($targetedIds), '?'));
        $targets = [];
        foreach (Database::select("SELECT announcement_id, target_type, target_id FROM announcement_targets WHERE announcement_id IN ({$in}) ORDER BY target_id", $targetedIds) as $row) {
            $targets[(int) $row['announcement_id']][$row['target_type']][] = (int) $row['target_id'];
        }
        $roleNames = array_column(Database::select('SELECT id, name FROM roles'), 'name', 'id');
        $programCodes = array_column(Database::select('SELECT id, code FROM programs ORDER BY code'), 'code', 'id');
        $yearNames = array_column(Database::select('SELECT id, name FROM year_levels ORDER BY sequence'), 'name', 'id');

        foreach ($targetedIds as $id) {
            $programs = array_values(array_intersect_key($programCodes, array_flip($targets[$id][self::TARGET_PROGRAM] ?? [])));
            $years = array_values(array_intersect_key($yearNames, array_flip($targets[$id][self::TARGET_YEAR_LEVEL] ?? [])));
            $parts = [];
            foreach ($targets[$id][self::TARGET_ROLE] ?? [] as $roleId) {
                $role = $roleNames[$roleId] ?? 'Role';
                $name = $role === User::ROLE_SUPER_ADMIN ? 'Super Admins' : $role . 's';
                if ($role === User::ROLE_STUDENT && ($programs !== [] || $years !== [])) {
                    $name .= ' (' . implode(' · ', array_filter([implode(', ', $programs), implode(', ', $years)])) . ')';
                }
                $parts[] = $name;
            }
            $labels[$id] = implode(', ', $parts);
        }
        return $labels;
    }
}
