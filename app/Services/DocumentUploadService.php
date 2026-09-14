<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\UploadedFile;
use App\Core\ValidationException;
use App\Models\EnrollmentApplication as App;
use App\Models\RequirementType;
use App\Models\User;

/**
 * Requirement document uploads (docs/10).
 *
 * A file is accepted only when its extension is allowed for the requirement,
 * the MIME type detected from its CONTENTS matches that extension, and its
 * first bytes are the signature of that format. The uploaded name is kept for
 * display only; the file is stored under a random name, outside the web root.
 */
final class DocumentUploadService
{
    /** Extension => the MIME type finfo must detect for it. */
    private const MIME = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
    ];

    /** Extension => file signature (magic bytes). */
    private const SIGNATURE = [
        'pdf'  => '%PDF-',
        'jpg'  => "\xFF\xD8\xFF",
        'jpeg' => "\xFF\xD8\xFF",
        'png'  => "\x89PNG\r\n\x1A\n",
    ];

    /**
     * @return int the new file id
     * @throws ValidationException
     */
    public static function upload(User $user, int $applicationId, int $requirementId, ?UploadedFile $file): int
    {
        $application = EnrollmentApplicationService::ownedApplication($user, $applicationId);

        $requirement = Database::selectOne(
            'SELECT er.*, rt.name, rt.code, rt.max_file_size_kb, rt.allowed_extensions
               FROM enrollment_requirements er
               JOIN requirement_types rt ON rt.id = er.requirement_type_id
              WHERE er.id = ? AND er.application_id = ?',
            [$requirementId, $applicationId]
        );
        if ($requirement === null) {
            throw HttpException::notFound('That requirement was not found.');
        }

        self::assertUploadsUnlocked($application, $requirement);
        $extension = self::validateFile($file, $requirement);

        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
        $relativePath = 'uploads/requirements/' . $applicationId . '/' . $storedName;
        $fullPath = STORAGE_PATH . '/' . $relativePath;

        $size = $file->size();
        $mime = $file->detectedMimeType();
        $checksum = $file->sha256();
        $displayName = FileStreamer::sanitizeFilename($file->clientName());

        $file->moveTo($fullPath);

        try {
            return Database::transaction(static function () use (
                $user, $applicationId, $requirement, $storedName, $relativePath, $size, $mime, $checksum, $displayName
            ): int {
                // Lock and re-check: the registrar may have changed the application
                // between the page loading and this upload arriving.
                $fresh = Database::selectOne('SELECT * FROM enrollment_applications WHERE id = ? FOR UPDATE', [$applicationId]);
                $freshRequirement = Database::selectOne('SELECT * FROM enrollment_requirements WHERE id = ? FOR UPDATE', [$requirement['id']]);
                self::assertUploadsUnlocked($fresh, $freshRequirement + ['name' => $requirement['name']]);

                // Supersede, never delete: earlier files stay for the record.
                Database::query(
                    'UPDATE enrollment_requirement_files SET is_current = 0 WHERE enrollment_requirement_id = ?',
                    [$requirement['id']]
                );

                $fileId = Database::insert('enrollment_requirement_files', [
                    'enrollment_requirement_id' => (int) $requirement['id'],
                    'original_filename'         => $displayName,
                    'stored_filename'           => $storedName,
                    'storage_path'              => $relativePath,
                    'mime_type'                 => $mime,
                    'file_size'                 => $size,
                    'checksum'                  => $checksum,
                    'uploaded_by'               => $user->id,
                    'uploaded_at'               => date('Y-m-d H:i:s'),
                    'is_current'                => 1,
                ]);

                Database::update('enrollment_requirements', [
                    'status'      => App::REQ_SUBMITTED,
                    'verified_by' => null,
                    'verified_at' => null,
                ], ['id' => $requirement['id']]);

                // Identifiers and technical facts only. No filename (it can carry
                // personal data) and never the contents.
                AuditService::log('requirement.uploaded', 'requirements', 'EnrollmentRequirement', (int) $requirement['id'], [
                    'status' => $freshRequirement['status'],
                ], [
                    'status'      => App::REQ_SUBMITTED,
                    'file_id'     => $fileId,
                    'requirement' => $requirement['code'],
                    'mime_type'   => $mime,
                    'size'        => $size,
                ], 'SUCCESS', null, $user->id, $user->role_name);

                return $fileId;
            });
        } catch (\Throwable $e) {
            // The database refused the upload, so the stored file must not linger.
            @unlink($fullPath);
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $application
     * @param array<string,mixed> $requirement
     */
    private static function assertUploadsUnlocked(array $application, array $requirement): void
    {
        if (!in_array($application['application_status'], App::UPLOADS_UNLOCKED, true)) {
            throw ValidationException::withMessage(
                'Documents cannot be changed while the application is ' . strtolower(App::label($application['application_status'])) . '.'
            );
        }

        if ($requirement['status'] === App::REQ_VERIFIED) {
            throw ValidationException::withMessage(
                'Your ' . $requirement['name'] . ' has already been verified by the registrar, so it cannot be replaced.'
            );
        }
    }

    /**
     * @param array<string,mixed> $requirement
     * @return string the validated, lower-case extension
     */
    private static function validateFile(?UploadedFile $file, array $requirement): string
    {
        return self::assertGenuineFile($file, RequirementType::extensions($requirement), (int) $requirement['max_file_size_kb'] * 1024, (string) $requirement['name']);
    }

    /**
     * Refuse a file unless it is present, within the size limit, has an allowed
     * extension, AND its contents (finfo MIME type and magic bytes, and for images
     * a readable image) match that extension. Shared by every upload: requirement
     * documents and payment proofs.
     *
     * @param array<int,string> $allowedExtensions e.g. ['pdf', 'jpg', 'png']
     * @return string the validated, lower-case extension
     * @throws ValidationException
     */
    public static function assertGenuineFile(?UploadedFile $file, array $allowedExtensions, int $maxBytes, string $label): string
    {
        if ($file === null || $file->error() === UPLOAD_ERR_NO_FILE) {
            throw ValidationException::withMessage('Please choose a file to upload.');
        }
        if (!$file->isValidUpload()) {
            throw ValidationException::withMessage($file->errorMessage());
        }

        if ($file->size() > $maxBytes) {
            throw ValidationException::withMessage(sprintf(
                'That file is %s. The limit for %s is %s.',
                self::megabytes($file->size()),
                $label,
                self::megabytes($maxBytes)
            ));
        }
        if ($file->size() === 0) {
            throw ValidationException::withMessage('That file is empty.');
        }

        $extension = $file->clientExtension();
        $allowed = array_values(array_intersect($allowedExtensions, array_keys(self::MIME)));

        if (!in_array($extension, $allowed, true)) {
            throw ValidationException::withMessage(
                'Upload a ' . strtoupper(implode(', ', array_unique(array_map(static fn ($e) => $e === 'jpeg' ? 'jpg' : $e, $allowed))))
                . ' file for ' . $label . '.'
            );
        }

        // The extension is the client's claim. The contents must back it up.
        $detected = $file->detectedMimeType();
        $signature = self::SIGNATURE[$extension];

        $contentsMatch = $detected === self::MIME[$extension]
            && str_starts_with($file->head(strlen($signature)), $signature);

        if ($contentsMatch && str_starts_with(self::MIME[$extension], 'image/')) {
            $contentsMatch = @getimagesize($file->tempPath()) !== false;
        }

        if (!$contentsMatch) {
            throw ValidationException::withMessage(
                'That file is not a real ' . strtoupper($extension === 'jpeg' ? 'jpg' : $extension)
                . ' document. Please upload the original scan or photo.'
            );
        }

        return $extension;
    }

    private static function megabytes(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.') . ' MB';
    }
}
