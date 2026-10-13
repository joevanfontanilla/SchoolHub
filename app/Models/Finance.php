<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Services\FinanceService;
use App\Services\PaymentPolicy;

/**
 * Read queries for assessments, payments, and online payment submissions
 * (docs/14, docs/15). Writes go through FinanceService.
 */
final class Finance
{
    private const ASSESSMENT_COLUMNS =
        "fa.*, ea.application_number, ea.payment_status, ea.enrollment_state, ea.application_status, ea.section_id,
         st.student_number, st.user_id, u.first_name, u.middle_name, u.last_name, u.email,
         t.name AS term_name, ay.code AS academic_year_code, p.code AS program_code, yl.name AS year_level_name,
         sec.code AS section_code";

    private const ASSESSMENT_JOINS =
        'FROM fee_assessments fa
         JOIN enrollment_applications ea ON ea.id = fa.application_id
         JOIN students st       ON st.id = fa.student_id
         JOIN users u           ON u.id = st.user_id
         JOIN terms t           ON t.id = fa.term_id
         JOIN academic_years ay ON ay.id = t.academic_year_id
         JOIN programs p        ON p.id = ea.program_id
         JOIN year_levels yl    ON yl.id = ea.year_level_id
    LEFT JOIN sections sec      ON sec.id = ea.section_id';

    /** @return array<string,mixed>|null */
    public static function assessment(int $id): ?array
    {
        return Database::selectOne('SELECT ' . self::ASSESSMENT_COLUMNS . ' ' . self::ASSESSMENT_JOINS . ' WHERE fa.id = ?', [$id]);
    }

    /** @return array<string,mixed>|null */
    public static function assessmentForApplication(int $applicationId): ?array
    {
        return Database::selectOne('SELECT ' . self::ASSESSMENT_COLUMNS . ' ' . self::ASSESSMENT_JOINS . ' WHERE fa.application_id = ?', [$applicationId]);
    }

    /** @return array<int,array<string,mixed>> newest first */
    public static function assessmentsForStudent(int $studentId): array
    {
        return Database::select('SELECT ' . self::ASSESSMENT_COLUMNS . ' ' . self::ASSESSMENT_JOINS . ' WHERE fa.student_id = ? ORDER BY fa.assessed_at DESC', [$studentId]);
    }

    /**
     * Student accounts for the cashier, with an optional search.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function accounts(string $q = '', string $balance = '', int $limit = 50, int $offset = 0): array
    {
        [$where, $bindings] = self::accountFilter($q, $balance);
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        return Database::select(
            'SELECT ' . self::ASSESSMENT_COLUMNS . ' ' . self::ASSESSMENT_JOINS . ' WHERE ' . $where . " ORDER BY fa.assessed_at DESC, fa.id DESC LIMIT {$limit} OFFSET {$offset}",
            $bindings
        );
    }

    public static function accountCount(string $q = '', string $balance = ''): int
    {
        [$where, $bindings] = self::accountFilter($q, $balance);
        return (int) Database::scalar('SELECT COUNT(*) ' . self::ASSESSMENT_JOINS . ' WHERE ' . $where, $bindings);
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private static function accountFilter(string $q, string $balance): array
    {
        $where = ["fa.status = 'ACTIVE'"];
        $bindings = [];
        if ($q !== '') {
            $where[] = "(st.student_number LIKE ? OR ea.application_number LIKE ? OR fa.assessment_number LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            array_push($bindings, $like, $like, $like, $like);
        }
        if ($balance === 'open') {
            $where[] = 'fa.balance > 0';
        } elseif ($balance === 'paid') {
            $where[] = 'fa.balance <= 0';
        }
        return [implode(' AND ', $where), $bindings];
    }

    /**
     * The installment plan of an assessment, with the grading periods that have opened (docs/14).
     *
     * @param array<string,mixed> $assessment
     * @return array<string,mixed>|null null before the student is enrolled
     */
    public static function installmentPlan(array $assessment): ?array
    {
        $opened = array_map('intval', array_column(Database::select(
            'SELECT sequence FROM grade_periods WHERE term_id = ? AND opened_at IS NOT NULL',
            [$assessment['term_id']]
        ), 'sequence'));

        return FinanceService::installments($assessment, self::adjustments((int) $assessment['id']), $opened);
    }

    /** @return array<int,array<string,mixed>> */
    public static function items(int $assessmentId): array
    {
        return Database::select('SELECT * FROM fee_items WHERE fee_assessment_id = ? ORDER BY sort_order, id', [$assessmentId]);
    }

    /**
     * Payments and reversals in order. A payment that was reversed carries
     * reversal_id/reversed_at/reversal_reason; a reversal row carries
     * reversed_receipt_number (the receipt it cancels).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function payments(int $assessmentId): array
    {
        return Database::select(
            "SELECT pay.*, CONCAT(c.first_name, ' ', c.last_name) AS cashier_name,
                    r.id AS reversal_id, r.created_at AS reversed_at, r.remarks AS reversal_reason,
                    orig.receipt_number AS reversed_receipt_number
               FROM payments pay
               JOIN users c ON c.id = pay.cashier_user_id
          LEFT JOIN payments r ON r.reversed_payment_id = pay.id
          LEFT JOIN payments orig ON orig.id = pay.reversed_payment_id
              WHERE pay.fee_assessment_id = ? ORDER BY pay.paid_at, pay.id",
            [$assessmentId]
        );
    }

    /** @return array<int,array<string,mixed>> oldest first */
    public static function adjustments(int $assessmentId): array
    {
        return Database::select(
            "SELECT fa.*, CONCAT(u.first_name, ' ', u.last_name) AS created_by_name
               FROM financial_adjustments fa JOIN users u ON u.id = fa.created_by
              WHERE fa.fee_assessment_id = ? ORDER BY fa.created_at, fa.id",
            [$assessmentId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function submissions(int $assessmentId): array
    {
        return Database::select(
            'SELECT ps.*, pay.receipt_number, pay.id AS payment_id, r.id AS reversal_id
               FROM payment_submissions ps
          LEFT JOIN payments pay ON pay.payment_submission_id = ps.id
          LEFT JOIN payments r ON r.reversed_payment_id = pay.id
              WHERE ps.fee_assessment_id = ? ORDER BY ps.submitted_at DESC',
            [$assessmentId]
        );
    }

    /**
     * Online payments for the cashier, oldest first so the queue is worked in order.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function submissionQueue(string $status = 'PENDING'): array
    {
        $status = in_array($status, ['PENDING', 'APPROVED', 'REJECTED'], true) ? $status : 'PENDING';
        return Database::select(
            "SELECT ps.*, fa.assessment_number, fa.balance, fa.total_amount, ea.application_number,
                    st.student_number, u.first_name, u.last_name, t.name AS term_name, ay.code AS academic_year_code
               FROM payment_submissions ps
               JOIN fee_assessments fa ON fa.id = ps.fee_assessment_id
               JOIN enrollment_applications ea ON ea.id = fa.application_id
               JOIN students st ON st.id = ps.student_id
               JOIN users u ON u.id = st.user_id
               JOIN terms t ON t.id = fa.term_id
               JOIN academic_years ay ON ay.id = t.academic_year_id
              WHERE ps.status = ?
              ORDER BY " . ($status === 'PENDING' ? 'ps.submitted_at ASC' : 'ps.reviewed_at DESC') . '
              LIMIT 200',
            [$status]
        );
    }

    /** @return array<string,mixed>|null */
    public static function submission(int $id): ?array
    {
        return Database::selectOne(
            "SELECT ps.*, pay.receipt_number, pay.id AS payment_id, CONCAT(r.first_name, ' ', r.last_name) AS reviewer_name,
                    st.user_id AS owner_user_id
               FROM payment_submissions ps
               JOIN students st ON st.id = ps.student_id
          LEFT JOIN payments pay ON pay.payment_submission_id = ps.id
          LEFT JOIN users r ON r.id = ps.reviewed_by
              WHERE ps.id = ?",
            [$id]
        );
    }

    /** @return array<string,mixed>|null a payment with its assessment and student, for the receipt */
    public static function payment(int $id): ?array
    {
        return Database::selectOne(
            "SELECT pay.*, CONCAT(c.first_name, ' ', c.last_name) AS cashier_name,
                    fa.assessment_number, fa.total_amount, fa.total_adjustments, fa.total_paid, fa.balance,
                    r.id AS reversal_id, r.created_at AS reversed_at, r.remarks AS reversal_reason,
                    st.student_number, st.user_id, u.first_name, u.middle_name, u.last_name,
                    t.name AS term_name, ay.code AS academic_year_code, p.code AS program_code, yl.name AS year_level_name
               FROM payments pay
               JOIN users c ON c.id = pay.cashier_user_id
               JOIN fee_assessments fa ON fa.id = pay.fee_assessment_id
               JOIN enrollment_applications ea ON ea.id = fa.application_id
               JOIN students st ON st.id = pay.student_id
               JOIN users u ON u.id = st.user_id
               JOIN terms t ON t.id = fa.term_id
               JOIN academic_years ay ON ay.id = t.academic_year_id
               JOIN programs p ON p.id = ea.program_id
               JOIN year_levels yl ON yl.id = ea.year_level_id
          LEFT JOIN payments r ON r.reversed_payment_id = pay.id
              WHERE pay.id = ?",
            [$id]
        );
    }

    /**
     * The balance right after this payment was recorded, for the receipt: the amount
     * due at that moment (assessment plus adjustments made by then) less everything
     * recorded up to and including it. Later adjustments and reversals don't change
     * a receipt already issued.
     */
    public static function balanceAfter(array $payment): string
    {
        $paidThrough = (string) Database::scalar(
            'SELECT COALESCE(SUM(amount), 0) FROM payments WHERE fee_assessment_id = ? AND (created_at < ? OR (created_at = ? AND id <= ?))',
            [$payment['fee_assessment_id'], $payment['created_at'], $payment['created_at'], $payment['id']]
        );
        $cents = FinanceService::cents($payment['total_amount']) + self::adjustedThrough($payment) - FinanceService::signedCents($paidThrough);
        return FinanceService::money(max(0, $cents));
    }

    /** Centavos of adjustments applied to the account up to the moment this payment was recorded. */
    public static function adjustedThrough(array $payment): int
    {
        return FinanceService::signedCents((string) Database::scalar(
            'SELECT COALESCE(SUM(amount), 0) FROM financial_adjustments WHERE fee_assessment_id = ? AND created_at <= ?',
            [$payment['fee_assessment_id'], $payment['created_at']]
        ));
    }

    /**
     * Enrolled students whose paid amount is below the payment policy, usually after
     * a reversal (docs/14). The enrollment stands; the cashier and registrar follow up.
     *
     * @param array<string,mixed> $assessment an assessment row with enrollment_state
     */
    public static function belowPolicy(array $assessment): bool
    {
        return $assessment['enrollment_state'] === 'ENROLLED'
            && FinanceService::cents($assessment['total_paid']) < PaymentPolicy::requiredCents($assessment);
    }

    /**
     * Payments recorded at the cashier's window on a day (docs/14, Walk-in payments): not online
     * verifications, not reversals, and not payments that were later reversed. Oldest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function counterPaymentsOn(string $date): array
    {
        return Database::select(
            "SELECT pay.id, pay.receipt_number, pay.amount, pay.payment_method, pay.reference_number, pay.paid_at,
                    u.first_name, u.last_name, st.student_number, fa.assessment_number
               FROM payments pay
               JOIN fee_assessments fa ON fa.id = pay.fee_assessment_id
               JOIN students st        ON st.id = pay.student_id
               JOIN users u            ON u.id  = st.user_id
          LEFT JOIN payments r         ON r.reversed_payment_id = pay.id
              WHERE pay.payment_submission_id IS NULL AND pay.reversed_payment_id IS NULL
                AND pay.amount > 0 AND r.id IS NULL AND DATE(pay.paid_at) = ?
              ORDER BY pay.paid_at, pay.id",
            [$date]
        );
    }

    /** @return array{pending:int,collected_today:string,payments_today:int,outstanding:string,assessments:int} */
    public static function cashierStats(): array
    {
        $today = date('Y-m-d');
        return [
            'pending'         => (int) Database::scalar("SELECT COUNT(*) FROM payment_submissions WHERE status = 'PENDING'"),
            'collected_today' => (string) Database::scalar('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE DATE(created_at) = ?', [$today]),
            'payments_today'  => (int) Database::scalar('SELECT COUNT(*) FROM payments WHERE DATE(created_at) = ? AND reversed_payment_id IS NULL', [$today]),
            'outstanding'     => (string) Database::scalar("SELECT COALESCE(SUM(balance), 0) FROM fee_assessments WHERE status = 'ACTIVE'"),
            'assessments'     => (int) Database::scalar("SELECT COUNT(*) FROM fee_assessments WHERE status = 'ACTIVE'"),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public static function recentPayments(int $limit = 8): array
    {
        return Database::select(
            "SELECT pay.*, st.student_number, u.first_name, u.last_name
               FROM payments pay JOIN students st ON st.id = pay.student_id JOIN users u ON u.id = st.user_id
              ORDER BY pay.created_at DESC, pay.id DESC LIMIT " . max(1, $limit)
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function feeTypes(bool $activeOnly = false): array
    {
        return Database::select(
            'SELECT ft.*, p.code AS program_code, yl.name AS year_level_name
               FROM fee_types ft
          LEFT JOIN programs p     ON p.id  = ft.program_id
          LEFT JOIN year_levels yl ON yl.id = ft.year_level_id '
            . ($activeOnly ? 'WHERE ft.is_active = 1 ' : '') . 'ORDER BY ft.is_active DESC, ft.sort_order, ft.id'
        );
    }
}
