<?php
/**
 * Phase 6 communication (docs/16): announcements, their audience and delivery,
 * urgent banners, images, the notifications inbox, and the route gates.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Router;
use App\Core\UploadedFile;
use App\Models\Announcement;
use App\Models\Notification;
use App\Models\User;
use App\Services\AnnouncementService;
use App\Services\EnrollmentApplicationService;
use App\Services\NotificationService;

function roleId(string $name): int
{
    return (int) Database::scalar('SELECT id FROM roles WHERE name = ?', [$name]);
}

/** @return array<string,mixed> valid announcement input, with overrides */
function announcementInput(array $overrides = []): array
{
    return array_merge([
        'title'    => 'Enrollment for the second semester is open',
        'content'  => "Enrollment opens on Monday.\n\nBring your documents to the registrar.",
        'category' => 'Enrollment',
        'audience' => 'EVERYONE',
    ], $overrides);
}

/** A student whose latest application is for this program and year level. */
function studentIn(int $programId, int $yearLevelId): User
{
    $student = makeStudent();
    EnrollmentApplicationService::createDraft($student, array_merge(applicationInput(), ['program_id' => $programId, 'year_level_id' => $yearLevelId]));
    return $student;
}

/** @return array<int,int> the fixture users (from $users) notified about this announcement */
function notifiedAmong(int $announcementId, array $users): array
{
    $ids = array_map(static fn (User $u) => $u->id, $users);
    $notified = array_map('intval', array_column(Database::select(
        "SELECT user_id FROM notifications WHERE type = 'announcement.published' AND link_url = ?",
        ['/portal/announcements/' . $announcementId]
    ), 'user_id'));
    $found = array_values(array_intersect($ids, $notified));
    sort($found);
    return $found;
}

/** @return array<int,int> */
function idsOf(User ...$users): array
{
    $ids = array_map(static fn (User $u) => $u->id, $users);
    sort($ids);
    return $ids;
}

function communicationRoutes(string $prefix): array
{
    $router = new Router();
    require BASE_PATH . '/routes/web.php';
    return array_values(array_filter($router->routes(), static fn (array $r) => str_starts_with($r['pattern'], $prefix)));
}

function communicationAccess(array $route, ?User $user): ?int
{
    actingAs($user);
    // RequireAuth redirects a signed-out browser and exits; the permission gate behind it answers 401.
    $middleware = $user === null ? array_values(array_diff($route['middleware'], ['auth'])) : $route['middleware'];
    try {
        (new Router())->authorize($middleware, makeRequest($route['method'], str_replace('{id}', '1', $route['pattern'])));
    } catch (HttpException $e) {
        return $e->status();
    }
    return null;
}

return [
    'announcements are validated: text, category, groups, public only for everyone, dates, and image descriptions' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $errors = static fn (array $input, ?UploadedFile $image = null) => fieldErrors(static fn () => AnnouncementService::save($registrar, null, announcementInput($input), $image));

            $t->assertTrue(isset($errors(['title' => 'Hi'])['title']), 'Title too short');
            $t->assertTrue(isset($errors(['content' => 'short'])['content']), 'Text too short');
            $t->assertTrue(isset($errors(['category' => 'Gossip'])['category']), 'Unknown category');
            $t->assertTrue(isset($errors(['audience' => 'TARGETED'])['roles']), 'Chosen groups need at least one group');
            $t->assertTrue(isset($errors(['audience' => 'TARGETED', 'roles' => [999999]])['roles']), 'Unknown role');
            $t->assertTrue(isset($errors(['audience' => 'TARGETED', 'roles' => [roleId(User::ROLE_TEACHER)], 'programs' => [(int) Database::scalar('SELECT id FROM programs LIMIT 1')]])['programs']), 'Programs narrow students only');
            $t->assertTrue(isset($errors(['audience' => 'TARGETED', 'roles' => [roleId(User::ROLE_STUDENT)], 'programs' => [999999]])['programs']), 'Unknown program');
            $t->assertTrue(isset($errors(['audience' => 'TARGETED', 'roles' => [roleId(User::ROLE_STUDENT)], 'is_public' => '1'])['is_public']), 'A targeted announcement is never public');
            $t->assertTrue(isset($errors(['published_at' => 'next tuesday-ish'])['published_at']), 'Bad date');
            $t->assertTrue(isset($errors(['published_at' => date('Y-m-d\TH:i', strtotime('+2 days')), 'expires_at' => date('Y-m-d\TH:i', strtotime('+1 day'))])['expires_at']), 'Ends before it starts');
            $t->assertTrue(isset($errors([], UploadedFile::forTesting(fixtureFile('png'), 'poster.png'))['image_alt']), 'An image needs a description');
            $t->assertThrows(static fn () => AnnouncementService::save($registrar, null, announcementInput(['image_alt' => 'A poster']), UploadedFile::forTesting(fixtureFile('fake_pdf'), 'poster.png')), 'PNG');
            $t->assertTrue(isset(fieldErrors(static fn () => AnnouncementService::save($registrar, null, announcementInput(['expires_at' => date('Y-m-d\TH:i', strtotime('-1 hour'))]), null, 'publish'))['expires_at']), 'Publishing something already ended');
            AnnouncementService::save($registrar, null, announcementInput(), null, 'publish');

            $t->assertEquals(1, (int) Database::scalar('SELECT COUNT(*) FROM announcements WHERE author_user_id = ?', [$registrar->id]), 'Only the one valid publish was written');
            $t->assertEquals(0, count(glob(STORAGE_PATH . '/uploads/announcements/*') ?: []) - count(array_filter(array_column(Database::select('SELECT image_path FROM announcements WHERE image_path IS NOT NULL'), 'image_path'))), 'A refused upload leaves no file behind');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'only roles with the announcement permissions write or publish; teachers, cashiers, and students are refused' => function (TestRunner $t): void {
        try {
            foreach ([User::ROLE_TEACHER, User::ROLE_CASHIER, User::ROLE_STUDENT] as $role) {
                $t->assertEquals(403, statusOf(static fn () => AnnouncementService::save(makeUser($role), null, announcementInput())), "{$role} cannot write");
            }
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            $draft = AnnouncementService::save($registrar, null, announcementInput());
            $t->assertEquals('DRAFT', Announcement::find($draft)['status']);
            $t->assertEquals(403, statusOf(static fn () => AnnouncementService::archive(makeUser(User::ROLE_TEACHER), $draft)));

            $published = AnnouncementService::save($admin, null, announcementInput(['title' => 'Super Admin notice here']), null, 'publish');
            $row = Announcement::find($published);
            $t->assertEquals('PUBLISHED', $row['status']);
            $t->assertEquals((int) $admin->id, (int) $row['published_by']);
            $t->assertNotNull(Database::selectOne("SELECT id FROM audit_logs WHERE action = 'announcement.published' AND target_id = ?", [$published]), 'Publishing is audited');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the people notified are exactly the people who can read it, except the author: everyone, roles, and students by program and year level' => function (TestRunner $t): void {
        try {
            [$programA, $programB] = array_map('intval', array_column(Database::select('SELECT id FROM programs WHERE is_active = 1 ORDER BY id LIMIT 2'), 'id'));
            [$first, $second] = array_map('intval', array_column(Database::select('SELECT id FROM year_levels ORDER BY sequence LIMIT 2'), 'id'));

            $aFirst = studentIn($programA, $first);
            $aSecond = studentIn($programA, $second);
            $bFirst = studentIn($programB, $first);
            $noApplication = makeUser(User::ROLE_STUDENT);
            $teacher = makeUser(User::ROLE_TEACHER);
            $cashier = makeUser(User::ROLE_CASHIER);
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $disabled = makeUser(User::ROLE_TEACHER, 'TestPass123', User::STATUS_DISABLED);
            $locked = makeUser(User::ROLE_CASHIER, 'TestPass123', User::STATUS_LOCKED);
            $all = [$aFirst, $aSecond, $bFirst, $noApplication, $teacher, $cashier, $registrar, $disabled, $locked];

            $cases = [
                'everyone' => [announcementInput(), idsOf($aFirst, $aSecond, $bFirst, $noApplication, $teacher, $cashier, $registrar, $locked)],
                'teachers and cashiers' => [announcementInput(['audience' => 'TARGETED', 'roles' => [roleId(User::ROLE_TEACHER), roleId(User::ROLE_CASHIER)]]), idsOf($teacher, $cashier, $locked)],
                'all students' => [announcementInput(['audience' => 'TARGETED', 'roles' => [roleId(User::ROLE_STUDENT)]]), idsOf($aFirst, $aSecond, $bFirst, $noApplication)],
                'program A students' => [announcementInput(['audience' => 'TARGETED', 'roles' => [roleId(User::ROLE_STUDENT)], 'programs' => [$programA]]), idsOf($aFirst, $aSecond)],
                'program A first years' => [announcementInput(['audience' => 'TARGETED', 'roles' => [roleId(User::ROLE_STUDENT)], 'programs' => [$programA], 'year_levels' => [$first]]), idsOf($aFirst)],
                'first years of A or B, and teachers' => [announcementInput(['audience' => 'TARGETED', 'roles' => [roleId(User::ROLE_STUDENT), roleId(User::ROLE_TEACHER)], 'programs' => [$programA, $programB], 'year_levels' => [$first]]), idsOf($aFirst, $bFirst, $teacher)],
            ];

            foreach ($cases as $label => [$input, $expected]) {
                $id = AnnouncementService::save($registrar, null, $input, null, 'publish');
                // The author reads their own announcement but is not notified of it.
                $t->assertEquals(array_values(array_diff($expected, [$registrar->id])), notifiedAmong($id, $all), "{$label}: notified");
                $readers = array_values(array_map(static fn (User $u) => $u->id, array_filter($all, static fn (User $u) => Announcement::isVisibleTo($id, $u))));
                sort($readers);
                $t->assertEquals($expected, $readers, "{$label}: can read");
                $t->assertEquals((int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE link_url = ?", ['/portal/announcements/' . $id]), (int) Announcement::find($id)['recipient_count'], "{$label}: recipient count");
            }

            $t->assertTrue(Announcement::countForUser($aFirst) >= 4, 'The program A first year sees their four');
            $t->assertEquals([], array_filter(Announcement::forUser($bFirst, 50), static fn ($a) => str_contains(Announcement::audienceLabel($a), 'Second Year')), 'Nobody sees announcements narrowed away from them');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'drafts are invisible and silent; a scheduled announcement is delivered once when it comes due; expired and archived ones disappear' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $teacher = makeUser(User::ROLE_TEACHER);

            $draft = AnnouncementService::save($registrar, null, announcementInput());
            $t->assertFalse(Announcement::isVisibleTo($draft, $teacher));
            $t->assertEquals([], notifiedAmong($draft, [$teacher]));
            AnnouncementService::dispatchDue(true);
            $t->assertEquals([], notifiedAmong($draft, [$teacher]), 'A draft is never delivered');

            $scheduled = AnnouncementService::save($registrar, null, announcementInput(['title' => 'Scheduled reminder for all', 'published_at' => date('Y-m-d\TH:i', strtotime('+3 days'))]), null, 'publish');
            $t->assertEquals('SCHEDULED', Announcement::state(Announcement::find($scheduled)));
            $t->assertFalse(Announcement::isVisibleTo($scheduled, $teacher));
            $t->assertEquals([], notifiedAmong($scheduled, [$teacher]));

            Database::update('announcements', ['published_at' => date('Y-m-d H:i:s', strtotime('-1 minute'))], ['id' => $scheduled]); // fixture: time passes
            $t->assertTrue(AnnouncementService::dispatchDue(true) >= 1);
            $t->assertEquals(idsOf($teacher), notifiedAmong($scheduled, [$teacher]), 'Delivered when due');
            AnnouncementService::dispatchDue(true);
            AnnouncementService::dispatch($scheduled);
            $t->assertEquals(1, (int) Database::scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND link_url = ?', [$teacher->id, '/portal/announcements/' . $scheduled]), 'Only once');

            // Editing after delivery changes what readers see but notifies nobody again.
            $slug = Announcement::find($scheduled)['slug'];
            AnnouncementService::save($registrar, $scheduled, announcementInput(['title' => 'Scheduled reminder, corrected']), null, 'save');
            $t->assertEquals('Scheduled reminder, corrected', Announcement::find($scheduled)['title']);
            $t->assertEquals($slug, Announcement::find($scheduled)['slug'], 'The public address stays');
            $t->assertEquals(1, (int) Database::scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND link_url = ?', [$teacher->id, '/portal/announcements/' . $scheduled]));

            Database::update('announcements', ['expires_at' => date('Y-m-d H:i:s', strtotime('-1 minute'))], ['id' => $scheduled]);
            $t->assertFalse(Announcement::isVisibleTo($scheduled, $teacher), 'Expired');
            $t->assertEquals('EXPIRED', Announcement::state(Announcement::find($scheduled)));
            Database::update('announcements', ['expires_at' => null], ['id' => $scheduled]);

            AnnouncementService::archive($registrar, $scheduled);
            $t->assertFalse(Announcement::isVisibleTo($scheduled, $teacher), 'Archived');
            $t->assertThrows(static fn () => AnnouncementService::save($registrar, $scheduled, announcementInput(), null, 'save'), 'archived');
            $t->assertThrows(static fn () => AnnouncementService::delete($registrar, $scheduled), 'never published');
            AnnouncementService::restore($registrar, $scheduled);
            $t->assertEquals('DRAFT', Announcement::find($scheduled)['status']);
            $t->assertThrows(static fn () => AnnouncementService::delete($registrar, $scheduled), 'never published');

            AnnouncementService::delete($registrar, $draft);
            $t->assertNull(Announcement::find($draft), 'A never-published draft can be deleted');
            $t->assertNotNull(Database::selectOne("SELECT id FROM audit_logs WHERE action = 'announcement.deleted' AND target_id = ?", [$draft]));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'urgent announcements make a banner that each reader can dismiss for themselves' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $studentA = makeUser(User::ROLE_STUDENT);
            $studentB = makeUser(User::ROLE_STUDENT);
            $teacher = makeUser(User::ROLE_TEACHER);

            $urgent = AnnouncementService::save($registrar, null, announcementInput(['title' => 'Classes suspended today', 'is_urgent' => '1', 'audience' => 'TARGETED', 'roles' => [roleId(User::ROLE_STUDENT)]]), null, 'publish');
            AnnouncementService::save($registrar, null, announcementInput(['title' => 'Not urgent at all here']), null, 'publish');
            $banner = static fn (User $u) => array_map('intval', array_column(Announcement::urgentFor($u, 10), 'id'));

            $t->assertTrue(in_array($urgent, $banner($studentA), true));
            $t->assertFalse(in_array($urgent, $banner($teacher), true), 'Not in the teacher audience');
            $t->assertEquals('Urgent: Classes suspended today', Database::scalar('SELECT title FROM notifications WHERE user_id = ? AND link_url = ?', [$studentA->id, '/portal/announcements/' . $urgent]));

            AnnouncementService::dismiss($studentA, $urgent);
            AnnouncementService::dismiss($studentA, $urgent); // twice is harmless
            $t->assertFalse(in_array($urgent, $banner($studentA), true), 'Dismissed for A');
            $t->assertTrue(in_array($urgent, $banner($studentB), true), 'Still shown to B');
            $t->assertTrue(Announcement::isVisibleTo($urgent, $studentA), 'Dismissing hides the banner, not the announcement');
            $t->assertEquals(404, statusOf(static fn () => AnnouncementService::dismiss($teacher, $urgent)), 'Outside the audience: it does not exist');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'an image is shown only to whoever may see the announcement; replacing it removes the old file' => function (TestRunner $t): void {
        try {
            $registrar = makeUser(User::ROLE_REGISTRAR);
            $student = makeUser(User::ROLE_STUDENT);
            $teacher = makeUser(User::ROLE_TEACHER);
            $png = static fn () => UploadedFile::forTesting(fixtureFile('png'), 'poster.png');

            $public = AnnouncementService::save($registrar, null, announcementInput(['is_public' => '1', 'image_alt' => 'Campus poster']), $png(), 'publish');
            $targeted = AnnouncementService::save($registrar, null, announcementInput(['audience' => 'TARGETED', 'roles' => [roleId(User::ROLE_STUDENT)], 'image_alt' => 'Exam schedule']), $png(), 'publish');
            $draft = AnnouncementService::save($registrar, null, announcementInput(['image_alt' => 'Draft art']), $png());

            $publicRow = Announcement::find($public);
            $t->assertTrue(is_file(STORAGE_PATH . '/' . $publicRow['image_path']), 'Stored under storage/');
            $t->assertEquals('image/png', $publicRow['image_mime']);
            $t->assertTrue(AnnouncementService::canViewImage(null, $publicRow), 'A visitor sees a public image');
            $t->assertFalse(AnnouncementService::canViewImage(null, Announcement::find($targeted)), 'A visitor does not see a targeted one');
            $t->assertTrue(AnnouncementService::canViewImage($student, Announcement::find($targeted)));
            $t->assertFalse(AnnouncementService::canViewImage($teacher, Announcement::find($targeted)), 'Outside the audience');
            $t->assertFalse(AnnouncementService::canViewImage($student, Announcement::find($draft)), 'A draft image is hidden from readers');
            $t->assertTrue(AnnouncementService::canViewImage($registrar, Announcement::find($draft)), 'Managers can preview');

            $old = STORAGE_PATH . '/' . $publicRow['image_path'];
            AnnouncementService::save($registrar, $public, announcementInput(['is_public' => '1', 'image_alt' => 'New poster']), $png(), 'save');
            $t->assertFalse(is_file($old), 'The replaced file is removed');
            $t->assertTrue(is_file(STORAGE_PATH . '/' . Announcement::find($public)['image_path']));

            $current = STORAGE_PATH . '/' . Announcement::find($public)['image_path'];
            AnnouncementService::save($registrar, $public, announcementInput(['is_public' => '1', 'remove_image' => '1']), null, 'save');
            $t->assertNull(Announcement::find($public)['image_path']);
            $t->assertFalse(is_file($current));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the inbox is private: open marks read and follows only safe in-app links; mark all, archive, and restore' => function (TestRunner $t): void {
        try {
            $owner = makeUser(User::ROLE_STUDENT);
            $other = makeUser(User::ROLE_STUDENT);
            NotificationService::notify($owner->id, 'enrollment.approved', 'Approved', null, '/student/enrollment/5');
            NotificationService::notify($owner->id, 'test.unsafe', 'Unsafe', null, '//evil.example/steal');
            NotificationService::notify($owner->id, 'test.none', 'No link');
            $ids = array_map('intval', array_column(Database::select('SELECT id FROM notifications WHERE user_id = ? ORDER BY id', [$owner->id]), 'id'));

            $t->assertEquals(3, Notification::unreadCount($owner->id));
            $t->assertEquals(404, statusOf(static fn () => NotificationService::open($other, $ids[0])), "Another user's notification does not exist");
            $t->assertEquals(404, statusOf(static fn () => NotificationService::archive($other, $ids[0])));
            $t->assertEquals(3, Notification::unreadCount($owner->id), 'Nothing changed');

            $t->assertEquals('/student/enrollment/5', NotificationService::open($owner, $ids[0]));
            $t->assertEquals('READ', Notification::findOwned($ids[0], $owner->id)['state']);
            $t->assertNotNull(Notification::findOwned($ids[0], $owner->id)['read_at']);
            $t->assertNull(NotificationService::open($owner, $ids[1]), 'An unsafe link is not followed');
            $t->assertNull(NotificationService::open($owner, $ids[2]));

            foreach (['https://evil.example', '//evil.example', '/\\evil.example', 'javascript:alert(1)', '/a/../../etc', "/x\nLocation: y", 'student/grades'] as $bad) {
                $t->assertNull(NotificationService::safeLink($bad), "Refused: {$bad}");
            }
            foreach (['/student/account?assessment=12', '/cashier/accounts/3', '/portal/announcements/9', '/student/enrollment/4#pay'] as $good) {
                $t->assertEquals($good, NotificationService::safeLink($good), "Allowed: {$good}");
            }

            NotificationService::notify($owner->id, 'test.more', 'More');
            NotificationService::notify($other->id, 'test.other', 'Other');
            $t->assertEquals(1, NotificationService::markAllRead($owner));
            $t->assertEquals(0, Notification::unreadCount($owner->id));
            $t->assertEquals(1, Notification::unreadCount($other->id), "Another user's unread stay unread");

            NotificationService::archive($owner, $ids[0]);
            $t->assertEquals(3, Notification::count($owner->id, 'all'));
            $t->assertEquals(1, Notification::count($owner->id, 'archived'));
            $t->assertEquals([], array_filter(Notification::latest($owner->id, 10), static fn ($n) => (int) $n['id'] === $ids[0]), 'Archived ones leave the bell');
            NotificationService::restore($owner, $ids[0]);
            $t->assertEquals(0, Notification::count($owner->id, 'archived'));
            $t->assertEquals(4, Notification::count($owner->id, 'all'));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'announcement management routes need the permission; reading routes need sign-in; no other role passes management' => function (TestRunner $t): void {
        try {
            $manage = communicationRoutes('/manage/');
            $portal = communicationRoutes('/portal/');
            $t->assertTrue(count($manage) >= 8, 'Found the management routes');
            $t->assertTrue(count($portal) >= 8, 'Found the portal routes');

            $registrar = makeUser(User::ROLE_REGISTRAR);
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            $others = [makeUser(User::ROLE_STUDENT), makeUser(User::ROLE_TEACHER), makeUser(User::ROLE_CASHIER)];

            foreach ($manage as $route) {
                $label = "{$route['method']} {$route['pattern']}";
                $t->assertTrue(in_array('auth', $route['middleware'], true), "{$label} requires sign-in");
                $t->assertTrue(array_filter($route['middleware'], static fn ($m) => str_starts_with($m, 'can:announcements.')) !== [], "{$label} names an announcement permission");
                $t->assertNull(communicationAccess($route, $registrar), "Registrar may reach {$label}");
                $t->assertNull(communicationAccess($route, $admin), "Super Admin may reach {$label}");
                foreach ($others as $user) {
                    $t->assertEquals(403, communicationAccess($route, $user), "{$user->role_name} must be refused {$label}");
                }
                $t->assertEquals(401, communicationAccess($route, null), "A visitor is refused {$label}");
            }

            foreach ($portal as $route) {
                $label = "{$route['method']} {$route['pattern']}";
                foreach ([...$others, $registrar, $admin] as $user) {
                    $t->assertNull(communicationAccess($route, $user), "{$user->role_name} may reach {$label}");
                }
                $t->assertEquals(401, communicationAccess($route, null), "A visitor is refused {$label}");
            }
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },
];
