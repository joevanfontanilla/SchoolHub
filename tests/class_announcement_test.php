<?php
/**
 * Teacher announcements to their own classes (docs/16).
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\ValidationException;
use App\Models\Announcement;
use App\Models\User;
use App\Services\AnnouncementService;
use App\Services\EnlistmentService;

/**
 * Two enrolled students in two sections of the current term, each section with one class
 * taught by its own teacher.
 *
 * @return array<string,mixed>
 */
function twoClasses(): array
{
    $a = enrolledStudent(currentTermId());
    $teacherA = makeUser(User::ROLE_TEACHER);
    $classA = makeClass($a['registrar'], $a['section_id'], makeSubject(), $teacherA->id, [meeting(1, '08:00', '09:00')]);

    $b = enrolledStudent(currentTermId());
    $teacherB = makeUser(User::ROLE_TEACHER);
    $classB = makeClass($b['registrar'], $b['section_id'], makeSubject(), $teacherB->id, [meeting(2, '08:00', '09:00')]);

    return compact('a', 'b', 'teacherA', 'teacherB', 'classA', 'classB');
}

function classPost(array $overrides = []): array
{
    return array_merge([
        'title'    => 'Room change for Monday',
        'content'  => 'We meet in the computer laboratory on Monday instead of our usual room.',
        'category' => 'Academic',
        'audience' => Announcement::CLASSES,
    ], $overrides);
}

/** @return array<int,int> which of these users got the announcement's notification */
function classNotified(int $announcementId, User ...$users): array
{
    $notified = array_map('intval', array_column(Database::select(
        "SELECT user_id FROM notifications WHERE type = 'announcement.published' AND link_url = ?",
        ['/portal/announcements/' . $announcementId]
    ), 'user_id'));
    return array_values(array_intersect(array_map(static fn (User $u) => $u->id, $users), $notified));
}

return [
    'a teacher posts to their own class: its students read it and are notified, nobody else is' => function (TestRunner $t): void {
        try {
            $c = twoClasses();
            $id = AnnouncementService::save($c['teacherA'], null, classPost(['classes' => [$c['classA']]]), null, 'publish');
            $row = Announcement::find($id);

            $t->assertEquals([Announcement::CLASSES, 'PUBLISHED'], [$row['audience'], $row['status']]);
            $t->assertEquals([$c['a']['student']->id], classNotified($id, $c['a']['student'], $c['b']['student'], $c['teacherA'], $c['teacherB'], $c['a']['registrar']), 'Only the enlisted student is notified; the teacher is the author');
            $t->assertEquals(1, (int) $row['recipient_count']);

            $t->assertTrue(Announcement::isVisibleTo($id, $c['a']['student']), 'The enlisted student reads it');
            $t->assertTrue(Announcement::isVisibleTo($id, $c['teacherA']), 'The class teacher reads it');
            $t->assertFalse(Announcement::isVisibleTo($id, $c['b']['student']), 'A student of another class does not');
            $t->assertFalse(Announcement::isVisibleTo($id, $c['teacherB']), 'Another teacher does not');
            $t->assertFalse(Announcement::isVisibleTo($id, $c['a']['registrar']), 'Staff outside the class do not');

            $code = (string) Database::scalar('SELECT sub.code FROM class_schedules cs JOIN subjects sub ON sub.id = cs.subject_id WHERE cs.id = ?', [$c['classA']]);
            $t->assertContains('Students of ' . $code, Announcement::audienceLabel($row));
            $t->assertEquals([$id], array_column(Announcement::classListFor($c['teacherA']->id), 'id'), 'Listed for its teacher');
            $t->assertEquals([], Announcement::classListFor($c['teacherB']->id), 'Not listed for another teacher');
        } finally {
            deleteTestUsers();
        }
    },

    'a teacher may choose only their own active classes, and only a class audience' => function (TestRunner $t): void {
        try {
            $c = twoClasses();
            $fieldsOf = static function (callable $callback): array {
                try {
                    $callback();
                } catch (ValidationException $e) {
                    return array_keys($e->errors());
                }
                return [];
            };

            $t->assertEquals(['classes'], $fieldsOf(static fn () => AnnouncementService::save($c['teacherA'], null, classPost(['classes' => [$c['classB']]]), null, 'publish')), "Another teacher's class");
            $t->assertEquals(['classes'], $fieldsOf(static fn () => AnnouncementService::save($c['teacherA'], null, classPost(['classes' => [$c['classA'], $c['classB']]]), null, 'publish')), 'One of the classes is not theirs');
            $t->assertEquals(['classes'], $fieldsOf(static fn () => AnnouncementService::save($c['teacherA'], null, classPost(), null, 'publish')), 'No class chosen');

            Database::update('class_schedules', ['status' => 'CLOSED'], ['id' => $c['classA']]);
            $t->assertEquals(['classes'], $fieldsOf(static fn () => AnnouncementService::save($c['teacherA'], null, classPost(['classes' => [$c['classA']]]), null, 'publish')), 'A closed class');
            Database::update('class_schedules', ['status' => 'ACTIVE'], ['id' => $c['classA']]);

            $t->assertEquals(403, statusOf(static fn () => AnnouncementService::save($c['teacherA'], null, classPost(['audience' => 'EVERYONE']), null, 'publish')), 'A teacher cannot write to everyone');
            $t->assertEquals(403, statusOf(static fn () => AnnouncementService::save(makeUser(User::ROLE_CASHIER), null, classPost(['classes' => [$c['classA']]]), null, 'publish')), 'A cashier cannot post to classes');
            $t->assertEquals(403, statusOf(static fn () => AnnouncementService::save($c['a']['registrar'], null, classPost(['classes' => [$c['classA']]]), null, 'publish')), 'Class posts are for teachers');
            $t->assertEquals(0, (int) Database::scalar("SELECT COUNT(*) FROM announcements WHERE audience = 'CLASSES' AND author_user_id IN (?, ?)", [$c['teacherA']->id, $c['a']['registrar']->id]), 'Nothing was written');
        } finally {
            deleteTestUsers();
        }
    },

    'only its teacher edits a class announcement; the registrar may archive it but not rewrite it' => function (TestRunner $t): void {
        try {
            $c = twoClasses();
            $id = AnnouncementService::save($c['teacherA'], null, classPost(['classes' => [$c['classA']]]), null, 'publish');

            $t->assertEquals(404, statusOf(static fn () => AnnouncementService::save($c['teacherB'], $id, classPost(['classes' => [$c['classB']]]), null, 'save')), "Another teacher's announcement does not exist for them");
            $t->assertEquals(403, statusOf(static fn () => AnnouncementService::save($c['a']['registrar'], $id, classPost(['classes' => [$c['classA']]]), null, 'save')), 'The registrar cannot rewrite it');
            $t->assertTrue(in_array(statusOf(static fn () => AnnouncementService::archive($c['teacherB'], $id)), [403, 404], true), 'Another teacher cannot archive it');

            // Its own teacher edits it without notifying anyone again; the audience stays the class.
            $before = (int) Database::scalar('SELECT COUNT(*) FROM notifications WHERE link_url = ?', ['/portal/announcements/' . $id]);
            AnnouncementService::save($c['teacherA'], $id, classPost(['title' => 'Room change for Monday and Wednesday', 'audience' => 'EVERYONE', 'classes' => [$c['classA']]]), null, 'save');
            $t->assertEquals([Announcement::CLASSES, 'Room change for Monday and Wednesday'], [Announcement::find($id)['audience'], Announcement::find($id)['title']], 'The audience cannot be widened');
            $t->assertEquals($before, (int) Database::scalar('SELECT COUNT(*) FROM notifications WHERE link_url = ?', ['/portal/announcements/' . $id]), 'Nobody is notified again');

            AnnouncementService::archive($c['a']['registrar'], $id);
            $t->assertEquals('ARCHIVED', Announcement::find($id)['status'], 'The registrar took it down');
            $t->assertFalse(Announcement::isVisibleTo($id, $c['a']['student']));
            $t->assertEquals(403, statusOf(static fn () => AnnouncementService::restore($c['a']['registrar'], $id)), 'Only its teacher restores it');
            AnnouncementService::restore($c['teacherA'], $id);
            $t->assertEquals('DRAFT', Announcement::find($id)['status']);
        } finally {
            deleteTestUsers();
        }
    },

    'the class audience follows enlistment and teaching: a dropped student stops seeing it, a new teacher starts' => function (TestRunner $t): void {
        try {
            $c = twoClasses();
            $id = AnnouncementService::save($c['teacherA'], null, classPost(['classes' => [$c['classA']]]), null, 'publish');
            $t->assertTrue(Announcement::isVisibleTo($id, $c['a']['student']));

            EnlistmentService::dropClass($c['a']['registrar'], (int) enlistmentIdFor($c['a']['enrollment_id'], $c['classA']), 'Changed schedule');
            $t->assertFalse(Announcement::isVisibleTo($id, $c['a']['student']), 'A dropped student no longer sees it');

            $newTeacher = makeUser(User::ROLE_TEACHER);
            Database::update('class_schedules', ['teacher_user_id' => $newTeacher->id], ['id' => $c['classA']]);
            $t->assertTrue(Announcement::isVisibleTo($id, $newTeacher), 'The teacher who took over the class reads it');
            $t->assertFalse(Announcement::isVisibleTo($id, $c['teacherA']), 'The former teacher no longer reads it');
        } finally {
            deleteTestUsers();
        }
    },
];
