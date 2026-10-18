<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Models\CreditedSubject;
use App\Models\Student;
use App\Models\User;

/**
 * The registrar credits a subject to a specific student (transfer credit, or study completed
 * before the school moved onto this system; docs/28). A credited subject counts as passed, so
 * it satisfies prerequisites and curriculum progress the same way an earned final does
 * (AcademicRecordService::passedSubjectIds unions these in). Every change is audited.
 *
 * Requires the `academic.credit` permission, which only the registrar holds by default.
 */
final class CreditedSubjectService
{
    /**
     * Credit one subject to a student.
     *
     * @param array<string,mixed> $input subject_id, and optional grade, source, remarks
     * @throws ValidationException|HttpException
     */
    public static function add(User $actor, int $studentId, array $input): void
    {
        self::assertCan($actor);

        $student = Student::find($studentId);
        if ($student === null) {
            throw HttpException::notFound('That student was not found.');
        }

        $subjectId = (int) ($input['subject_id'] ?? 0);
        $subject = $subjectId > 0
            ? Database::selectOne('SELECT id, units, code, title FROM subjects WHERE id = ? AND is_active = 1', [$subjectId])
            : null;

        $errors = [];
        if ($subject === null) {
            $errors['subject_id'] = ['Choose a subject to credit.'];
        } elseif ((int) Database::scalar('SELECT COUNT(*) FROM credited_subjects WHERE student_id = ? AND subject_id = ?', [$studentId, $subjectId]) > 0) {
            $errors['subject_id'] = ['That subject is already credited to this student.'];
        } elseif (AcademicRecordService::hasPassed($studentId, $subjectId)) {
            $errors['subject_id'] = ['The student has already passed that subject here, so it does not need crediting.'];
        }

        // An optional recorded grade must be an equivalent from the Official Grading System (not a mark).
        $grade = trim((string) ($input['grade'] ?? ''));
        if ($grade !== '' && (!is_numeric($grade) || !in_array(number_format((float) $grade, 2, '.', ''), GradeCalculator::equivalents(), true))) {
            $errors['grade'] = ['Leave the grade blank, or enter a grade from the Official Grading System, like 1.40.'];
        }

        $source = trim((string) ($input['source'] ?? ''));
        $remarks = trim((string) ($input['remarks'] ?? ''));
        if (mb_strlen($source) > 150) {
            $errors['source'] = ['Keep the source under 150 characters.'];
        }
        if (mb_strlen($remarks) > 255) {
            $errors['remarks'] = ['Keep the note under 255 characters.'];
        }

        if ($errors !== []) {
            throw ValidationException::withErrors($errors);
        }

        Database::transaction(static function () use ($actor, $studentId, $subject, $grade, $source, $remarks): void {
            Database::insert('credited_subjects', [
                'student_id'  => $studentId,
                'subject_id'  => (int) $subject['id'],
                'units'       => $subject['units'],
                'grade'       => $grade !== '' ? $grade : null,
                'source'      => $source !== '' ? $source : null,
                'remarks'     => $remarks !== '' ? $remarks : null,
                'credited_by' => $actor->id,
            ]);
            AuditService::log(
                'student.subject_credited',
                'students',
                'Student',
                $studentId,
                null,
                ['subject' => $subject['code'], 'units' => $subject['units'], 'source' => $source !== '' ? $source : null],
                'SUCCESS',
                null,
                $actor->id,
                $actor->role_name
            );
        });
    }

    /**
     * Remove a credit from a student (a mistaken entry). The subject stops counting as passed.
     *
     * @throws HttpException
     */
    public static function remove(User $actor, int $studentId, int $creditId): void
    {
        self::assertCan($actor);

        $credit = CreditedSubject::find($creditId);
        if ($credit === null || (int) $credit['student_id'] !== $studentId) {
            throw HttpException::notFound('That credited subject was not found.');
        }

        Database::transaction(static function () use ($actor, $studentId, $creditId, $credit): void {
            $subject = Database::selectOne('SELECT code FROM subjects WHERE id = ?', [(int) $credit['subject_id']]);
            Database::query('DELETE FROM credited_subjects WHERE id = ?', [$creditId]);
            AuditService::log(
                'student.subject_credit_removed',
                'students',
                'Student',
                $studentId,
                ['subject' => $subject['code'] ?? null],
                null,
                'SUCCESS',
                null,
                $actor->id,
                $actor->role_name
            );
        });
    }

    private static function assertCan(User $actor): void
    {
        if (!$actor->hasPermission('academic.credit')) {
            throw HttpException::forbidden('You are not allowed to credit subjects to a student.');
        }
    }
}
