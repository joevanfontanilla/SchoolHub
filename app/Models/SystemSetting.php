<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Key/value system settings (docs/20).
 */
final class SystemSetting
{
    private static ?array $cache = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        if (!array_key_exists($key, $all)) {
            return $default;
        }

        return self::cast($all[$key]['value'], $all[$key]['type']);
    }

    /**
     * A security threshold (docs/07): the system setting when the Super Admin has one,
     * else config security.<key>, else the default. Always at least 1.
     */
    public static function security(string $key, int $default): int
    {
        $fallback = (int) \App\Core\Config::get('security.' . $key, $default);
        return max(1, (int) self::get($key, $fallback));
    }

    /** Settings marked public — safe to render on the public website. */
    public static function publicSettings(): array
    {
        $out = [];
        foreach (self::all() as $key => $row) {
            if ((int) $row['is_public'] === 1) {
                $out[$key] = self::cast($row['value'], $row['type']);
            }
        }
        return $out;
    }

    /** @return array<string,array{value:?string,type:string,is_public:int}> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        try {
            $rows = Database::select('SELECT `key`, `value`, `type`, is_public FROM system_settings');
        } catch (\Throwable) {
            // Before the first migration there is no table yet — the public site
            // should still render with its defaults rather than fail.
            return self::$cache = [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[$row['key']] = [
                'value'     => $row['value'],
                'type'      => (string) $row['type'],
                'is_public' => (int) $row['is_public'],
            ];
        }

        return self::$cache = $out;
    }

    public static function set(string $key, string $value, ?int $userId = null): void
    {
        Database::query(
            'INSERT INTO system_settings (`key`, `value`, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_by = VALUES(updated_by)',
            [$key, $value, $userId]
        );
        self::$cache = null;
    }

    private static function cast(?string $value, string $type): mixed
    {
        return match ($type) {
            'int'  => (int) $value,
            'bool' => in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true),
            'json' => json_decode((string) $value, true),
            default => $value,
        };
    }
}
