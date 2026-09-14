<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * A single uploaded file.
 *
 * fromRequest() is the only constructor reachable from a web request. It
 * insists the temp file really arrived through an HTTP upload
 * (is_uploaded_file) and moves it with move_uploaded_file. forTesting() exists
 * for the CLI test suite; it refuses to run under a web SAPI, so it cannot be
 * used to pull an arbitrary server file into storage.
 */
final class UploadedFile
{
    private function __construct(
        private string $tempPath,
        private string $clientName,
        private int $size,
        private int $error,
        private bool $viaHttp
    ) {
    }

    /** @param array{name?:mixed,tmp_name?:mixed,size?:mixed,error?:mixed} $entry one $_FILES entry */
    public static function fromRequest(array $entry): self
    {
        // A multi-file input arrives as arrays; this app only accepts one file per field.
        if (is_array($entry['name'] ?? null)) {
            return new self('', '', 0, UPLOAD_ERR_NO_FILE, true);
        }

        return new self(
            (string) ($entry['tmp_name'] ?? ''),
            (string) ($entry['name'] ?? ''),
            (int) ($entry['size'] ?? 0),
            (int) ($entry['error'] ?? UPLOAD_ERR_NO_FILE),
            true
        );
    }

    public static function forTesting(string $path, ?string $clientName = null, int $error = UPLOAD_ERR_OK): self
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('UploadedFile::forTesting() is only available on the command line.');
        }

        return new self($path, $clientName ?? basename($path), is_file($path) ? (int) filesize($path) : 0, $error, false);
    }

    public function error(): int
    {
        return $this->error;
    }

    public function isValidUpload(): bool
    {
        if ($this->error !== UPLOAD_ERR_OK || $this->tempPath === '' || !is_file($this->tempPath)) {
            return false;
        }
        return $this->viaHttp ? is_uploaded_file($this->tempPath) : true;
    }

    /** The name the client sent. Display only — never use it to build a path. */
    public function clientName(): string
    {
        return $this->clientName;
    }

    public function clientExtension(): string
    {
        return strtolower(pathinfo($this->clientName, PATHINFO_EXTENSION));
    }

    /** Real size on disk, not the client-reported one. */
    public function size(): int
    {
        return is_file($this->tempPath) ? (int) filesize($this->tempPath) : $this->size;
    }

    /** MIME type detected from the file's contents, not from the client. */
    public function detectedMimeType(): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $type = $finfo->file($this->tempPath);
        return is_string($type) ? $type : 'application/octet-stream';
    }

    /** The first bytes of the file, for magic-number checks. */
    public function head(int $bytes = 8): string
    {
        $handle = @fopen($this->tempPath, 'rb');
        if ($handle === false) {
            return '';
        }
        $data = (string) fread($handle, $bytes);
        fclose($handle);
        return $data;
    }

    public function tempPath(): string
    {
        return $this->tempPath;
    }

    public function sha256(): string
    {
        return (string) hash_file('sha256', $this->tempPath);
    }

    public function moveTo(string $destination): void
    {
        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the upload directory.');
        }

        $moved = $this->viaHttp
            ? move_uploaded_file($this->tempPath, $destination)
            : copy($this->tempPath, $destination);

        if (!$moved) {
            throw new RuntimeException('Could not store the uploaded file.');
        }
    }

    /** A plain-language message for a PHP upload error code. */
    public function errorMessage(): string
    {
        return match ($this->error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is larger than the server allows.',
            UPLOAD_ERR_PARTIAL                        => 'The file only partly uploaded. Please try again.',
            UPLOAD_ERR_NO_FILE                        => 'Please choose a file to upload.',
            default                                   => 'The file could not be uploaded. Please try again.',
        };
    }
}
