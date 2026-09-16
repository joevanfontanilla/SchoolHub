<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\UploadedFile;
use App\Core\ValidationException;
use App\Models\User;

/**
 * Every user's profile photo, in one place (docs/08). A student's photo is their record photo on
 * student_profiles.photo_path (also settable by the registrar); every other role keeps theirs on
 * users.avatar_path. This service is the single entry point the top bar and the shared account
 * page use, so the rest of the app never has to know which table a given user's photo lives in.
 *
 * A photo is a small JPG or PNG, validated like every upload (DocumentUploadService), stored under
 * storage/ with a random name, and served only through the /account/photo route.
 */
final class AvatarService
{
    public const EXTENSIONS = StudentProfileService::PHOTO_EXTENSIONS;
    public const MAX_BYTES  = StudentProfileService::PHOTO_MAX_BYTES;

    /** The stored relative path of a user's photo, or null when there is none or the file is gone. */
    public static function path(User $user): ?string
    {
        if ($user->isStudent()) {
            $path = (string) Database::scalar(
                'SELECT sp.photo_path FROM students s JOIN student_profiles sp ON sp.student_id = s.id WHERE s.user_id = ?',
                [$user->id]
            );
        } else {
            $path = (string) Database::scalar('SELECT avatar_path FROM users WHERE id = ?', [$user->id]);
        }
        return $path !== '' && is_file(STORAGE_PATH . '/' . $path) ? $path : null;
    }

    /** @return array{path:string,mime:string}|null */
    public static function photo(User $user): ?array
    {
        $path = self::path($user);
        if ($path === null) {
            return null;
        }
        $mime = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'png' ? 'image/png' : 'image/jpeg';
        return ['path' => $path, 'mime' => $mime];
    }

    /** The user's own avatar URL for display, cache-busted, or null when they have none. */
    public static function currentUrl(User $user): ?string
    {
        $path = self::path($user);
        return $path === null ? null : url('/account/photo') . '?v=' . (int) @filemtime(STORAGE_PATH . '/' . $path);
    }

    /** Stream the signed-in user's own photo inline, or 404. @throws HttpException */
    public static function stream(User $user): void
    {
        $photo = self::photo($user);
        if ($photo === null) {
            throw HttpException::notFound('No photo has been uploaded.');
        }
        FileStreamer::send($photo['path'], 'photo.' . pathinfo($photo['path'], PATHINFO_EXTENSION), $photo['mime'], true);
    }

    /**
     * Set or remove the signed-in user's own photo. A new upload supersedes and deletes the old
     * file; the old file is removed only after the row is written.
     *
     * @throws ValidationException
     */
    public static function save(User $user, ?UploadedFile $file, bool $remove): void
    {
        $uploaded = $file !== null && $file->error() !== UPLOAD_ERR_NO_FILE;
        if (!$uploaded && !$remove) {
            throw ValidationException::withErrors(['photo' => ['Choose a photo to upload, or tick remove.']]);
        }

        $old = self::path($user);
        $newPath = null;
        if ($uploaded) {
            try {
                $ext = DocumentUploadService::assertGenuineFile($file, self::EXTENSIONS, self::MAX_BYTES, 'a profile photo');
            } catch (ValidationException $e) {
                throw ValidationException::withErrors(['photo' => [$e->getMessage()]]);
            }
            $dir = $user->isStudent() ? 'uploads/profile-photos' : 'uploads/avatars';
            $newPath = $dir . '/' . bin2hex(random_bytes(16)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
            $file->moveTo(STORAGE_PATH . '/' . $newPath);
        }

        try {
            Database::transaction(static function () use ($user, $newPath): void {
                if ($user->isStudent()) {
                    $studentRow = RegistrationService::ensureStudentRecord($user);
                    Database::update('student_profiles', ['photo_path' => $newPath], ['student_id' => (int) $studentRow['id']]);
                } else {
                    Database::update('users', ['avatar_path' => $newPath], ['id' => $user->id]);
                }
                AuditService::log($newPath !== null ? 'user.avatar_updated' : 'user.avatar_removed', 'auth', 'User', $user->id,
                    null, null, 'SUCCESS', null, $user->id, $user->role_name);
            });
        } catch (\Throwable $e) {
            if ($newPath !== null) {
                @unlink(STORAGE_PATH . '/' . $newPath);
            }
            throw $e;
        }

        if ($old !== null && $old !== $newPath) {
            @unlink(STORAGE_PATH . '/' . $old);
        }
    }
}
