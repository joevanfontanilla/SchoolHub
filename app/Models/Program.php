<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Program
{
    public static function active(): array
    {
        return Database::select(
            'SELECT id, code, name, description, degree_level, years_duration
               FROM programs
              WHERE is_active = 1
              ORDER BY code'
        );
    }

    public static function find(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM programs WHERE id = ?', [$id]);
    }

    public static function count(): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM programs WHERE is_active = 1');
    }
}
