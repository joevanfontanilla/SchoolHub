<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\User;

/**
 * Building a section's classes: subject, teacher, and weekly meetings (docs/13).
 *
 * Every save re-checks, within the same term, that no meeting overlaps:
 *   - another class taught by the same teacher,
 *   - another class in the same room,
 *   - another class of the same section (a block student cannot be in two places),
 *   - another class an enlisted student already takes.
 * Two meetings overlap when they share a day and a.start < b.end AND b.start < a.end,
 * so back-to-back classes (8-9, then 9-10) are fine.
 */
final class ClassScheduleService
{
    public const DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
    public const EARLIEST = '06:00';
    public const LATEST = '21:00';

    /**
     * Create or update a class and replace its meetings.
     *
     * @param array{subject_id?:mixed,teacher_user_id?:mixed,meetings?:mixed} $input
     * @return array{class_id:int,sync:array} sync = the enlistment report for a new class
     */
    public static function saveClass(User $registrar, int $sectionId, ?int $classId, array $input): array
    {
        if (!$registrar->isRegistrar()) {
            throw HttpException::forbidden('Only the registrar can build class schedules.');
        }

        $section = Database::selectOne('SELECT * FROM sections WHERE id = ?', [$sectionId]);
        if ($section === null) {
            throw HttpException::notFound('That section was not found.');
        }
        $existing = null;
        if ($classId !== null) {
            $existing = Database::selectOne('SELECT * FROM class_schedules WHERE id = ? AND section_id = ?', [$classId, $sectionId]);
            if ($existing === null) {
                throw HttpException::notFound('That class was not found.');
            }
        }

        // Every class has a teacher: students' printed schedules show one for each subject.
        $validator = Validator::make($input, ['subject_id' => 'required|integer', 'teacher_user_id' => 'required|integer'], ['subject_id' => 'Subject', 'teacher_user_id' => 'Teacher']);
        $subjectId = (int) ($input['subject_id'] ?? 0);
        $teacherId = (int) ($input['teacher_user_id'] ?? 0) ?: null;

        $subject = Database::selectOne('SELECT * FROM subjects WHERE id = ?', [$subjectId]);
        if ($subject === null || (int) $subject['is_active'] !== 1) {
            $validator->addError('subject_id', 'Choose an active subject.');
        } elseif ($subject['program_id'] !== null && (int) $subject['program_id'] !== (int) $section['program_id']) {
            $validator->addError('subject_id', "{$subject['code']} belongs to another program.");
        }

        $duplicate = (int) Database::scalar(
            'SELECT COUNT(*) FROM class_schedules WHERE section_id = ? AND subject_id = ? AND id <> ?',
            [$sectionId, $subjectId, $classId ?? 0]
        ) > 0;
        if ($duplicate) {
            $validator->addError('subject_id', 'This section already has a class for that subject.');
        }

        if ($teacherId !== null) {
            $isTeacher = (int) Database::scalar(
                "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND r.name = 'Teacher' AND u.status = 'Active'",
                [$teacherId]
            ) > 0;
            if (!$isTeacher) {
                $validator->addError('teacher_user_id', 'The teacher must be an active teacher account.');
            }
        }

        $meetings = self::normalizeMeetings($input['meetings'] ?? [], $validator);

        if ($validator->fails()) {
            throw ValidationException::withErrors($validator->errors());
        }

        return Database::transaction(static function () use ($registrar, $section, $existing, $classId, $subjectId, $subject, $teacherId, $meetings): array {
            // Serialize schedule edits within a term so two saves cannot both pass
            // the conflict check and then clash with each other.
            Database::selectOne('SELECT id FROM terms WHERE id = ? FOR UPDATE', [$section['term_id']]);

            $conflicts = self::conflicts((int) $section['term_id'], (int) $section['id'], $classId, $teacherId, $meetings);
            if ($conflicts !== []) {
                throw ValidationException::withErrors(['meetings' => $conflicts], $conflicts[0]);
            }

            $data = [
                'term_id'         => (int) $section['term_id'],
                'section_id'      => (int) $section['id'],
                'subject_id'      => $subjectId,
                'teacher_user_id' => $teacherId,
                'status'          => 'ACTIVE',
            ];

            if ($existing === null) {
                $classId = Database::insert('class_schedules', $data);
            } else {
                Database::update('class_schedules', $data, ['id' => $classId]);
            }

            // Meetings are the class's configuration, not history: replaced as a set.
            Database::query('DELETE FROM class_meetings WHERE class_schedule_id = ?', [$classId]);
            foreach ($meetings as $meeting) {
                Database::insert('class_meetings', $meeting + ['class_schedule_id' => $classId]);
            }

            AuditService::log(
                $existing === null ? 'class.created' : 'class.updated',
                'academic',
                'ClassSchedule',
                $classId,
                $existing === null ? null : ['teacher_user_id' => $existing['teacher_user_id'], 'subject_id' => $existing['subject_id']],
                ['section' => $section['code'], 'subject' => $subject['code'], 'teacher_user_id' => $teacherId, 'meetings' => count($meetings)],
                'SUCCESS',
                null,
                $registrar->id,
                $registrar->role_name
            );

            $sync = $existing === null ? EnlistmentService::syncSection((int) $section['id'], $registrar) : [];

            return ['class_id' => $classId, 'sync' => $sync];
        });
    }

    /** Close a class that was never taught. Refused once grades or attendance exist. */
    public static function closeClass(User $registrar, int $classId): void
    {
        if (!$registrar->isRegistrar()) {
            throw HttpException::forbidden('Only the registrar can close classes.');
        }

        Database::transaction(static function () use ($registrar, $classId): void {
            $class = Database::selectOne('SELECT cs.*, sub.code FROM class_schedules cs JOIN subjects sub ON sub.id = cs.subject_id WHERE cs.id = ? FOR UPDATE', [$classId]);
            if ($class === null) {
                throw HttpException::notFound('That class was not found.');
            }
            $taught = (int) Database::scalar(
                'SELECT (SELECT COUNT(*) FROM grades g JOIN class_enlistments ce ON ce.id = g.class_enlistment_id WHERE ce.class_schedule_id = ?)
                      + (SELECT COUNT(*) FROM attendance a JOIN class_enlistments ce ON ce.id = a.class_enlistment_id WHERE ce.class_schedule_id = ?)',
                [$classId, $classId]
            );
            if ($taught > 0) {
                throw ValidationException::withMessage("{$class['code']} already has grades or attendance, so it cannot be closed. Drop individual students instead.");
            }

            $enrollments = array_column(Database::select("SELECT enrollment_id FROM class_enlistments WHERE class_schedule_id = ? AND status = 'ENLISTED'", [$classId]), 'enrollment_id');
            Database::query(
                "UPDATE class_enlistments SET status = 'DROPPED', dropped_at = ?, dropped_by = ?, drop_reason = 'Class closed' WHERE class_schedule_id = ? AND status = 'ENLISTED'",
                [date('Y-m-d H:i:s'), $registrar->id, $classId]
            );
            Database::update('class_schedules', ['status' => 'CLOSED'], ['id' => $classId]);
            foreach ($enrollments as $enrollmentId) {
                EnlistmentService::recomputeUnits((int) $enrollmentId);
            }

            AuditService::log('class.closed', 'academic', 'ClassSchedule', $classId, ['status' => 'ACTIVE'], ['status' => 'CLOSED', 'students_dropped' => count($enrollments)], 'SUCCESS', null, $registrar->id, $registrar->role_name);
        });
    }

    /**
     * Would enlisting this enrollment in this class clash with a class it already
     * takes? Returns a message naming the clash, or null.
     */
    public static function studentClash(int $enrollmentId, int $classId): ?string
    {
        $row = Database::selectOne(
            "SELECT osub.code AS other_code, m.day_of_week, om.start_time, om.end_time
               FROM class_meetings m
               JOIN class_enlistments ce ON ce.enrollment_id = ? AND ce.status = 'ENLISTED' AND ce.class_schedule_id <> ?
               JOIN class_meetings om    ON om.class_schedule_id = ce.class_schedule_id
                                        AND om.day_of_week = m.day_of_week
                                        AND om.start_time < m.end_time AND m.start_time < om.end_time
               JOIN class_schedules ocs  ON ocs.id = ce.class_schedule_id AND ocs.status = 'ACTIVE'
               JOIN subjects osub        ON osub.id = ocs.subject_id
              WHERE m.class_schedule_id = ?
              LIMIT 1",
            [$enrollmentId, $classId, $classId]
        );

        return $row === null ? null : sprintf(
            'Time conflict with %s on %s, %s.',
            $row['other_code'],
            self::DAYS[(int) $row['day_of_week']],
            self::timeRange($row['start_time'], $row['end_time'])
        );
    }

    /**
     * Every conflict these meetings would cause, as readable messages.
     *
     * @param array<int,array{day_of_week:int,start_time:string,end_time:string,room_id:?int}> $meetings
     * @return array<int,string>
     */
    public static function conflicts(int $termId, int $sectionId, ?int $classId, ?int $teacherId, array $meetings): array
    {
        $messages = [];

        foreach ($meetings as $meeting) {
            $overlaps = Database::select(
                "SELECT cs.id, cs.section_id, cs.teacher_user_id, m.room_id, m.day_of_week, m.start_time, m.end_time,
                        sub.code AS subject_code, sec.code AS section_code, r.code AS room_code
                   FROM class_meetings m
                   JOIN class_schedules cs ON cs.id = m.class_schedule_id
                   JOIN subjects sub       ON sub.id = cs.subject_id
                   JOIN sections sec       ON sec.id = cs.section_id
              LEFT JOIN rooms r            ON r.id = m.room_id
                  WHERE cs.term_id = ? AND cs.status = 'ACTIVE' AND cs.id <> ?
                    AND m.day_of_week = ?
                    AND m.start_time < ? AND ? < m.end_time",
                [$termId, $classId ?? 0, $meeting['day_of_week'], $meeting['end_time'], $meeting['start_time']]
            );

            foreach ($overlaps as $other) {
                $when = self::DAYS[(int) $other['day_of_week']] . ' ' . self::timeRange($other['start_time'], $other['end_time']);
                $what = "{$other['subject_code']} ({$other['section_code']})";

                if ($teacherId !== null && (int) $other['teacher_user_id'] === $teacherId) {
                    $messages[] = "The teacher already teaches {$what} on {$when}.";
                }
                if ($meeting['room_id'] !== null && (int) $other['room_id'] === $meeting['room_id']) {
                    $messages[] = "Room {$other['room_code']} is already used by {$what} on {$when}.";
                }
                if ((int) $other['section_id'] === $sectionId) {
                    $messages[] = "This section already has {$other['subject_code']} on {$when}.";
                }
            }

            // Irregular students enlisted in this class who take an overlapping class elsewhere.
            if ($classId !== null) {
                $studentClash = Database::selectOne(
                    "SELECT osub.code, om.day_of_week, om.start_time, om.end_time, COUNT(DISTINCT ce.enrollment_id) AS students
                       FROM class_enlistments ce
                       JOIN class_enlistments oce ON oce.enrollment_id = ce.enrollment_id AND oce.status = 'ENLISTED' AND oce.class_schedule_id <> ce.class_schedule_id
                       JOIN class_schedules ocs   ON ocs.id = oce.class_schedule_id AND ocs.status = 'ACTIVE' AND ocs.section_id <> ?
                       JOIN class_meetings om     ON om.class_schedule_id = ocs.id AND om.day_of_week = ? AND om.start_time < ? AND ? < om.end_time
                       JOIN subjects osub         ON osub.id = ocs.subject_id
                      WHERE ce.class_schedule_id = ? AND ce.status = 'ENLISTED'
                      GROUP BY osub.code, om.day_of_week, om.start_time, om.end_time
                      LIMIT 1",
                    [$sectionId, $meeting['day_of_week'], $meeting['end_time'], $meeting['start_time'], $classId]
                );
                if ($studentClash !== null) {
                    $messages[] = sprintf(
                        '%d enlisted student%s also take%s %s on %s %s.',
                        $studentClash['students'],
                        (int) $studentClash['students'] === 1 ? '' : 's',
                        (int) $studentClash['students'] === 1 ? 's' : '',
                        $studentClash['code'],
                        self::DAYS[(int) $studentClash['day_of_week']],
                        self::timeRange($studentClash['start_time'], $studentClash['end_time'])
                    );
                }
            }
        }

        return array_values(array_unique($messages));
    }

    public static function timeRange(string $start, string $end): string
    {
        return date('g:i A', strtotime($start)) . '–' . date('g:i A', strtotime($end));
    }

    /**
     * @param mixed $raw meetings[i][day_of_week|start_time|end_time|room_id]
     * @return array<int,array{day_of_week:int,start_time:string,end_time:string,room_id:?int}>
     */
    private static function normalizeMeetings(mixed $raw, Validator $validator): array
    {
        $meetings = [];
        foreach (is_array($raw) ? array_values($raw) : [] as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $day = (int) ($row['day_of_week'] ?? 0);
            $start = trim((string) ($row['start_time'] ?? ''));
            $end = trim((string) ($row['end_time'] ?? ''));
            $roomId = (int) ($row['room_id'] ?? 0) ?: null;

            if ($day === 0 && $start === '' && $end === '') {
                continue; // an unused blank row
            }

            $label = 'Meeting ' . ($index + 1);
            if (!isset(self::DAYS[$day])) {
                $validator->addError("meetings.{$index}", "{$label}: choose a day.");
                continue;
            }
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $start) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $end)) {
                $validator->addError("meetings.{$index}", "{$label}: enter start and end times.");
                continue;
            }
            if ($start >= $end) {
                $validator->addError("meetings.{$index}", "{$label}: the end time must be after the start time.");
                continue;
            }
            if ($start < self::EARLIEST || $end > self::LATEST) {
                $validator->addError("meetings.{$index}", "{$label}: classes run between 6:00 AM and 9:00 PM.");
                continue;
            }
            if ($roomId !== null && (int) Database::scalar('SELECT COUNT(*) FROM rooms WHERE id = ? AND is_active = 1', [$roomId]) === 0) {
                $validator->addError("meetings.{$index}", "{$label}: choose an active room.");
                continue;
            }

            $meetings[] = ['day_of_week' => $day, 'start_time' => $start . ':00', 'end_time' => $end . ':00', 'room_id' => $roomId];
        }

        if ($meetings === [] && $validator->passes()) {
            $validator->addError('meetings', 'Add at least one weekly meeting time.');
        }

        // The class's own meetings must not overlap each other.
        foreach ($meetings as $i => $a) {
            foreach ($meetings as $j => $b) {
                if ($j > $i && $a['day_of_week'] === $b['day_of_week'] && $a['start_time'] < $b['end_time'] && $b['start_time'] < $a['end_time']) {
                    $validator->addError('meetings', 'Two meetings of this class overlap on ' . self::DAYS[$a['day_of_week']] . '.');
                }
            }
        }

        return $meetings;
    }
}
