<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use LogicException;

/**
 * Gapless, collision-free document numbers (docs/17 section 6).
 *
 * The counter row is locked with SELECT ... FOR UPDATE, so two requests issuing
 * a number at the same moment queue behind each other instead of reading the
 * same value. That lock only means something inside a transaction, which is why
 * calling this outside one is refused.
 */
final class SequenceService
{
    /**
     * @param string $key    'application', 'student', 'receipt', ...
     * @param string $prefix 'APP' -> APP-2026-00001; '' -> 2026-00001
     */
    public static function next(string $key, string $prefix = '', ?string $period = null, int $pad = 5): string
    {
        if (!Database::connection()->inTransaction()) {
            throw new LogicException('SequenceService::next() must run inside a database transaction.');
        }

        $period ??= date('Y');

        // Make sure the counter row exists, then lock it.
        Database::query(
            'INSERT IGNORE INTO document_sequences (`key`, prefix, period, current_number) VALUES (?, ?, ?, 0)',
            [$key, $prefix, $period]
        );

        $row = Database::selectOne(
            'SELECT id, current_number FROM document_sequences WHERE `key` = ? AND period = ? FOR UPDATE',
            [$key, $period]
        );

        $number = (int) $row['current_number'] + 1;
        Database::update('document_sequences', ['current_number' => $number], ['id' => $row['id']]);

        $formatted = $period . '-' . str_pad((string) $number, $pad, '0', STR_PAD_LEFT);

        return $prefix === '' ? $formatted : $prefix . '-' . $formatted;
    }
}
