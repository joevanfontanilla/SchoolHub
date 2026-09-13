<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Public documents published for download (docs/06).
 *
 * The stored file lives under storage/ and is streamed by a controller — the
 * filename is never used to build a public URL.
 */
final class Download
{
    public static function publicList(): array
    {
        return Database::select(
            'SELECT id, title, description, original_filename, file_size, mime_type, download_count, created_at
               FROM downloads
              WHERE is_public = 1
              ORDER BY created_at DESC'
        );
    }

    public static function find(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM downloads WHERE id = ?', [$id]);
    }

    public static function recordHit(int $id): void
    {
        Database::query('UPDATE downloads SET download_count = download_count + 1 WHERE id = ?', [$id]);
    }

    public static function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / 1048576, 1) . ' MB';
    }
}
