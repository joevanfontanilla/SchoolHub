<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Models\User;

/**
 * Closing the cashier's day (docs/14, Daily operations at the window).
 *
 * The system works out what the drawer should hold from what was recorded that day; the cashier
 * counts it and signs off. A closed day takes no new payment, reversal, or voucher until the
 * Super Admin reopens it. Money in whole centavos.
 */
final class DayCloseService
{
    public const LOCKED_MESSAGE = 'Today has been closed. Ask the administrator to reopen it before recording anything else.';

    /**
     * What the day's records say. Reversals are negative payments, so they net out.
     *
     * @return array{date:string,methods:array<string,int>,receivedCash:int,otherReceived:int,paidOutCash:int,expectedCash:int,paymentCount:int,reversalCount:int}
     */
    public static function summary(string $date): array
    {
        $methods = [];
        foreach (Database::select(
            'SELECT payment_method, SUM(amount) AS total FROM payments WHERE DATE(created_at) = ? GROUP BY payment_method ORDER BY payment_method',
            [$date]
        ) as $row) {
            $methods[(string) $row['payment_method']] = FinanceService::signedCents($row['total']);
        }

        $receivedCash = $methods['Cash'] ?? 0;
        $otherReceived = array_sum($methods) - $receivedCash;
        $paidOutCash = FinanceService::cents((string) Database::scalar(
            "SELECT COALESCE(SUM(amount), 0) FROM disbursement_vouchers WHERE payment_method = 'Cash' AND DATE(created_at) = ?",
            [$date]
        ));

        return [
            'date'          => $date,
            'methods'       => $methods,
            'receivedCash'  => $receivedCash,
            'otherReceived' => $otherReceived,
            'paidOutCash'   => $paidOutCash,
            'expectedCash'  => $receivedCash - $paidOutCash,
            'paymentCount'  => (int) Database::scalar('SELECT COUNT(*) FROM payments WHERE DATE(created_at) = ? AND reversed_payment_id IS NULL', [$date]),
            'reversalCount' => (int) Database::scalar('SELECT COUNT(*) FROM payments WHERE DATE(created_at) = ? AND reversed_payment_id IS NOT NULL', [$date]),
        ];
    }

    /** @return array<string,mixed>|null the day's close record, whether closed or reopened */
    public static function find(string $date): ?array
    {
        return Database::selectOne(
            "SELECT dc.*, CONCAT(c.first_name, ' ', c.last_name) AS closed_by_name,
                    CONCAT(r.first_name, ' ', r.last_name) AS reopened_by_name
               FROM day_closes dc
               JOIN users c ON c.id = dc.closed_by
          LEFT JOIN users r ON r.id = dc.reopened_by
              WHERE dc.close_date = ?",
            [$date]
        );
    }

    public static function isClosed(string $date): bool
    {
        return Database::scalar("SELECT id FROM day_closes WHERE close_date = ? AND status = 'CLOSED'", [$date]) !== null;
    }

    /** Called before anything is recorded on today's books. */
    public static function assertOpen(): void
    {
        if (self::isClosed(date('Y-m-d'))) {
            throw ValidationException::withMessage(self::LOCKED_MESSAGE);
        }
    }

    /** @return array<int,array<string,mixed>> newest first */
    public static function history(int $limit = 30): array
    {
        return Database::select(
            "SELECT dc.*, CONCAT(c.first_name, ' ', c.last_name) AS closed_by_name,
                    CONCAT(r.first_name, ' ', r.last_name) AS reopened_by_name
               FROM day_closes dc
               JOIN users c ON c.id = dc.closed_by
          LEFT JOIN users r ON r.id = dc.reopened_by
              ORDER BY dc.close_date DESC LIMIT " . max(1, $limit)
        );
    }

    /**
     * Close a day with the counted cash.
     *
     * @return array<string,mixed> the close record
     */
    public static function close(User $cashier, string $date, string $counted, string $explanation): array
    {
        if (!$cashier->isCashier()) {
            throw HttpException::forbidden('Only the cashier can close the day.');
        }
        $explanation = trim($explanation);
        $errors = [];

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
            $errors['date'] = ['Choose the day to close.'];
        } elseif ($date > date('Y-m-d')) {
            $errors['date'] = ['A day cannot be closed before it happens.'];
        }
        if (!preg_match('/^\d+(\.\d{1,2})?$/', trim($counted))) {
            $errors['counted_cash'] = ['Enter the cash you counted, in pesos, with at most two decimal places. Enter 0 if the drawer is empty.'];
        }
        if ($errors !== []) {
            throw ValidationException::withErrors($errors);
        }

        $countedCents = FinanceService::cents($counted);

        return Database::transaction(static function () use ($cashier, $date, $countedCents, $explanation): array {
            $existing = Database::selectOne('SELECT * FROM day_closes WHERE close_date = ? FOR UPDATE', [$date]);
            if ($existing !== null && $existing['status'] === 'CLOSED') {
                throw ValidationException::withMessage('That day is already closed.');
            }

            $summary = self::summary($date);
            $difference = $countedCents - $summary['expectedCash'];
            if ($difference !== 0 && mb_strlen($explanation) < 5) {
                throw ValidationException::withErrors(
                    ['explanation' => ['The count is off by ' . money(FinanceService::money(abs($difference))) . ' (' . ($difference < 0 ? 'short' : 'over') . '). Say why, in at least 5 characters.']],
                    'The cash count differs from what the records expect.'
                );
            }

            $row = [
                'status'         => 'CLOSED',
                'received_cash'  => FinanceService::money($summary['receivedCash']),
                'paid_out_cash'  => FinanceService::money($summary['paidOutCash']),
                'expected_cash'  => FinanceService::money($summary['expectedCash']),
                'counted_cash'   => FinanceService::money($countedCents),
                'difference'     => FinanceService::money($difference),
                'other_received' => FinanceService::money($summary['otherReceived']),
                'payment_count'  => $summary['paymentCount'],
                'explanation'    => $explanation !== '' ? $explanation : null,
                'closed_by'      => $cashier->id,
                'closed_at'      => date('Y-m-d H:i:s'),
                'reopened_by'    => null,
                'reopened_at'    => null,
                'reopen_reason'  => null,
            ];
            if ($existing === null) {
                $id = Database::insert('day_closes', ['close_date' => $date] + $row);
            } else {
                $id = (int) $existing['id'];
                Database::update('day_closes', $row, ['id' => $id]);
            }

            AuditService::log('finance.day_closed', 'finance', 'DayClose', $id, null, [
                'date' => $date, 'expected' => $row['expected_cash'], 'counted' => $row['counted_cash'], 'difference' => $row['difference'],
            ], 'SUCCESS', $explanation !== '' ? $explanation : null, $cashier->id, $cashier->role_name);

            return self::find($date) ?? [];
        });
    }

    /** The Super Admin reopens a closed day, with a reason. */
    public static function reopen(User $admin, int $id, string $reason): void
    {
        if (!$admin->isSuperAdmin()) {
            throw HttpException::forbidden('Only the administrator can reopen a closed day.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withErrors(['reason' => ['Give the reason for reopening this day (at least 5 characters).']], 'A reason is required to reopen a day.');
        }

        Database::transaction(static function () use ($admin, $id, $reason): void {
            $close = Database::selectOne('SELECT * FROM day_closes WHERE id = ? FOR UPDATE', [$id]);
            if ($close === null) {
                throw HttpException::notFound('That closed day was not found.');
            }
            if ($close['status'] !== 'CLOSED') {
                throw ValidationException::withMessage('That day is already open.');
            }

            Database::update('day_closes', [
                'status' => 'REOPENED', 'reopened_by' => $admin->id, 'reopened_at' => date('Y-m-d H:i:s'), 'reopen_reason' => $reason,
            ], ['id' => $id]);

            AuditService::log('finance.day_reopened', 'finance', 'DayClose', $id, ['date' => $close['close_date']], null,
                'SUCCESS', $reason, $admin->id, $admin->role_name);
            NotificationService::notifyRole(User::ROLE_CASHIER, 'finance.day_reopened',
                'The administrator reopened ' . d($close['close_date']), $reason, '/cashier/day-close?date=' . $close['close_date']);
        });
    }
}
