<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Enrollment application queries and status vocabulary (docs/26).
 *
 * Student-facing lookups always take the owning student's id, so a record that
 * belongs to someone else is simply not found.
 *
 * Status columns are never written here — only EnrollmentStatusService changes
 * them.
 */
final class EnrollmentApplication
{
    // application_status
    public const DRAFT                   = 'DRAFT';
    public const SUBMITTED               = 'SUBMITTED';
    public const UNDER_REVIEW            = 'UNDER_REVIEW';
    public const REQUIREMENTS_INCOMPLETE = 'REQUIREMENTS_INCOMPLETE';
    public const CORRECTION_REQUIRED     = 'CORRECTION_REQUIRED';
    public const APPROVED                = 'APPROVED';
    public const REJECTED                = 'REJECTED';
    public const CANCELLED               = 'CANCELLED';

    public const TERMINAL = [self::REJECTED, self::CANCELLED];

    /** States in which the student may upload documents (docs/26 transitions 1 and 4). */
    public const UPLOADS_UNLOCKED = [self::DRAFT, self::REQUIREMENTS_INCOMPLETE];

    /** States in which the student may edit the application form (docs/26 transitions 1 and 5). */
    public const EDITS_UNLOCKED = [self::DRAFT, self::CORRECTION_REQUIRED];

    // requirement status
    public const REQ_NOT_SUBMITTED       = 'NOT_SUBMITTED';
    public const REQ_SUBMITTED           = 'SUBMITTED';
    public const REQ_UNDER_REVIEW        = 'UNDER_REVIEW';
    public const REQ_VERIFIED            = 'VERIFIED';
    public const REQ_REJECTED            = 'REJECTED';
    public const REQ_CORRECTION_REQUIRED = 'CORRECTION_REQUIRED';

    private const SELECT = "SELECT ea.*,
                                   t.name AS term_name, ay.code AS academic_year_code,
                                   p.code AS program_code, p.name AS program_name,
                                   yl.name AS year_level_name
                              FROM enrollment_applications ea
                              JOIN terms t           ON t.id  = ea.term_id
                              JOIN academic_years ay ON ay.id = ea.academic_year_id
                              JOIN programs p        ON p.id  = ea.program_id
                              JOIN year_levels yl    ON yl.id = ea.year_level_id";

    /** @return array<string,mixed>|null */
    public static function findForStudent(int $id, int $studentId): ?array
    {
        return Database::selectOne(self::SELECT . ' WHERE ea.id = ? AND ea.student_id = ?', [$id, $studentId]);
    }

    /** Unscoped — for staff code paths that have already been authorized. */
    public static function find(int $id): ?array
    {
        return Database::selectOne(self::SELECT . ' WHERE ea.id = ?', [$id]);
    }

    /** @return array<string,mixed>|null the enrollment made from this application, with its section code */
    public static function enrollment(int $applicationId): ?array
    {
        return Database::selectOne(
            'SELECT e.*, sec.code AS section_code FROM enrollments e JOIN sections sec ON sec.id = e.section_id WHERE e.application_id = ?',
            [$applicationId]
        );
    }

    /** The application a requirement belongs to, or 0. */
    public static function applicationIdForRequirement(int $requirementId): int
    {
        return (int) Database::scalar('SELECT application_id FROM enrollment_requirements WHERE id = ?', [$requirementId]);
    }

    /** The document name of a requirement, e.g. "PSA Birth Certificate". */
    public static function requirementName(int $requirementId): string
    {
        return (string) Database::scalar(
            'SELECT rt.name FROM enrollment_requirements er JOIN requirement_types rt ON rt.id = er.requirement_type_id WHERE er.id = ?',
            [$requirementId]
        );
    }

    /** @return array<int,array<string,mixed>> newest first */
    public static function listForStudent(int $studentId): array
    {
        return Database::select(self::SELECT . ' WHERE ea.student_id = ? ORDER BY ea.created_at DESC, ea.id DESC', [$studentId]);
    }

    /** The application a student is currently working on: the newest non-terminal one, else the newest. */
    public static function currentForStudent(int $studentId): ?array
    {
        $placeholders = implode(',', array_fill(0, count(self::TERMINAL), '?'));
        return Database::selectOne(
            self::SELECT . " WHERE ea.student_id = ?
                             ORDER BY (ea.application_status IN ({$placeholders})) ASC, ea.created_at DESC, ea.id DESC
                             LIMIT 1",
            array_merge([$studentId], self::TERMINAL)
        );
    }

    public static function hasOpenApplicationForTerm(int $studentId, int $termId): bool
    {
        $placeholders = implode(',', array_fill(0, count(self::TERMINAL), '?'));
        return (int) Database::scalar(
            "SELECT COUNT(*) FROM enrollment_applications
              WHERE student_id = ? AND term_id = ? AND application_status NOT IN ({$placeholders})",
            array_merge([$studentId, $termId], self::TERMINAL)
        ) > 0;
    }

    /**
     * Requirements with their type details and the current file, if any.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function requirements(int $applicationId): array
    {
        return Database::select(
            "SELECT er.*, rt.code, rt.name, rt.description, rt.max_file_size_kb, rt.allowed_extensions,
                    f.id AS file_id, f.original_filename, f.file_size, f.mime_type, f.uploaded_at
               FROM enrollment_requirements er
               JOIN requirement_types rt ON rt.id = er.requirement_type_id
          LEFT JOIN enrollment_requirement_files f
                 ON f.enrollment_requirement_id = er.id AND f.is_current = 1
              WHERE er.application_id = ?
              ORDER BY er.is_required DESC, rt.sort_order, rt.id",
            [$applicationId]
        );
    }

    /** @return array<int,array<string,mixed>> oldest first */
    public static function history(int $applicationId): array
    {
        return Database::select(
            'SELECT h.*, u.first_name, u.last_name
               FROM enrollment_status_history h
          LEFT JOIN users u ON u.id = h.actor_user_id
              WHERE h.application_id = ?
              ORDER BY h.created_at, h.id',
            [$applicationId]
        );
    }

    /** Human label for any status value on any axis. */
    public static function label(string $status): string
    {
        return match ($status) {
            self::DRAFT                   => 'Draft',
            self::SUBMITTED               => 'Submitted',
            self::UNDER_REVIEW            => 'Under review',
            self::REQUIREMENTS_INCOMPLETE => 'Requirements incomplete',
            self::CORRECTION_REQUIRED     => 'Correction required',
            self::APPROVED                => 'Approved',
            self::REJECTED                => 'Rejected',
            self::CANCELLED               => 'Cancelled',
            'NOT_SUBMITTED'               => 'Not submitted',
            'VERIFIED'                    => 'Verified',
            'NOT_ASSESSED'                => 'Not assessed',
            'PENDING'                     => 'Payment pending',
            'PARTIAL'                     => 'Partially paid',
            'PAID'                        => 'Fully paid',
            'NOT_ENROLLED'                => 'Not enrolled',
            'ENROLLED'                    => 'Enrolled',
            default                       => ucwords(strtolower(str_replace('_', ' ', $status))),
        };
    }
}
