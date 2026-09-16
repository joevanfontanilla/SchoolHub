<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Finance;

/**
 * Assembles a student's Certificate of Registration (COR): the official one-page form for an
 * enrolled term, with the enlisted subjects and their schedule, the total units, and the fee
 * assessment. Read-only; it only gathers data the enrollment and finance services already own,
 * so both the student's own COR and the registrar's view of it show exactly the same thing.
 */
final class RegistrationDocumentService
{
    /**
     * The COR for a student's enrolled term. With no term id, the most recent enrolled term.
     * Returns null when the student has no enrolled term.
     *
     * @return array{enrollment:array<string,mixed>,classes:array<int,array<string,mixed>>,units:float,assessment:?array<string,mixed>,items:array<int,array<string,mixed>>,adjustments:array<int,array<string,mixed>>,payments:array<int,array<string,mixed>>}|null
     */
    public static function certificate(int $studentId, ?int $termId = null): ?array
    {
        $enrollments = AcademicRecordService::enrollmentsOf($studentId);
        $enrollment = $enrollments[0] ?? null;
        if ($termId !== null) {
            foreach ($enrollments as $candidate) {
                if ((int) $candidate['term_id'] === $termId) {
                    $enrollment = $candidate;
                    break;
                }
            }
        }
        if ($enrollment === null) {
            return null;
        }

        $classes = array_values(array_filter(
            AcademicRecordService::classesForEnrollment((int) $enrollment['id']),
            static fn ($c) => $c['enlistment_status'] === 'ENLISTED'
        ));
        $units = array_sum(array_map(static fn ($c) => (float) $c['units'], $classes));

        // The assessment is tied to the enrollment's application; a term may not be assessed yet.
        $applicationId = (int) ($enrollment['application_id'] ?? 0);
        $assessment = $applicationId > 0 ? Finance::assessmentForApplication($applicationId) : null;
        $assessmentId = $assessment !== null ? (int) $assessment['id'] : 0;

        return [
            'enrollment'  => $enrollment,
            'classes'     => $classes,
            'units'       => $units,
            'assessment'  => $assessment,
            'items'       => $assessmentId > 0 ? Finance::items($assessmentId) : [],
            'adjustments' => $assessmentId > 0 ? Finance::adjustments($assessmentId) : [],
            'payments'    => $assessmentId > 0 ? Finance::payments($assessmentId) : [],
        ];
    }
}
