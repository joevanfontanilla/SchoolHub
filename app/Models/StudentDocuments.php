<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Services\AcademicRecordService;

/**
 * Every document belonging to one student, in one place (docs/10, docs/15): the files
 * uploaded for each application, official receipts, statements of account, class
 * schedules, and proofs of online payment. Read-only; each document is still opened
 * through its own authorizing route.
 */
final class StudentDocuments
{
    /**
     * @param bool $forStudent the student's own view: includes draft applications, schedules,
     *                         and payment proofs (the registrar may not open proofs, and never
     *                         sees drafts)
     * @return array{applications:array<int,array<string,mixed>>,receipts:array<int,array<string,mixed>>,statements:array<int,array<string,mixed>>,schedules:array<int,array<string,mixed>>,proofs:array<int,array<string,mixed>>,count:int}
     */
    public static function forStudent(int $studentId, bool $forStudent): array
    {
        $applications = [];
        foreach (Database::select(
            "SELECT ea.id AS application_id, ea.application_number, ea.application_status, t.name AS term_name, ay.code AS academic_year_code,
                    er.id AS requirement_id, er.status, er.is_required, rt.name,
                    f.id AS file_id, f.original_filename, f.mime_type, f.file_size, f.uploaded_at
               FROM enrollment_requirements er
               JOIN enrollment_applications ea ON ea.id = er.application_id
               JOIN terms t           ON t.id = ea.term_id
               JOIN academic_years ay ON ay.id = t.academic_year_id
               JOIN requirement_types rt ON rt.id = er.requirement_type_id
               JOIN enrollment_requirement_files f ON f.enrollment_requirement_id = er.id AND f.is_current = 1
              WHERE ea.student_id = ?" . ($forStudent ? '' : " AND ea.application_status <> 'DRAFT'") . '
              ORDER BY ea.created_at DESC, ea.id DESC, er.is_required DESC, rt.sort_order, rt.id',
            [$studentId]
        ) as $row) {
            $id = (int) $row['application_id'];
            $applications[$id] ??= [
                'id' => $id, 'application_number' => $row['application_number'], 'application_status' => $row['application_status'],
                'term' => $row['term_name'] . ', A.Y. ' . $row['academic_year_code'], 'files' => [],
            ];
            $applications[$id]['files'][] = $row;
        }

        $receipts = Database::select(
            "SELECT pay.id, pay.receipt_number, pay.amount, pay.payment_method, pay.paid_at, (SELECT bank_name FROM school_bank_accounts WHERE id = pay.bank_account_id) AS bank_name, fa.assessment_number,
                    (SELECT r.id FROM payments r WHERE r.reversed_payment_id = pay.id LIMIT 1) AS reversal_id
               FROM payments pay JOIN fee_assessments fa ON fa.id = pay.fee_assessment_id
              WHERE pay.student_id = ? AND pay.receipt_number IS NOT NULL
              ORDER BY pay.paid_at DESC, pay.id DESC",
            [$studentId]
        );

        $statements = Finance::assessmentsForStudent($studentId);
        $schedules = $forStudent ? AcademicRecordService::enrollmentsOf($studentId) : [];
        $proofs = $forStudent ? Database::select(
            'SELECT id, amount, method, reference_number, paid_on, status, submitted_at, original_filename, mime_type, file_size
               FROM payment_submissions WHERE student_id = ? ORDER BY submitted_at DESC, id DESC',
            [$studentId]
        ) : [];

        $files = array_sum(array_map(static fn (array $a) => count($a['files']), $applications));

        return [
            'applications' => array_values($applications),
            'receipts'     => $receipts,
            'statements'   => $statements,
            'schedules'    => $schedules,
            'proofs'       => $proofs,
            'count'        => $files + count($receipts) + count($statements) + count($schedules) + count($proofs),
        ];
    }
}
