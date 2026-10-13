<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Models\FinanceReport;
use App\Models\User;

/**
 * Reminders for due installments (docs/14, Due installments).
 *
 * An installment is due when its exam period has opened and it is not fully paid. Each one is
 * reminded once: `installment_reminders` remembers (assessment, installment). The cashier presses
 * the button; nothing runs by itself because the current host has no scheduler.
 */
final class InstallmentReminderService
{
    /**
     * Due installments that have not been reminded yet.
     *
     * @return array<int,array{assessment_id:int,user_id:int,number:string,key:string,label:string,balance:int}>
     */
    public static function pending(?int $termId = null, ?int $programId = null, ?int $assessmentId = null): array
    {
        $accounts = [];
        foreach (FinanceReport::accountList($termId, $programId, null, true)['groups'] as $group) {
            foreach ($group['rows'] as $account) {
                if ($assessmentId === null || (int) $account['id'] === $assessmentId) {
                    $accounts[] = $account;
                }
            }
        }
        if ($accounts === []) {
            return [];
        }

        $ids = array_map(static fn (array $a): int => (int) $a['id'], $accounts);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sent = [];
        foreach (Database::select("SELECT fee_assessment_id, installment_key FROM installment_reminders WHERE fee_assessment_id IN ({$in})", $ids) as $row) {
            $sent[$row['fee_assessment_id'] . ':' . $row['installment_key']] = true;
        }

        $pending = [];
        foreach ($accounts as $account) {
            foreach (FinanceService::dueRows($account['plan']) as $row) {
                if (!isset($sent[$account['id'] . ':' . $row['key']])) {
                    $pending[] = [
                        'assessment_id' => (int) $account['id'],
                        'user_id'       => (int) $account['user_id'],
                        'number'        => (string) $account['assessment_number'],
                        'key'           => (string) $row['key'],
                        'label'         => (string) $row['label'],
                        'balance'       => (int) $row['balance'],
                    ];
                }
            }
        }

        return $pending;
    }

    /**
     * Notify every student with a due installment they have not been reminded of.
     *
     * @return int how many reminders were sent
     */
    public static function send(User $cashier, ?int $termId = null, ?int $programId = null, ?int $assessmentId = null): int
    {
        if (!$cashier->isCashier()) {
            throw HttpException::forbidden('Only the cashier can send payment reminders.');
        }

        $sent = 0;
        foreach (self::pending($termId, $programId, $assessmentId) as $reminder) {
            // The unique key makes a double click harmless: the second insert is ignored.
            $inserted = Database::query(
                'INSERT IGNORE INTO installment_reminders (fee_assessment_id, installment_key, sent_at) VALUES (?, ?, ?)',
                [$reminder['assessment_id'], $reminder['key'], date('Y-m-d H:i:s')]
            )->rowCount();
            if ($inserted === 0) {
                continue;
            }

            NotificationService::notify($reminder['user_id'], 'finance.installment_due',
                $reminder['label'] . ' is due: ' . money(FinanceService::money($reminder['balance'])),
                "Your {$reminder['label']} installment for account {$reminder['number']} is due. Please pay at the cashier or send your payment online.",
                '/student/account?assessment=' . $reminder['assessment_id']);
            $sent++;
        }

        if ($sent > 0) {
            AuditService::log('finance.reminders_sent', 'finance', null, null, null, ['reminders' => $sent],
                'SUCCESS', null, $cashier->id, $cashier->role_name);
        }

        return $sent;
    }
}
