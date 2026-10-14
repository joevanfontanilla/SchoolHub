<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\UploadedFile;
use App\Core\ValidationException;
use App\Models\User;

/**
 * Every user's e-signature (docs/08): an image of their handwritten signature, uploaded or drawn on
 * screen. It is stored under storage/uploads/signatures with a random name, never web-reachable, shown
 * to its owner on My Account, and embedded (as a data URI) in the printouts the person prepares. It is a
 * convenience, not a cryptographic digital signature.
 */
final class SignatureService
{
    public const EXTENSIONS = ['png', 'jpg', 'jpeg'];
    public const MAX_BYTES = 1024 * 1024;
    /** A drawn signature is small; refuse anything larger than this in pixels. */
    private const MAX_PIXELS = 2000;

    /** @var array<int,?string> */
    private static array $cache = [];

    /** The stored relative path of a user's signature, or null when there is none or the file is gone. */
    public static function path(int $userId): ?string
    {
        $path = (string) Database::scalar('SELECT signature_path FROM users WHERE id = ?', [$userId]);

        return $path !== '' && is_file(STORAGE_PATH . '/' . $path) ? $path : null;
    }

    public static function has(int $userId): bool
    {
        return self::path($userId) !== null;
    }

    /**
     * The signature as a data URI for a printout, or null. Nothing links to the file itself.
     * Looked up once per user per request.
     */
    public static function dataUri(?int $userId): ?string
    {
        if ($userId === null || $userId <= 0) {
            return null;
        }
        if (array_key_exists($userId, self::$cache)) {
            return self::$cache[$userId];
        }

        $path = self::path($userId);
        $uri = null;
        if ($path !== null) {
            $bytes = (string) @file_get_contents(STORAGE_PATH . '/' . $path);
            $uri = $bytes === '' ? null : 'data:' . self::mime($path) . ';base64,' . base64_encode($bytes);
        }

        return self::$cache[$userId] = $uri;
    }

    /** The owner's own cache-busted preview address for My Account, or null. */
    public static function currentUrl(User $user): ?string
    {
        $path = self::path($user->id);

        return $path === null ? null : url('/account/signature') . '?v=' . (int) @filemtime(STORAGE_PATH . '/' . $path);
    }

    /** Stream the signed-in user's own signature inline, or 404. @throws HttpException */
    public static function stream(User $user): void
    {
        $path = self::path($user->id);
        if ($path === null) {
            throw HttpException::notFound('You have not saved a signature.');
        }
        FileStreamer::send($path, 'signature.' . pathinfo($path, PATHINFO_EXTENSION), self::mime($path), true);
    }

    /**
     * Save the signed-in user's own signature, from an uploaded image or a drawn one (a PNG data URL).
     * The new file replaces and deletes the old one after the row is written.
     *
     * @param ?string $drawn a data:image/png;base64,... URL from the drawing pad
     * @throws ValidationException
     */
    public static function save(User $user, ?UploadedFile $file, ?string $drawn, bool $agreed): void
    {
        $uploaded = $file !== null && $file->error() !== UPLOAD_ERR_NO_FILE;
        $drawn = trim((string) $drawn);
        if (!$uploaded && $drawn === '') {
            throw ValidationException::withErrors(['signature' => ['Draw your signature or choose an image to upload.']]);
        }
        if (!$agreed) {
            throw ValidationException::withErrors(['agree' => ['Tick the box to confirm this is your own signature.']]);
        }

        $newPath = null;
        $directory = 'uploads/signatures';
        if ($uploaded) {
            try {
                $extension = DocumentUploadService::assertGenuineFile($file, self::EXTENSIONS, self::MAX_BYTES, 'a signature');
            } catch (ValidationException $e) {
                throw ValidationException::withErrors(['signature' => [$e->getMessage()]]);
            }
            $newPath = $directory . '/' . bin2hex(random_bytes(16)) . '.' . ($extension === 'jpeg' ? 'jpg' : $extension);
            self::ensureDirectory(STORAGE_PATH . '/' . $directory);
            $file->moveTo(STORAGE_PATH . '/' . $newPath);
        } else {
            $png = self::decodeDrawing($drawn);
            $newPath = $directory . '/' . bin2hex(random_bytes(16)) . '.png';
            self::ensureDirectory(STORAGE_PATH . '/' . $directory);
            file_put_contents(STORAGE_PATH . '/' . $newPath, $png);
        }

        $old = self::path($user->id);
        try {
            Database::transaction(static function () use ($user, $newPath, $old): void {
                Database::update('users', ['signature_path' => $newPath], ['id' => $user->id]);
                AuditService::log('user.signature_updated', 'auth', 'User', $user->id, null, ['replaced' => $old !== null],
                    'SUCCESS', null, $user->id, $user->role_name);
            });
        } catch (\Throwable $e) {
            @unlink(STORAGE_PATH . '/' . $newPath);
            throw $e;
        }

        if ($old !== null && $old !== $newPath) {
            @unlink(STORAGE_PATH . '/' . $old);
        }
        unset(self::$cache[$user->id]);
    }

    /**
     * Remove a signature. The owner removes their own; a Super Admin can remove anyone's (they can neither
     * see nor create one).
     *
     * @throws HttpException
     */
    public static function remove(User $actor, int $ownerId): void
    {
        if ($actor->id !== $ownerId && !$actor->isSuperAdmin()) {
            throw HttpException::forbidden('You can only remove your own signature.');
        }

        $old = self::path($ownerId);
        Database::transaction(static function () use ($actor, $ownerId): void {
            Database::update('users', ['signature_path' => null], ['id' => $ownerId]);
            AuditService::log('user.signature_removed', 'auth', 'User', $ownerId, null, ['by_admin' => $actor->id !== $ownerId],
                'SUCCESS', null, $actor->id, $actor->role_name);
        });
        if ($old !== null) {
            @unlink(STORAGE_PATH . '/' . $old);
        }
        unset(self::$cache[$ownerId]);
    }

    /**
     * The PNG bytes of a drawn signature, or a validation error. The data URL must be a PNG, small, and
     * a readable image of a sensible size.
     *
     * @throws ValidationException
     */
    private static function decodeDrawing(string $dataUrl): string
    {
        $invalid = ValidationException::withErrors(['signature' => ['That drawing could not be read. Clear it and draw again.']]);
        if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $match)) {
            throw $invalid;
        }
        $bytes = base64_decode($match[1], true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_BYTES || !str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            throw $invalid;
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[2] !== IMAGETYPE_PNG || $info[0] < 20 || $info[1] < 10 || $info[0] > self::MAX_PIXELS || $info[1] > self::MAX_PIXELS) {
            throw $invalid;
        }

        return $bytes;
    }

    private static function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
    }

    private static function mime(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'png' ? 'image/png' : 'image/jpeg';
    }
}
