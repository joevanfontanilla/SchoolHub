<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Announcements (docs/16).
 *
 * Visibility is always filtered in the query — never only in the view.
 */
final class Announcement
{
    /** Published, not expired, and flagged public. For the public website. */
    public static function publicList(int $limit = 20, int $offset = 0): array
    {
        return Database::select(
            "SELECT a.id, a.title, a.slug, a.content, a.category, a.image_path, a.published_at
               FROM announcements a
              WHERE a.is_public = 1
                AND a.status = 'PUBLISHED'
                AND (a.published_at IS NULL OR a.published_at <= NOW())
                AND (a.expires_at IS NULL OR a.expires_at > NOW())
              ORDER BY a.published_at DESC, a.id DESC
              LIMIT {$limit} OFFSET {$offset}"
        );
    }

    public static function publicCount(): int
    {
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM announcements
              WHERE is_public = 1 AND status = 'PUBLISHED'
                AND (published_at IS NULL OR published_at <= NOW())
                AND (expires_at IS NULL OR expires_at > NOW())"
        );
    }

    public static function findPublicBySlug(string $slug): ?array
    {
        return Database::selectOne(
            "SELECT a.*, u.first_name, u.last_name
               FROM announcements a
          LEFT JOIN users u ON u.id = a.author_user_id
              WHERE a.slug = ?
                AND a.is_public = 1
                AND a.status = 'PUBLISHED'
                AND (a.published_at IS NULL OR a.published_at <= NOW())
                AND (a.expires_at IS NULL OR a.expires_at > NOW())",
            [$slug]
        );
    }

    /**
     * Announcements visible to one signed-in user: everyone-targeted, plus any
     * targeted at their role.
     */
    public static function forUser(User $user, int $limit = 10): array
    {
        return Database::select(
            "SELECT DISTINCT a.id, a.title, a.slug, a.content, a.category, a.published_at
               FROM announcements a
          LEFT JOIN announcement_targets t ON t.announcement_id = a.id
              WHERE a.status = 'PUBLISHED'
                AND (a.published_at IS NULL OR a.published_at <= NOW())
                AND (a.expires_at IS NULL OR a.expires_at > NOW())
                AND (
                    a.audience = 'EVERYONE'
                    OR (t.target_type = 'ROLE' AND t.target_id = ?)
                )
              ORDER BY a.published_at DESC, a.id DESC
              LIMIT {$limit}",
            [$user->role_id]
        );
    }
}
