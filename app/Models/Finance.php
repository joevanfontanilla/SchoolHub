<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

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
    public static function accounts(string $q = '', string $balance = ''): array
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

        return Database::select(
            'SELECT ' . self::ASSESSMENT_COLUMNS . ' ' . self::ASSESSMENT_JOINS . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY fa.assessed_at DESC LIMIT 200',
            $bindings
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function items(int $assessmentId): array
    {
        return Database::select('SELECT * FROM fee_items WHERE fee_assessment_id = ? ORDER BY sort_order, id', [$assessmentId]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function payments(int $assessmentId): array
    {
        return Database::select(
            "SELECT pay.*, CONCAT(c.first_name, ' ', c.last_name) AS cashier_name
               FROM payments pay JOIN users c ON c.id = pay.cashier_user_id
              WHERE pay.fee_assessment_id = ? ORDER BY pay.paid_at, pay.id",
            [$assessmentId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function submissions(int $assessmentId): array
    {
        return Database::select(
            'SELECT ps.*, pay.receipt_number, pay.id AS payment_id
               FROM payment_submissions ps LEFT JOIN payments pay ON pay.payment_submission_id = ps.id
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
                    fa.assessment_number, fa.total_amount, fa.total_paid, fa.balance,
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
              WHERE pay.id = ?",
            [$id]
        );
    }

    /**
     * Balance after each payment, in order — for the receipt's "balance after".
     */
    public static function balanceAfter(array $payment): string
    {
        $paidThrough = (string) Database::scalar(
            'SELECT COALESCE(SUM(amount), 0) FROM payments WHERE fee_assessment_id = ? AND (paid_at < ? OR (paid_at = ? AND id <= ?))',
            [$payment['fee_assessment_id'], $payment['paid_at'], $payment['paid_at'], $payment['id']]
        );
        $cents = \App\Services\FinanceService::cents($payment['total_amount']) - \App\Services\FinanceService::cents($paidThrough);
        return \App\Services\FinanceService::money(max(0, $cents));
    }

    /** @return array{pending:int,collected_today:string,payments_today:int,outstanding:string,assessments:int} */
    public static function cashierStats(): array
    {
        $today = date('Y-m-d');
        return [
            'pending'         => (int) Database::scalar("SELECT COUNT(*) FROM payment_submissions WHERE status = 'PENDING'"),
            'collected_today' => (string) Database::scalar('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE DATE(created_at) = ?', [$today]),
            'payments_today'  => (int) Database::scalar('SELECT COUNT(*) FROM payments WHERE DATE(created_at) = ?', [$today]),
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
        return Database::select('SELECT * FROM fee_types ' . ($activeOnly ? 'WHERE is_active = 1 ' : '') . 'ORDER BY is_active DESC, sort_order, id');
    }
}
