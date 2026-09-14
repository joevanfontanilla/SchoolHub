<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;

/**
 * Streams a file from storage/ through PHP.
 *
 * Files under storage/ are never web-reachable. Every download goes through a
 * controller that authorizes the requester first, then calls this (docs/10).
 */
final class FileStreamer
{
    /** @param bool $inline show in the browser (images) instead of downloading */
    public static function send(string $relativePath, string $downloadName, ?string $mimeType = null, bool $inline = false): void
    {
        $base = realpath(STORAGE_PATH);
        $full = realpath(STORAGE_PATH . '/' . ltrim($relativePath, '/\\'));

        // Reject anything that resolves outside storage/ — this is what stops a
        // traversal like "../../config/config.local.php".
        if ($base === false || $full === false || !str_starts_with($full, $base) || !is_file($full)) {
            throw HttpException::notFound('That file is no longer available.');
        }

        $safeName = self::sanitizeFilename($downloadName);

        header('Content-Type: ' . ($mimeType ?: 'application/octet-stream'));
        header('Content-Length: ' . filesize($full));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safeName . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');

        // Clear any buffered output so the file is not corrupted by stray bytes.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        readfile($full);
        exit;
    }

    /** Strip path separators and control characters from a download name. */
    public static function sanitizeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F"]+/', '', $name) ?? 'download';
        $name = trim($name);

        return $name === '' ? 'download' : mb_substr($name, 0, 120);
    }
}
