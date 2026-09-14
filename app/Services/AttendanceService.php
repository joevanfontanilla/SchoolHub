<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\ValidationException;
use App\Models\User;

/**
 * Class attendance (docs/12). Recorded by the class's own teacher, for a day the
 * class actually meets, within its term, never in the future.
 */
final class AttendanceService
{
    public const STATUSES = ['PRESENT', 'ABSENT', 'LATE', 'EXCUSED'];

    /**
     * @param array<int|string,mixed> $entries enlistment id => status ('' leaves it unchanged)
     * @param array<int|string,mixed> $remarks enlistment id => optional note
     * @return array{saved:int,changed:int}
     */
    public static function record(User $teacher, int $classId, string $date, array $entries, array $remarks = []): array
    {
        $class = GradingService::ownedClass($teacher, $classId);
        $problem = self::dateProblem($class, $date);
        if ($problem !== null) {
            throw ValidationException::withErrors(['date' => [$problem]], $problem);
        }

        return Database::transaction(static function () use ($teacher, $class, $date, $entries, $remarks): array {
            $enlisted = [];
            foreach (Database::select("SELECT id FROM class_enlistments WHERE class_schedule_id = ? AND status = 'ENLISTED'", [$class['id']]) as $row) {
                $enlisted[(int) $row['id']] = true;
            }

            $errors = [];
            $clean = [];
            foreach ($entries as $enlistmentId => $status) {
                $enlistmentId = (int) $enlistmentId;
                $status = strtoupper(trim((string) $status));
                if ($status === '') {
                    continue;
                }
                if (!isset($enlisted[$enlistmentId])) {
                    $errors["attendance.{$enlistmentId}"] = ['That student is not enlisted in this class.'];
                } elseif (!in_array($status, self::STATUSES, true)) {
                    $errors["attendance.{$enlistmentId}"] = ['Choose present, absent, late, or excused.'];
                } else {
                    $clean[$enlistmentId] = $status;
                }
            }
            if ($errors !== []) {
                throw ValidationException::withErrors($errors, 'Some entries were not valid, so nothing was saved.');
            }

            $saved = 0;
            $changes = [];
            $now = date('Y-m-d H:i:s');

            foreach ($clean as $enlistmentId => $status) {
                $note = mb_substr(trim((string) ($remarks[$enlistmentId] ?? '')), 0, 255) ?: null;
                $existing = Database::selectOne(
                    'SELECT * FROM attendance WHERE class_enlistment_id = ? AND attendance_date = ? FOR UPDATE',
                    [$enlistmentId, $date]
                );

                if ($existing === null) {
                    Database::insert('attendance', [
                        'class_enlistment_id' => $enlistmentId,
                        'attendance_date'     => $date,
                        'status'              => $status,
                        'remarks'             => $note,
                        'recorded_by'         => $teacher->id,
                        'recorded_at'         => $now,
                    ]);
                    $saved++;
                } elseif ($existing['status'] !== $status || $existing['remarks'] !== $note) {
                    Database::update('attendance', ['status' => $status, 'remarks' => $note, 'recorded_by' => $teacher->id, 'recorded_at' => $now], ['id' => $existing['id']]);
                    $changes[$enlistmentId] = $existing['status'] . '->' . $status;
                }
            }

            if ($saved > 0 || $changes !== []) {
                // One entry per sheet save: a full roster every day would otherwise flood the log.
                AuditService::log('attendance.recorded', 'academic', 'ClassSchedule', (int) $class['id'], $changes === [] ? null : ['changed' => $changes], [
                    'date' => $date, 'new_entries' => $saved, 'changed_entries' => count($changes),
                ], 'SUCCESS', null, $teacher->id, $teacher->role_name);
            }

            return ['saved' => $saved, 'changed' => count($changes)];
        });
    }

    /**
     * Why attendance cannot be taken on this date, or null when it can.
     *
     * @param array<string,mixed> $class
     */
    public static function dateProblem(array $class, string $date): ?string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            return 'Choose a valid date.';
        }
        if ($date > date('Y-m-d')) {
            return 'Attendance cannot be recorded for a future date.';
        }

        $term = Database::selectOne('SELECT start_date, end_date, name FROM terms WHERE id = ?', [$class['term_id']]);
        if ($date < $term['start_date'] || $date > $term['end_date']) {
            return sprintf('%s runs from %s to %s, so %s is outside the term.', $term['name'], d($term['start_date']), d($term['end_date']), d($date));
        }

        $day = (int) $parsed->format('N');
        $meets = (int) Database::scalar('SELECT COUNT(*) FROM class_meetings WHERE class_schedule_id = ? AND day_of_week = ?', [$class['id'], $day]) > 0;
        if (!$meets) {
            return "{$class['code']} does not meet on " . ClassScheduleService::DAYS[$day] . 's.';
        }

        return null;
    }
}
