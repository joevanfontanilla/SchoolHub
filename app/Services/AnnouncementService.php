<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\UploadedFile;
use App\Core\Validator;
use App\Core\ValidationException;
use App\Models\Announcement;
use App\Models\User;

/**
 * Writing, publishing, and delivering announcements (docs/16).
 *
 * Lifecycle: DRAFT -> PUBLISHED (immediately, or scheduled for a later date) ->
 * ARCHIVED -> back to DRAFT. A draft that was never published may be deleted;
 * anything that was published is archived instead, so the record stays.
 *
 * Writing needs `announcements.manage`; publishing needs `announcements.publish`
 * (the Registrar and Super Admin). When an announcement becomes live, every user
 * in its audience gets one notification, exactly once (dispatch()).
 */
final class AnnouncementService
{
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png'];
    public const IMAGE_MAX_BYTES = 2 * 1024 * 1024;

    /** A dispatch sweep runs at most once per request. */
    private static bool $swept = false;

    /**
     * Create (id null) or update an announcement.
     *
     * @param array<string,mixed> $input title, content, category, audience, roles[], programs[],
     *                                   year_levels[], is_public, is_urgent, published_at, expires_at,
     *                                   image_alt, remove_image
     * @param string $action 'draft' to save without publishing, 'publish' to publish a draft,
     *                       'save' to keep the current status (for a published announcement)
     * @return int the announcement id
     */
    public static function save(User $actor, ?int $id, array $input, ?UploadedFile $image = null, string $action = 'draft'): int
    {
        self::assertCan($actor, 'announcements.manage', 'You are not allowed to write announcements.');
        if ($action === 'publish') {
            self::assertCan($actor, 'announcements.publish', 'You are not allowed to publish announcements.');
        }

        $existing = $id === null ? null : Announcement::find($id);
        if ($id !== null && $existing === null) {
            throw HttpException::notFound('That announcement was not found.');
        }
        if ($existing !== null && $existing['status'] === Announcement::ARCHIVED) {
            throw ValidationException::withMessage('This announcement is archived. Restore it to a draft before editing.');
        }
        if ($action === 'publish' && $existing !== null && $existing['status'] !== Announcement::DRAFT) {
            $action = 'save'; // already published: publishing again must not move its date
        }
        $status = match (true) {
            $action === 'publish' => Announcement::PUBLISHED,
            $existing !== null    => $existing['status'],
            default               => Announcement::DRAFT,
        };

        $clean = self::validate($input, $existing, $status, $image);

        // Validate and store the new image before the transaction; remove it again if the
        // transaction fails, and remove the replaced one only after it succeeds.
        $newImage = null;
        if ($image !== null && $image->error() !== UPLOAD_ERR_NO_FILE) {
            $extension = DocumentUploadService::assertGenuineFile($image, self::IMAGE_EXTENSIONS, self::IMAGE_MAX_BYTES, 'an announcement image');
            $relative = 'uploads/announcements/' . bin2hex(random_bytes(16)) . '.' . ($extension === 'jpeg' ? 'jpg' : $extension);
            $newImage = ['path' => $relative, 'mime' => $image->detectedMimeType()];
            $image->moveTo(STORAGE_PATH . '/' . $relative);
        }
        $removeOld = $existing !== null && $existing['image_path'] !== null && ($newImage !== null || !empty($input['remove_image']));

        try {
            $savedId = Database::transaction(static function () use ($actor, $existing, $clean, $status, $action, $newImage, $removeOld): int {
                $now = date('Y-m-d H:i:s');
                $row = [
                    'title'     => $clean['title'],
                    'content'   => $clean['content'],
                    'category'  => $clean['category'],
                    'audience'  => $clean['audience'],
                    'is_public' => $clean['is_public'],
                    'is_urgent' => $clean['is_urgent'],
                    'expires_at' => $clean['expires_at'],
                    'image_alt' => $clean['image_alt'],
                    'status'    => $status,
                ];
                if ($newImage !== null) {
                    $row['image_path'] = $newImage['path'];
                    $row['image_mime'] = $newImage['mime'];
                } elseif ($removeOld) {
                    $row['image_path'] = null;
                    $row['image_mime'] = null;
                    $row['image_alt'] = null;
                }

                // The publish date can change until the audience has been notified.
                if ($existing === null || $existing['notified_at'] === null) {
                    $row['published_at'] = $clean['published_at'];
                }
                if ($action === 'publish') {
                    // Publishing now, or at the chosen later date. A date already past means now.
                    $row['published_at'] = $clean['published_at'] !== null && $clean['published_at'] > $now ? $clean['published_at'] : $now;
                    $row['published_by'] = $actor->id;
                }

                if ($existing === null) {
                    $row['slug'] = self::uniqueSlug($clean['title']);
                    $row['author_user_id'] = $actor->id;
                    $id = Database::insert('announcements', $row);
                } else {
                    $id = (int) $existing['id'];
                    Database::selectOne('SELECT id FROM announcements WHERE id = ? FOR UPDATE', [$id]);
                    // The public address stays fixed once published.
                    if ($existing['status'] === Announcement::DRAFT && $existing['published_by'] === null) {
                        $row['slug'] = self::uniqueSlug($clean['title'], $id);
                    }
                    Database::update('announcements', $row, ['id' => $id]);
                    Database::query('DELETE FROM announcement_targets WHERE announcement_id = ?', [$id]);
                }

                foreach ([Announcement::TARGET_ROLE => 'roles', Announcement::TARGET_PROGRAM => 'programs', Announcement::TARGET_YEAR_LEVEL => 'year_levels'] as $type => $key) {
                    foreach ($clean[$key] as $targetId) {
                        Database::insert('announcement_targets', ['announcement_id' => $id, 'target_type' => $type, 'target_id' => $targetId]);
                    }
                }

                $auditAction = match (true) {
                    $existing === null && $status === Announcement::PUBLISHED => 'announcement.published',
                    $existing === null                                      => 'announcement.created',
                    $action === 'publish'                                   => 'announcement.published',
                    default                                                 => 'announcement.updated',
                };
                AuditService::log($auditAction, 'communication', 'Announcement', $id,
                    $existing === null ? null : ['title' => $existing['title'], 'status' => $existing['status']],
                    ['title' => $clean['title'], 'status' => $status, 'audience' => $clean['audience'], 'urgent' => $clean['is_urgent'], 'public' => $clean['is_public']],
                    'SUCCESS', null, $actor->id, $actor->role_name);

                self::dispatch($id);

                return $id;
            });
        } catch (\Throwable $e) {
            if ($newImage !== null) {
                @unlink(STORAGE_PATH . '/' . $newImage['path']);
            }
            throw $e;
        }

        if ($removeOld) {
            @unlink(STORAGE_PATH . '/' . $existing['image_path']);
        }

        return $savedId;
    }

    /** PUBLISHED (live, scheduled, or expired) -> ARCHIVED: hidden everywhere, kept on record. */
    public static function archive(User $actor, int $id): void
    {
        self::assertCan($actor, 'announcements.publish', 'You are not allowed to archive announcements.');
        self::changeStatus($actor, $id, Announcement::PUBLISHED, Announcement::ARCHIVED, 'announcement.archived', 'Only a published announcement can be archived.');
    }

    /** ARCHIVED -> DRAFT, to edit and publish again. Its audience is not notified a second time. */
    public static function restore(User $actor, int $id): void
    {
        self::assertCan($actor, 'announcements.manage', 'You are not allowed to restore announcements.');
        self::changeStatus($actor, $id, Announcement::ARCHIVED, Announcement::DRAFT, 'announcement.restored', 'Only an archived announcement can be restored.');
    }

    /** A draft that was never published may be deleted outright. */
    public static function delete(User $actor, int $id): void
    {
        self::assertCan($actor, 'announcements.manage', 'You are not allowed to delete announcements.');
        $imagePath = Database::transaction(static function () use ($actor, $id): ?string {
            $announcement = Database::selectOne('SELECT * FROM announcements WHERE id = ? FOR UPDATE', [$id]);
            if ($announcement === null) {
                throw HttpException::notFound('That announcement was not found.');
            }
            if ($announcement['status'] !== Announcement::DRAFT || $announcement['published_by'] !== null) {
                throw ValidationException::withMessage('Only a draft that was never published can be deleted. Archive it instead.');
            }
            Database::query('DELETE FROM announcements WHERE id = ?', [$id]);
            AuditService::log('announcement.deleted', 'communication', 'Announcement', $id, ['title' => $announcement['title']], null, 'SUCCESS', null, $actor->id, $actor->role_name);
            return $announcement['image_path'];
        });
        if ($imagePath !== null) {
            @unlink(STORAGE_PATH . '/' . $imagePath);
        }
    }

    /** Hide an urgent banner for one user. The announcement must be visible to them. */
    public static function dismiss(User $user, int $id): void
    {
        if (!Announcement::isVisibleTo($id, $user)) {
            throw HttpException::notFound('That announcement is not available.');
        }
        Database::query(
            'INSERT IGNORE INTO announcement_dismissals (announcement_id, user_id, dismissed_at) VALUES (?, ?, ?)',
            [$id, $user->id, date('Y-m-d H:i:s')]
        );
    }

    /**
     * Notify the audience of one announcement if it is live and has not been sent yet.
     * One INSERT ... SELECT using the same audience condition as reading, so recipients
     * and readers never differ. The row lock and notified_at make it happen once.
     *
     * @return int notifications written
     */
    public static function dispatch(int $id): int
    {
        return Database::transaction(static function () use ($id): int {
            $now = date('Y-m-d H:i:s');
            $announcement = Database::selectOne(
                'SELECT a.* FROM announcements a WHERE a.id = ? AND a.notified_at IS NULL AND ' . Announcement::liveSql() . ' FOR UPDATE',
                [$id, $now, $now]
            );
            if ($announcement === null) {
                return 0;
            }

            $body = ($announcement['category'] ? $announcement['category'] . ' · ' : '') . str_limit(preg_replace('/\s+/', ' ', trim($announcement['content'])), 160);
            $count = Database::query(
                "INSERT INTO notifications (user_id, `type`, title, body, link_url, state)
                 SELECT u.id, 'announcement.published', ?, ?, ?, 'UNREAD' FROM users u, announcements a
                  WHERE a.id = ? AND " . Announcement::audienceSql(),
                [
                    mb_substr(($announcement['is_urgent'] ? 'Urgent: ' : 'Announcement: ') . $announcement['title'], 0, 200),
                    $body,
                    '/portal/announcements/' . $id,
                    $id,
                ]
            )->rowCount();

            Database::update('announcements', ['notified_at' => $now, 'recipient_count' => $count], ['id' => $id]);
            AuditService::log('announcement.notified', 'communication', 'Announcement', $id, null, ['recipients' => $count]);

            return $count;
        });
    }

    /**
     * Deliver scheduled announcements whose publish time has come. Called on portal
     * page loads (there is no cron in a plain XAMPP install) and by
     * database/dispatch_announcements.php, which a scheduled task can run.
     *
     * @return int notifications written
     */
    public static function dispatchDue(bool $force = false): int
    {
        if (self::$swept && !$force) {
            return 0;
        }
        self::$swept = true;

        $now = date('Y-m-d H:i:s');
        $total = 0;
        foreach (Database::select(
            'SELECT a.id FROM announcements a WHERE a.notified_at IS NULL AND ' . Announcement::liveSql() . ' ORDER BY a.published_at LIMIT 20',
            [$now, $now]
        ) as $row) {
            $total += self::dispatch((int) $row['id']);
        }
        return $total;
    }

    /**
     * Whether a user (or a signed-out visitor) may see an announcement's image: the same
     * rule as the announcement itself, plus the managers for drafts and previews.
     */
    public static function canViewImage(?User $viewer, array $announcement): bool
    {
        if ($announcement['image_path'] === null) {
            return false;
        }
        if ($viewer !== null && $viewer->hasPermission('announcements.manage')) {
            return true;
        }
        $now = date('Y-m-d H:i:s');
        $live = $announcement['status'] === Announcement::PUBLISHED && $announcement['published_at'] !== null
            && $announcement['published_at'] <= $now && ($announcement['expires_at'] === null || $announcement['expires_at'] > $now);
        if (!$live) {
            return false;
        }
        if ((int) $announcement['is_public'] === 1) {
            return true;
        }
        return $viewer !== null && Announcement::isVisibleTo((int) $announcement['id'], $viewer);
    }

    // -- Internals --------------------------------------------------------------------

    /**
     * @param array<string,mixed>      $input
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    private static function validate(array $input, ?array $existing, string $status, ?UploadedFile $image): array
    {
        $validator = Validator::make($input, [
            'title'     => 'required|min:5|max:200',
            'content'   => 'required|min:10|max:20000',
            'category'  => 'required|in:' . implode(',', Announcement::CATEGORIES),
            'audience'  => 'required|in:EVERYONE,TARGETED',
            'image_alt' => 'nullable|max:200',
        ], ['image_alt' => 'Image description', 'content' => 'Text']);

        $ids = static fn (string $key): array => array_values(array_unique(array_filter(
            array_map('intval', (array) ($input[$key] ?? [])),
            static fn (int $v) => $v > 0
        )));
        $audience = (string) ($input['audience'] ?? '');
        $roles = $audience === 'TARGETED' ? $ids('roles') : [];
        $programs = $audience === 'TARGETED' ? $ids('programs') : [];
        $yearLevels = $audience === 'TARGETED' ? $ids('year_levels') : [];

        $known = static function (string $table, array $values): bool {
            if ($values === []) {
                return true;
            }
            $in = implode(',', array_fill(0, count($values), '?'));
            return (int) Database::scalar("SELECT COUNT(*) FROM {$table} WHERE id IN ({$in})", $values) === count($values);
        };

        if ($audience === 'TARGETED') {
            if ($roles === []) {
                $validator->addError('roles', 'Choose at least one group of users to reach.');
            } elseif (!$known('roles', $roles)) {
                $validator->addError('roles', 'Choose from the listed groups.');
            }
            $studentRole = (int) Database::scalar("SELECT id FROM roles WHERE name = 'Student'");
            if (($programs !== [] || $yearLevels !== []) && !in_array($studentRole, $roles, true)) {
                $validator->addError('programs', 'Programs and year levels narrow down students. Include Students, or clear them.');
            }
            if (!$known('programs', $programs)) {
                $validator->addError('programs', 'Choose from the listed programs.');
            }
            if (!$known('year_levels', $yearLevels)) {
                $validator->addError('year_levels', 'Choose from the listed year levels.');
            }
        }

        $isPublic = !empty($input['is_public']) ? 1 : 0;
        if ($isPublic === 1 && $audience !== 'EVERYONE') {
            $validator->addError('is_public', 'Only an announcement for everyone can be shown on the public website.');
        }

        $dates = [];
        foreach (['published_at' => 'publish date', 'expires_at' => 'end date'] as $field => $label) {
            $raw = trim((string) ($input[$field] ?? ''));
            $dates[$field] = null;
            if ($raw === '') {
                continue;
            }
            $parsed = \DateTime::createFromFormat('!Y-m-d\TH:i', $raw) ?: \DateTime::createFromFormat('!Y-m-d H:i:s', $raw) ?: \DateTime::createFromFormat('!Y-m-d H:i', $raw);
            if ($parsed === false) {
                $validator->addError($field, "Enter a valid {$label} and time.");
                continue;
            }
            $dates[$field] = $parsed->format('Y-m-d H:i:s');
        }
        if ($existing !== null && $existing['notified_at'] !== null) {
            $dates['published_at'] = $existing['published_at']; // already sent; the date is fixed
        }
        $now = date('Y-m-d H:i:s');
        $effectiveStart = $dates['published_at'] !== null && $dates['published_at'] > $now ? $dates['published_at'] : $now;
        if ($dates['expires_at'] !== null && $dates['expires_at'] <= ($status === Announcement::DRAFT ? ($dates['published_at'] ?? '') : $effectiveStart)) {
            $validator->addError('expires_at', 'The end date must be after the publish date' . ($status === Announcement::DRAFT ? '.' : ' and in the future.'));
        }

        $hasNewImage = $image !== null && $image->error() !== UPLOAD_ERR_NO_FILE;
        $keepsImage = $existing !== null && $existing['image_path'] !== null && empty($input['remove_image']);
        if (($hasNewImage || $keepsImage) && trim((string) ($input['image_alt'] ?? '')) === '') {
            $validator->addError('image_alt', 'Describe the image for people who cannot see it.');
        }

        if ($validator->fails()) {
            throw ValidationException::withErrors($validator->errors());
        }

        return [
            'title'        => trim((string) $input['title']),
            'content'      => trim((string) $input['content']),
            'category'     => (string) $input['category'],
            'audience'     => $audience,
            'roles'        => $roles,
            'programs'     => $programs,
            'year_levels'  => $yearLevels,
            'is_public'    => $isPublic,
            'is_urgent'    => !empty($input['is_urgent']) ? 1 : 0,
            'published_at' => $dates['published_at'],
            'expires_at'   => $dates['expires_at'],
            'image_alt'    => ($hasNewImage || $keepsImage) ? trim((string) $input['image_alt']) : null,
        ];
    }

    private static function changeStatus(User $actor, int $id, string $from, string $to, string $auditAction, string $refusal): void
    {
        Database::transaction(static function () use ($actor, $id, $from, $to, $auditAction, $refusal): void {
            $announcement = Database::selectOne('SELECT * FROM announcements WHERE id = ? FOR UPDATE', [$id]);
            if ($announcement === null) {
                throw HttpException::notFound('That announcement was not found.');
            }
            if ($announcement['status'] !== $from) {
                throw ValidationException::withMessage($refusal);
            }
            Database::update('announcements', ['status' => $to], ['id' => $id]);
            AuditService::log($auditAction, 'communication', 'Announcement', $id, ['status' => $from], ['status' => $to], 'SUCCESS', null, $actor->id, $actor->role_name);
        });
    }

    private static function uniqueSlug(string $title, ?int $exceptId = null): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) ?: $title)), '-');
        $base = mb_substr($base !== '' ? $base : 'announcement', 0, 200);
        $slug = $base;
        for ($n = 2; Database::scalar('SELECT id FROM announcements WHERE slug = ? AND id <> ?', [$slug, $exceptId ?? 0]) !== null; $n++) {
            $slug = $base . '-' . $n;
        }
        return $slug;
    }

    private static function assertCan(User $actor, string $permission, string $message): void
    {
        if (!$actor->hasPermission($permission)) {
            throw HttpException::forbidden($message);
        }
    }
}
