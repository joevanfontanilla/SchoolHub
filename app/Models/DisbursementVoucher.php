<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Read queries for cash disbursement vouchers (docs/14). Writing goes through
 * App\Services\DisbursementService. Vouchers are append-only.
 */
final class DisbursementVoucher
{
    private const SELECT = 'SELECT dv.*, u.first_name, u.last_name
                              FROM disbursement_vouchers dv
                              JOIN users u ON u.id = dv.recorded_by';

    /** @return array<int,array<string,mixed>> the most recent vouchers, newest first */
    public static function recent(): array
    {
        return Database::select(self::SELECT . ' ORDER BY dv.disbursed_at DESC, dv.id DESC LIMIT 100');
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne(self::SELECT . ' WHERE dv.id = ?', [$id]);
    }

    /**
     * The Cash Disbursements Journal for a date range (by disbursement date).
     *
     * @return array{rows:array<int,array<string,mixed>>,count:int,total:string}
     */
    public static function journal(string $from, string $to): array
    {
        $rows = Database::select(
            self::SELECT . ' WHERE DATE(dv.disbursed_at) BETWEEN ? AND ? ORDER BY dv.disbursed_at, dv.id',
            [$from, $to]
        );
        $total = 0.0;
        foreach ($rows as $row) {
            $total += (float) $row['amount'];
        }
        return ['rows' => $rows, 'count' => count($rows), 'total' => number_format($total, 2, '.', '')];
    }
}
