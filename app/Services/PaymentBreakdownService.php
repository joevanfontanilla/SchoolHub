<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Where the payments went (docs/14, Payment breakdown). A payment is not tied to a fee line in the books,
 * so this allocates it for display only: the old account first, then the rest across the other lines in
 * proportion to each line's share of what was charged. It never changes a balance or the ledger.
 *
 * The allocation is a function of the running total paid, so a period is "what each line had received by
 * the end of it, minus what it had received before it". A reversal therefore lowers the line it had counted
 * toward, and nothing is counted twice. Money in whole centavos; the lines always add up to what was paid.
 */
final class PaymentBreakdownService
{
    public const OLD = 'OLD';
    public const UNALLOCATED = 'UNALLOCATED';

    /**
     * Split a total paid: the old account first, then the rest across the weighted lines, with the leftover
     * centavos going to the largest remainders (ties by key) so the parts add up exactly.
     *
     * @param array<string,int> $weights positive centavos charged per line key
     * @return array<string,int> OLD, then every key of $weights (and UNALLOCATED when there are no lines)
     */
    public static function allocate(int $paid, int $old, array $weights): array
    {
        $paid = max(0, $paid);
        $weights = array_filter($weights, static fn (int $w): bool => $w > 0);
        $oldPart = min($paid, max(0, $old));
        $rest = $paid - $oldPart;

        $out = [self::OLD => $oldPart] + array_fill_keys(array_keys($weights), 0);
        $total = array_sum($weights);
        if ($rest <= 0) {
            return $out;
        }
        if ($total <= 0) {
            return $out + [self::UNALLOCATED => $rest];
        }

        $remainders = [];
        $assigned = 0;
        foreach ($weights as $key => $weight) {
            $out[$key] = intdiv($rest * $weight, $total);
            $remainders[$key] = ($rest * $weight) % $total;
            $assigned += $out[$key];
        }
        $order = array_keys($remainders);
        usort($order, static fn (string $a, string $b): int => [$remainders[$b], $a] <=> [$remainders[$a], $b]);
        foreach (array_slice($order, 0, $rest - $assigned) as $key) {
            $out[$key]++;
        }

        return $out;
    }

    /**
     * One student's account: each line with what was assessed and what has been paid toward it so far.
     *
     * @return array{rows:array<int,array<string,mixed>>,discounts:int,assessed:int,paid:int,balance:int,share:bool}|null null when the account has no charges
     */
    public static function forAssessment(int $assessmentId): ?array
    {
        $assessment = Database::selectOne('SELECT id, total_paid FROM fee_assessments WHERE id = ?', [$assessmentId]);
        if ($assessment === null) {
            return null;
        }

        $lines = self::lines(self::items([$assessmentId])[$assessmentId] ?? [], self::adjustments([$assessmentId])[$assessmentId] ?? []);
        $paid = max(0, FinanceService::cents($assessment['total_paid']));
        $split = self::allocate($paid, $lines['old'], $lines['weights']);

        $rows = [];
        if ($lines['old'] > 0) {
            $rows[] = ['key' => self::OLD, 'label' => 'Old account', 'assessed' => $lines['old'], 'paid' => $split[self::OLD]];
        }
        foreach ($lines['weights'] as $key => $weight) {
            $rows[] = ['key' => $key, 'label' => $lines['labels'][$key], 'assessed' => $weight, 'paid' => $split[$key] ?? 0];
        }
        if (isset($split[self::UNALLOCATED])) {
            $rows[] = ['key' => self::UNALLOCATED, 'label' => 'Not matched to a fee line', 'assessed' => 0, 'paid' => $split[self::UNALLOCATED]];
        }
        foreach ($rows as &$row) {
            $row['share'] = $paid > 0 ? $row['paid'] / $paid * 100 : 0.0;
        }
        unset($row);

        $assessed = $lines['old'] + array_sum($lines['weights']) + $lines['discounts'];

        return [
            'rows'      => $rows,
            'discounts' => $lines['discounts'],
            'assessed'  => $assessed,
            'paid'      => $paid,
            'balance'   => max(0, $assessed - $paid),
            'share'     => $paid > 0,
        ];
    }

    /**
     * The breakdown of a period: for the accounts that paid in it, each line's assessed and collected
     * amounts, plus collections by method and by day. A payment is dated when it was recorded.
     *
     * @return array{lines:array<int,array<string,mixed>>,discounts:int,assessed:int,collected:int,accounts:int,methods:array<string,array{cents:int,count:int}>,days:array<string,array{cents:int,count:int}>}
     */
    public static function report(string $from, string $to, ?int $programId, ?int $yearLevelId): array
    {
        $filter = '';
        $bindings = [$from, $to];
        if ($programId !== null) {
            $filter .= ' AND ea.program_id = ?';
            $bindings[] = $programId;
        }
        if ($yearLevelId !== null) {
            $filter .= ' AND ea.year_level_id = ?';
            $bindings[] = $yearLevelId;
        }
        $scope = "FROM payments pay
                  JOIN fee_assessments fa ON fa.id = pay.fee_assessment_id
                  JOIN enrollment_applications ea ON ea.id = fa.application_id
                 WHERE pay.created_at >= ? AND pay.created_at < DATE_ADD(?, INTERVAL 1 DAY){$filter}";

        $ids = array_map('intval', array_column(Database::select("SELECT DISTINCT pay.fee_assessment_id {$scope}", $bindings), 'fee_assessment_id'));

        $methods = [];
        foreach (Database::select("SELECT pay.payment_method AS method, SUM(pay.amount) AS total, SUM(pay.reversed_payment_id IS NULL) AS payments {$scope} GROUP BY pay.payment_method ORDER BY pay.payment_method", $bindings) as $row) {
            $methods[(string) $row['method']] = ['cents' => FinanceService::signedCents($row['total']), 'count' => (int) $row['payments']];
        }
        $days = [];
        foreach (Database::select("SELECT DATE(pay.created_at) AS day, SUM(pay.amount) AS total, SUM(pay.reversed_payment_id IS NULL) AS payments {$scope} GROUP BY DATE(pay.created_at) ORDER BY day", $bindings) as $row) {
            $days[(string) $row['day']] = ['cents' => FinanceService::signedCents($row['total']), 'count' => (int) $row['payments']];
        }

        $empty = ['lines' => [], 'discounts' => 0, 'assessed' => 0, 'collected' => 0, 'accounts' => 0, 'methods' => $methods, 'days' => $days];
        if ($ids === []) {
            return $empty;
        }

        // Running totals paid before the period and by its end, per account, then each account's allocation of both.
        $in = implode(',', array_fill(0, count($ids), '?'));
        $paidAt = [];
        foreach (Database::select(
            "SELECT fee_assessment_id,
                    SUM(CASE WHEN created_at < ? THEN amount ELSE 0 END) AS before_total,
                    SUM(CASE WHEN created_at < DATE_ADD(?, INTERVAL 1 DAY) THEN amount ELSE 0 END) AS upto_total
               FROM payments WHERE fee_assessment_id IN ({$in}) GROUP BY fee_assessment_id",
            array_merge([$from, $to], $ids)
        ) as $row) {
            $paidAt[(int) $row['fee_assessment_id']] = [FinanceService::signedCents($row['before_total']), FinanceService::signedCents($row['upto_total'])];
        }

        $items = self::items($ids);
        $adjustments = self::adjustments($ids);
        $assessed = $collected = $labels = [];
        $oldAssessed = $oldCollected = $discounts = $unallocated = 0;
        foreach ($ids as $id) {
            $lines = self::lines($items[$id] ?? [], $adjustments[$id] ?? []);
            [$before, $upto] = $paidAt[$id] ?? [0, 0];
            $then = self::allocate($before, $lines['old'], $lines['weights']);
            $now = self::allocate($upto, $lines['old'], $lines['weights']);

            $oldAssessed += $lines['old'];
            $oldCollected += $now[self::OLD] - $then[self::OLD];
            $unallocated += ($now[self::UNALLOCATED] ?? 0) - ($then[self::UNALLOCATED] ?? 0);
            $discounts += $lines['discounts'];
            foreach ($lines['weights'] as $key => $weight) {
                $labels[$key] = $lines['labels'][$key];
                $assessed[$key] = ($assessed[$key] ?? 0) + $weight;
                $collected[$key] = ($collected[$key] ?? 0) + $now[$key] - $then[$key];
            }
        }

        $total = $oldCollected + $unallocated + array_sum($collected);
        $rows = [];
        if ($oldAssessed > 0 || $oldCollected !== 0) {
            $rows[] = ['key' => self::OLD, 'label' => 'Old account', 'assessed' => $oldAssessed, 'collected' => $oldCollected];
        }
        foreach ($assessed as $key => $cents) {
            $rows[] = ['key' => $key, 'label' => $labels[$key], 'assessed' => $cents, 'collected' => $collected[$key]];
        }
        if ($unallocated !== 0) {
            $rows[] = ['key' => self::UNALLOCATED, 'label' => 'Not matched to a fee line', 'assessed' => 0, 'collected' => $unallocated];
        }
        foreach ($rows as &$row) {
            $row['share'] = $total !== 0 ? $row['collected'] / $total * 100 : 0.0;
        }
        unset($row);

        return [
            'lines'     => $rows,
            'discounts' => $discounts,
            'assessed'  => $oldAssessed + array_sum($assessed) + $discounts,
            'collected' => $total,
            'accounts'  => count($ids),
            'methods'   => $methods,
            'days'      => $days,
        ];
    }

    // -- Helpers ---------------------------------------------------------------------------

    /**
     * The charged lines of one account: the weights payments are shared by (fee lines and added charges),
     * the old account, and the discounts that lower what is owed.
     *
     * @param array<int,array<string,mixed>> $items
     * @param array<int,array<string,mixed>> $adjustments
     * @return array{weights:array<string,int>,labels:array<string,string>,old:int,discounts:int}
     */
    private static function lines(array $items, array $adjustments): array
    {
        $weights = $labels = [];
        foreach ($items as $item) {
            $key = 'FEE:' . ($item['fee_key'] ?? $item['description']);
            $labels[$key] = (string) ($item['fee_name'] ?? $item['description']);
            $weights[$key] = ($weights[$key] ?? 0) + FinanceService::cents($item['amount']);
        }

        $old = $discounts = 0;
        foreach ($adjustments as $adjustment) {
            $signed = FinanceService::signedCents($adjustment['amount']);
            if ($signed < 0) {
                $discounts += $signed;
            } elseif ($adjustment['type'] === 'OLD_ACCOUNT') {
                $old += $signed;
            } else {
                $key = 'ADJ:' . $adjustment['type'];
                $labels[$key] = FinanceService::ADJUSTMENT_TYPES[$adjustment['type']] ?? (string) $adjustment['type'];
                $weights[$key] = ($weights[$key] ?? 0) + $signed;
            }
        }

        return ['weights' => $weights, 'labels' => $labels, 'old' => $old, 'discounts' => $discounts];
    }

    /**
     * @param array<int,int> $ids
     * @return array<int,array<int,array<string,mixed>>> fee items by assessment
     */
    private static function items(array $ids): array
    {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (Database::select(
            "SELECT fi.fee_assessment_id, fi.description, fi.amount, ft.fee_key, ft.name AS fee_name
               FROM fee_items fi LEFT JOIN fee_types ft ON ft.id = fi.fee_type_id
              WHERE fi.fee_assessment_id IN ({$in}) ORDER BY fi.sort_order, fi.id",
            $ids
        ) as $row) {
            $out[(int) $row['fee_assessment_id']][] = $row;
        }

        return $out;
    }

    /**
     * @param array<int,int> $ids
     * @return array<int,array<int,array<string,mixed>>> adjustments by assessment
     */
    private static function adjustments(array $ids): array
    {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (Database::select("SELECT fee_assessment_id, type, amount FROM financial_adjustments WHERE fee_assessment_id IN ({$in}) ORDER BY id", $ids) as $row) {
            $out[(int) $row['fee_assessment_id']][] = $row;
        }

        return $out;
    }
}
