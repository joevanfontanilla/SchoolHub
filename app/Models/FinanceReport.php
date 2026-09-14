<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Services\FinanceService;

/**
 * Cashier reports (docs/15): collections over a date range and outstanding
 * balances. Read-only. Amounts are summed in centavos.
 *
 * A collection is dated by when the cashier recorded or verified it (created_at),
 * which is when the money entered the school's books. Reversals appear as negative
 * lines on the day they were made, so the net always matches the accounts.
 */
final class FinanceReport
{
    /**
     * @return array{rows:array<int,array<string,mixed>>,by_day:array<string,array{count:int,cents:int}>,by_method:array<string,int>,gross:int,reversed:int,net:int,count:int}
     */
    public static function collections(string $from, string $to): array
    {
        $rows = Database::select(
            "SELECT pay.*, st.student_number, u.first_name, u.last_name, fa.assessment_number,
                    CONCAT(c.first_name, ' ', c.last_name) AS cashier_name,
                    orig.receipt_number AS reversed_receipt_number
               FROM payments pay
               JOIN students st ON st.id = pay.student_id
               JOIN users u ON u.id = st.user_id
               JOIN users c ON c.id = pay.cashier_user_id
               JOIN fee_assessments fa ON fa.id = pay.fee_assessment_id
          LEFT JOIN payments orig ON orig.id = pay.reversed_payment_id
              WHERE pay.created_at >= ? AND pay.created_at < DATE_ADD(?, INTERVAL 1 DAY)
              ORDER BY pay.created_at, pay.id",
            [$from, $to]
        );

        $byDay = [];
        $byMethod = [];
        $gross = 0;
        $reversed = 0;
        foreach ($rows as $row) {
            $cents = FinanceService::signedCents($row['amount']);
            $day = substr((string) $row['created_at'], 0, 10);
            $byDay[$day] ??= ['count' => 0, 'cents' => 0];
            $byDay[$day]['count'] += $cents > 0 ? 1 : 0;
            $byDay[$day]['cents'] += $cents;
            $byMethod[$row['payment_method']] = ($byMethod[$row['payment_method']] ?? 0) + $cents;
            $cents > 0 ? $gross += $cents : $reversed += $cents;
        }
        arsort($byMethod);

        return [
            'rows'      => $rows,
            'by_day'    => $byDay,
            'by_method' => $byMethod,
            'gross'     => $gross,
            'reversed'  => $reversed,
            'net'       => $gross + $reversed,
            'count'     => count(array_filter($rows, static fn ($r) => $r['reversed_payment_id'] === null)),
        ];
    }

    /**
     * Net collections per day for the last $days days, oldest first, including days
     * with nothing collected.
     *
     * @return array<string,int> Y-m-d => centavos
     */
    public static function dailyTotals(int $days = 7): array
    {
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $totals = [];
        for ($i = 0; $i < $days; $i++) {
            $totals[date('Y-m-d', strtotime("{$from} +{$i} days"))] = 0;
        }
        foreach (Database::select(
            'SELECT DATE(created_at) AS day, SUM(amount) AS total FROM payments WHERE created_at >= ? GROUP BY DATE(created_at)',
            [$from]
        ) as $row) {
            if (isset($totals[$row['day']])) {
                $totals[$row['day']] = FinanceService::signedCents($row['total']);
            }
        }
        return $totals;
    }

    /**
     * Active assessments with money still owed, largest balance first.
     *
     * @return array{rows:array<int,array<string,mixed>>,due:int,paid:int,balance:int}
     */
    public static function outstanding(?int $termId = null, ?int $programId = null): array
    {
        $where = ["fa.status = 'ACTIVE'", 'fa.balance > 0'];
        $bindings = [];
        if ($termId !== null) {
            $where[] = 'fa.term_id = ?';
            $bindings[] = $termId;
        }
        if ($programId !== null) {
            $where[] = 'ea.program_id = ?';
            $bindings[] = $programId;
        }

        $rows = Database::select(
            "SELECT fa.*, ea.application_number, ea.enrollment_state, ea.payment_status,
                    st.student_number, u.first_name, u.last_name, u.email,
                    p.code AS program_code, yl.name AS year_level_name, sec.code AS section_code,
                    t.name AS term_name, ay.code AS academic_year_code,
                    (SELECT MAX(pay.created_at) FROM payments pay WHERE pay.fee_assessment_id = fa.id) AS last_payment_at
               FROM fee_assessments fa
               JOIN enrollment_applications ea ON ea.id = fa.application_id
               JOIN students st ON st.id = fa.student_id
               JOIN users u ON u.id = st.user_id
               JOIN programs p ON p.id = ea.program_id
               JOIN year_levels yl ON yl.id = ea.year_level_id
          LEFT JOIN sections sec ON sec.id = ea.section_id
               JOIN terms t ON t.id = fa.term_id
               JOIN academic_years ay ON ay.id = t.academic_year_id
              WHERE " . implode(' AND ', $where) . '
              ORDER BY fa.balance DESC, u.last_name, u.first_name',
            $bindings
        );

        $due = 0;
        $paid = 0;
        $balance = 0;
        foreach ($rows as $row) {
            $due += FinanceService::dueCents($row);
            $paid += FinanceService::cents($row['total_paid']);
            $balance += FinanceService::cents($row['balance']);
        }

        return ['rows' => $rows, 'due' => $due, 'paid' => $paid, 'balance' => $balance];
    }
}
