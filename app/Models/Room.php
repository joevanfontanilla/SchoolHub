<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Room
{
    public const TYPES = ['Lecture', 'Laboratory', 'Computer Laboratory', 'Gymnasium', 'Other'];

    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        return Database::select(
            'SELECT r.*, (SELECT COUNT(DISTINCT m.class_schedule_id) FROM class_meetings m
                            JOIN class_schedules cs ON cs.id = m.class_schedule_id AND cs.status = \'ACTIVE\'
                           WHERE m.room_id = r.id) AS class_count
               FROM rooms r ORDER BY r.is_active DESC, r.code'
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function active(): array
    {
        return Database::select('SELECT id, code, name, capacity FROM rooms WHERE is_active = 1 ORDER BY code');
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM rooms WHERE id = ?', [$id]);
    }
}
