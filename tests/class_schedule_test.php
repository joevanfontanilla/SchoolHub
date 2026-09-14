<?php
/**
 * Building classes: subject, teacher, meetings, and conflict checks (docs/13).
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\ValidationException;
use App\Models\User;
use App\Services\ClassScheduleService as Schedule;

/** The message of the ValidationException a callback throws, with every field error. */
function scheduleRefusal(callable $callback): string
{
    try {
        $callback();
    } catch (ValidationException $e) {
        return $e->getMessage() . ' | ' . implode(' | ', array_merge(...array_values($e->errors()) ?: [[]]));
    }
    return '';
}

/** A registrar and an empty fixture section in the open term. @return array{0:User,1:int} */
function scheduleSection(): array
{
    $registrar = makeUser(User::ROLE_REGISTRAR);
    $sectionId = Database::insert('sections', [
        'code' => testCode('X'),
        'program_id' => (int) Database::scalar('SELECT id FROM programs WHERE is_active = 1 ORDER BY id LIMIT 1'),
        'year_level_id' => (int) Database::scalar('SELECT id FROM year_levels ORDER BY sequence LIMIT 1'),
        'term_id' => openTermId(), 'capacity' => 40, 'is_active' => 1,
    ]);
    return [$registrar, $sectionId];
}

return [
    'a class is saved with its weekly meetings and audited' => function (TestRunner $t): void {
        try {
            [$registrar, $sectionId] = scheduleSection();
            $teacher = makeUser(User::ROLE_TEACHER);
            $room = makeRoom();

            $classId = makeClass($registrar, $sectionId, makeSubject(), $teacher->id, [meeting(1, '08:00', '09:00', $room), meeting(3, '08:00', '09:00', $room)]);

            $class = Database::selectOne('SELECT * FROM class_schedules WHERE id = ?', [$classId]);
            $t->assertEquals($teacher->id, (int) $class['teacher_user_id']);
            $t->assertEquals(openTermId(), (int) $class['term_id'], 'The class takes its section\'s term');
            $t->assertEquals(2, (int) Database::scalar('SELECT COUNT(*) FROM class_meetings WHERE class_schedule_id = ?', [$classId]));
            $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'class.created' AND target_id = ? AND user_id = ?", [$classId, $registrar->id]));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a teacher cannot teach two overlapping classes, but back-to-back classes are fine' => function (TestRunner $t): void {
        try {
            [$registrar, $sectionA] = scheduleSection();
            $sectionB = siblingSection($sectionA);
            $teacher = makeUser(User::ROLE_TEACHER);
            makeClass($registrar, $sectionA, makeSubject(), $teacher->id, [meeting(1, '08:00', '09:00')]);

            $refusal = scheduleRefusal(static fn () => makeClass($registrar, $sectionB, makeSubject(), $teacher->id, [meeting(1, '08:30', '09:30')]));
            $t->assertContains('The teacher already teaches', $refusal);
            $t->assertContains('Monday 8:00 AM–9:00 AM', $refusal, 'The clash is named with its day and time');

            $t->assertTrue(makeClass($registrar, $sectionB, makeSubject(), $teacher->id, [meeting(1, '09:00', '10:00')]) > 0, '9–10 right after 8–9 is allowed');
            $t->assertTrue(makeClass($registrar, siblingSection($sectionA), makeSubject(), $teacher->id, [meeting(2, '08:00', '09:00')]) > 0, 'Another day is allowed');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a room cannot be double-booked, and one section\'s classes cannot overlap' => function (TestRunner $t): void {
        try {
            [$registrar, $sectionA] = scheduleSection();
            $room = makeRoom();
            makeClass($registrar, $sectionA, makeSubject(), null, [meeting(5, '13:00', '15:00', $room)]);

            $refusal = scheduleRefusal(static fn () => makeClass($registrar, siblingSection($sectionA), makeSubject(), null, [meeting(5, '14:00', '16:00', $room)]));
            $t->assertContains('is already used by', $refusal);

            $refusal = scheduleRefusal(static fn () => makeClass($registrar, $sectionA, makeSubject(), null, [meeting(5, '14:30', '15:30')]));
            $t->assertContains('This section already has', $refusal);
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'meeting times must be valid' => function (TestRunner $t): void {
        try {
            [$registrar, $sectionId] = scheduleSection();
            $subject = makeSubject();
            $cases = [
                'no meetings'           => [[], 'at least one weekly meeting'],
                'end before start'      => [[meeting(1, '10:00', '09:00')], 'end time must be after'],
                'before 6 AM'           => [[meeting(1, '05:30', '07:00')], 'between 6:00 AM and 9:00 PM'],
                'after 9 PM'            => [[meeting(1, '20:00', '21:30')], 'between 6:00 AM and 9:00 PM'],
                'no day'                => [[meeting(0, '08:00', '09:00')], 'choose a day'],
                'bad time'              => [[meeting(1, '8am', '9am')], 'enter start and end times'],
                'own meetings overlap'  => [[meeting(2, '08:00', '10:00'), meeting(2, '09:00', '11:00')], 'overlap on Tuesday'],
            ];
            foreach ($cases as $label => [$meetings, $expected]) {
                $t->assertContains($expected, scheduleRefusal(static fn () => makeClass($registrar, $sectionId, $subject, null, $meetings)), $label);
            }
            $t->assertEquals(0, (int) Database::scalar('SELECT COUNT(*) FROM class_schedules WHERE section_id = ?', [$sectionId]), 'Nothing was saved');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a subject of another program is refused, and a section has each subject once' => function (TestRunner $t): void {
        try {
            [$registrar, $sectionId] = scheduleSection();
            $otherProgram = Database::insert('programs', ['code' => testCode('P'), 'name' => 'Other', 'years_duration' => 4, 'is_active' => 1]);
            $foreign = makeSubject(3, [], ['program_id' => $otherProgram]);

            $t->assertContains('belongs to another program', scheduleRefusal(static fn () => makeClass($registrar, $sectionId, $foreign, null, [meeting(1, '08:00', '09:00')])));

            $subject = makeSubject();
            makeClass($registrar, $sectionId, $subject, null, [meeting(1, '08:00', '09:00')]);
            $t->assertContains('already has a class for that subject', scheduleRefusal(static fn () => makeClass($registrar, $sectionId, $subject, null, [meeting(2, '08:00', '09:00')])));

            $cashier = makeUser(User::ROLE_CASHIER);
            $t->assertContains('active teacher', scheduleRefusal(static fn () => makeClass($registrar, $sectionId, makeSubject(), $cashier->id, [meeting(3, '08:00', '09:00')])));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'changing a class\'s teacher re-checks that teacher\'s timetable' => function (TestRunner $t): void {
        try {
            [$registrar, $sectionA] = scheduleSection();
            $busy = makeUser(User::ROLE_TEACHER);
            makeClass($registrar, $sectionA, makeSubject(), $busy->id, [meeting(4, '10:00', '11:00')]);

            $sectionB = siblingSection($sectionA);
            $subject = makeSubject();
            $classId = makeClass($registrar, $sectionB, $subject, null, [meeting(4, '10:00', '11:00')]);

            $refusal = scheduleRefusal(static fn () => Schedule::saveClass($registrar, $sectionB, $classId, [
                'subject_id' => $subject, 'teacher_user_id' => $busy->id, 'meetings' => [meeting(4, '10:00', '11:00')],
            ]));
            $t->assertContains('The teacher already teaches', $refusal);
            $t->assertNull(Database::scalar('SELECT teacher_user_id FROM class_schedules WHERE id = ?', [$classId]), 'The class was left unchanged');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a new class enlists the students already enrolled in the section' => function (TestRunner $t): void {
        try {
            $enrolled = enrolledStudent();
            $result = Schedule::saveClass($enrolled['registrar'], $enrolled['section_id'], null, [
                'subject_id' => makeSubject(3), 'meetings' => [meeting(1, '08:00', '09:00')],
            ]);

            $t->assertNotNull(enlistmentIdFor($enrolled['enrollment_id'], $result['class_id']));
            $t->assertEquals(1, count($result['sync'][$enrolled['enrollment_id']]['enlisted']));
            $t->assertEquals(3.0, (float) Database::scalar('SELECT total_units FROM enrollments WHERE id = ?', [$enrolled['enrollment_id']]));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a class that has been taught cannot be closed; an untaught one closes and drops its students' => function (TestRunner $t): void {
        try {
            $enrolled = enrolledStudent();
            $registrar = $enrolled['registrar'];
            $taught = makeClass($registrar, $enrolled['section_id'], makeSubject(), null, [meeting(1, '08:00', '09:00')]);
            $untaught = makeClass($registrar, $enrolled['section_id'], makeSubject(), null, [meeting(2, '08:00', '09:00')]);

            Database::insert('attendance', [
                'class_enlistment_id' => enlistmentIdFor($enrolled['enrollment_id'], $taught),
                'attendance_date' => date('Y-m-d'), 'status' => 'PRESENT', 'recorded_by' => $registrar->id, 'recorded_at' => date('Y-m-d H:i:s'),
            ]);
            $t->assertContains('already has grades or attendance', scheduleRefusal(static fn () => Schedule::closeClass($registrar, $taught)));

            Schedule::closeClass($registrar, $untaught);
            $t->assertEquals('CLOSED', Database::scalar('SELECT status FROM class_schedules WHERE id = ?', [$untaught]));
            $t->assertEquals('DROPPED', Database::scalar('SELECT status FROM class_enlistments WHERE id = ?', [enlistmentIdFor($enrolled['enrollment_id'], $untaught)]));
            $t->assertEquals(3.0, (float) Database::scalar('SELECT total_units FROM enrollments WHERE id = ?', [$enrolled['enrollment_id']]), 'Only the taught class still counts');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'only the registrar builds or closes classes' => function (TestRunner $t): void {
        try {
            [, $sectionId] = scheduleSection();
            $teacher = makeUser(User::ROLE_TEACHER);
            $t->assertEquals(403, statusOf(static fn () => Schedule::saveClass($teacher, $sectionId, null, ['subject_id' => makeSubject(), 'meetings' => [meeting(1, '08:00', '09:00')]])));
            $t->assertEquals(403, statusOf(static fn () => Schedule::closeClass(makeUser(User::ROLE_SUPER_ADMIN), 1)));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },
];
