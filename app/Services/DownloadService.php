<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\UploadedFile;
use App\Core\Validator;
use App\Core\ValidationException;
use App\Models\User;
use ZipArchive;

/**
 * The files published on the public Downloads page (docs/06), kept by the Super Admin under Administration > Downloads.
 *
 * A file is stored under storage/uploads/downloads with a random name and streamed by a route that checks it is public, so its stored name is never
 * a web address. Documents only: the type is checked from the file's own bytes, not just its name.
 */
final class DownloadService
{
    public const DIRECTORY = 'uploads/downloads';
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** extension => the MIME type it is sent with */
    public const TYPES = [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt'  => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'jpg'  => 'image/jpeg',
        'png'  => 'image/png',
    ];

    /** The folder a modern Office file must contain, which tells a real .docx from any other zip. */
    private const OOXML_FOLDER = ['docx' => 'word/', 'xlsx' => 'xl/', 'pptx' => 'ppt/'];
    private const OLE_SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    /** @return array<int,array<string,mixed>> every download for the admin, in the order the public sees them */
    public static function all(): array
    {
        return Database::select(
            'SELECT d.*, CONCAT(u.first_name, \' \', u.last_name) AS uploaded_by_name
               FROM downloads d LEFT JOIN users u ON u.id = d.uploaded_by
              ORDER BY d.sort_order, d.created_at DESC, d.id DESC'
        );
    }

    /** @return ?array<string,mixed> */
    public static function find(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM downloads WHERE id = ?', [$id]);
    }

    /**
     * Add a download (a file is required), or edit one (a file, when given, replaces the old one).
     *
     * @param array<string,mixed> $input title, description, sort_order, is_public
     * @return int the download's id
     * @throws ValidationException
     */
    public static function save(User $admin, ?int $id, array $input, ?UploadedFile $file): int
    {
        self::assertAdmin($admin);
        $existing = $id === null ? null : self::find($id);
        if ($id !== null && $existing === null) {
            throw HttpException::notFound('That download was not found.');
        }

        $validator = Validator::make($input, [
            'title'       => 'required|max:200',
            'description' => 'nullable|max:1000',
        ], ['title' => 'Title', 'description' => 'Description']);
        $order = trim((string) ($input['sort_order'] ?? ''));
        if ($order !== '' && !preg_match('/^\d{1,4}$/', $order)) {
            $validator->addError('sort_order', 'The order is a whole number from 0 to 9999, such as 1 or 2.');
        }
        if ($validator->fails()) {
            throw ValidationException::withErrors($validator->errors());
        }

        $uploaded = $file !== null && $file->error() !== UPLOAD_ERR_NO_FILE;
        if ($existing === null && !$uploaded) {
            throw ValidationException::withErrors(['file' => ['Choose the file to publish.']]);
        }

        $extension = null;
        if ($uploaded) {
            try {
                $extension = self::assertGenuineDocument($file);
            } catch (ValidationException $e) {
                throw ValidationException::withErrors(['file' => [$e->getMessage()]]);
            }
        }

        $row = [
            'title'       => trim((string) preg_replace('/\s+/', ' ', (string) $input['title'])),
            'description' => ($d = trim((string) ($input['description'] ?? ''))) === '' ? null : $d,
            'sort_order'  => (int) $order,
            'is_public'   => !empty($input['is_public']) ? 1 : 0,
        ];

        $newName = null;
        if ($uploaded) {
            $newName = bin2hex(random_bytes(16)) . '.' . $extension;
            self::ensureDirectory();
            $file->moveTo(STORAGE_PATH . '/' . self::DIRECTORY . '/' . $newName);
            $row += [
                'original_filename' => self::cleanName($file->clientName()),
                'stored_filename'   => $newName,
                'file_size'         => $file->size(),
                'mime_type'         => self::TYPES[$extension],
            ];
        }

        try {
            Database::transaction(static function () use (&$id, $existing, $row, $admin, $newName): void {
                if ($existing === null) {
                    $id = Database::insert('downloads', $row + ['uploaded_by' => $admin->id]);
                    AuditService::log('download.created', 'administration', 'Download', $id, null,
                        ['title' => $row['title'], 'file' => $row['original_filename'], 'public' => $row['is_public']], 'SUCCESS', null, $admin->id, $admin->role_name);
                    return;
                }
                Database::update('downloads', $row, ['id' => $existing['id']]);
                AuditService::log($newName !== null ? 'download.replaced' : 'download.updated', 'administration', 'Download', (int) $existing['id'],
                    ['title' => $existing['title'], 'public' => (int) $existing['is_public']], ['title' => $row['title'], 'public' => $row['is_public']], 'SUCCESS', null, $admin->id, $admin->role_name);
            });
        } catch (\Throwable $e) {
            if ($newName !== null) {
                @unlink(STORAGE_PATH . '/' . self::DIRECTORY . '/' . $newName);
            }
            throw $e;
        }

        if ($newName !== null && $existing !== null) {
            @unlink(STORAGE_PATH . '/' . self::DIRECTORY . '/' . $existing['stored_filename']);
        }

        return (int) $id;
    }

    /** Show or hide a download on the public page, without deleting it. */
    public static function setPublic(User $admin, int $id, bool $public): void
    {
        self::assertAdmin($admin);
        $download = self::find($id) ?? throw HttpException::notFound('That download was not found.');
        Database::update('downloads', ['is_public' => $public ? 1 : 0], ['id' => $id]);
        AuditService::log($public ? 'download.shown' : 'download.hidden', 'administration', 'Download', $id, null, ['title' => $download['title']], 'SUCCESS', null, $admin->id, $admin->role_name);
    }

    /** Delete a download and its file. */
    public static function delete(User $admin, int $id): void
    {
        self::assertAdmin($admin);
        $download = self::find($id) ?? throw HttpException::notFound('That download was not found.');
        Database::transaction(static function () use ($admin, $id, $download): void {
            Database::query('DELETE FROM downloads WHERE id = ?', [$id]);
            AuditService::log('download.deleted', 'administration', 'Download', $id, ['title' => $download['title'], 'file' => $download['original_filename']], null, 'SUCCESS', null, $admin->id, $admin->role_name);
        });
        @unlink(STORAGE_PATH . '/' . self::DIRECTORY . '/' . $download['stored_filename']);
    }

    /**
     * The file is present, within the limit, an allowed document type by extension, AND its own bytes agree: a PDF starts with %PDF, an image is a real
     * image, an old Office file has the Office container signature, and a modern one is a zip holding the Office folder for its kind.
     *
     * @return string the lower-case extension
     * @throws ValidationException
     */
    public static function assertGenuineDocument(?UploadedFile $file): string
    {
        $list = strtoupper(implode(', ', array_keys(self::TYPES)));
        if ($file === null || $file->error() === UPLOAD_ERR_NO_FILE) {
            throw ValidationException::withMessage('Choose the file to publish.');
        }
        if (!$file->isValidUpload()) {
            throw ValidationException::withMessage($file->errorMessage());
        }
        if ($file->size() === 0) {
            throw ValidationException::withMessage('That file is empty.');
        }
        if ($file->size() > self::MAX_BYTES) {
            throw ValidationException::withMessage('That file is ' . round($file->size() / 1048576, 1) . ' MB. The limit is ' . (self::MAX_BYTES / 1048576) . ' MB.');
        }

        $extension = $file->clientExtension();
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }
        if (!isset(self::TYPES[$extension])) {
            throw ValidationException::withMessage("Upload one of these document types: {$list}.");
        }

        $genuine = match ($extension) {
            'pdf'  => str_starts_with($file->head(5), '%PDF-'),
            'jpg'  => str_starts_with($file->head(3), "\xFF\xD8\xFF") && @getimagesize($file->tempPath()) !== false,
            'png'  => str_starts_with($file->head(8), "\x89PNG\r\n\x1A\n") && @getimagesize($file->tempPath()) !== false,
            'doc', 'xls', 'ppt' => str_starts_with($file->head(8), self::OLE_SIGNATURE),
            default => self::isOfficeZip($file, $extension),
        };
        if (!$genuine) {
            throw ValidationException::withMessage('That file is not a real ' . strtoupper($extension) . ' document. Upload the original file.');
        }

        return $extension;
    }

    private static function isOfficeZip(UploadedFile $file, string $extension): bool
    {
        if (!str_starts_with($file->head(4), "PK\x03\x04")) {
            return false;
        }
        if (!class_exists(ZipArchive::class)) {
            return true; // the signature is all there is to go on
        }
        $zip = new ZipArchive();
        if ($zip->open($file->tempPath()) !== true) {
            return false;
        }
        $hasContentTypes = $zip->locateName('[Content_Types].xml') !== false;
        $folder = self::OOXML_FOLDER[$extension];
        $hasFolder = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_starts_with((string) $zip->getNameIndex($i), $folder)) {
                $hasFolder = true;
                break;
            }
        }
        $zip->close();

        return $hasContentTypes && $hasFolder;
    }

    /** The name shown to people when they save the file: no path, no control characters. */
    private static function cleanName(string $name): string
    {
        return FileStreamer::sanitizeFilename($name);
    }

    private static function ensureDirectory(): void
    {
        $dir = STORAGE_PATH . '/' . self::DIRECTORY;
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create storage/' . self::DIRECTORY . '.');
        }
    }

    private static function assertAdmin(User $user): void
    {
        if (!$user->isSuperAdmin()) {
            throw HttpException::forbidden('Only the Super Admin manages the public downloads.');
        }
    }
}
