<?php
/**
 * The public admission requirements list, editable in System Settings (docs/06), and the upload checklist that follows the school's printed list (docs/10).
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Models\RequirementType;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\PublicRequirementsService;

/** Run a test body, then put the published list back as it was. */
function withRequirementsRestored(callable $body): void
{
    $original = (string) Database::scalar('SELECT `value` FROM system_settings WHERE `key` = ?', [PublicRequirementsService::KEY]);
    try {
        $body();
    } finally {
        SystemSetting::set(PublicRequirementsService::KEY, $original);
        Database::query("DELETE FROM audit_logs WHERE action = 'requirements.published' AND user_id IN (SELECT id FROM users WHERE username LIKE ?)", [testPrefixPattern()]);
        deleteTestUsers();
    }
}

/** @return array<int,string> the document codes asked of a student type */
function checklistCodes(string $type, ?string $civil = null): array
{
    return array_column(RequirementType::forApplication(currentTermId(), $type, $civil), 'code');
}

return [
    'the public list starts with the school\'s printed requirements, grouped as on the flyer' => function (TestRunner $t): void {
        $titles = array_column(PublicRequirementsService::groups(), 'title');
        $t->assertTrue(in_array('If Married', $titles, true));
        $t->assertTrue(in_array('For College: Transferees', $titles, true));
        $groups = array_column(PublicRequirementsService::groups(), 'items', 'title');
        $t->assertTrue(in_array('Marriage Contract', $groups['If Married'], true));
        $t->assertEquals(7, count($groups['For Senior High School (Grade 11 & 12)']), 'Seven Senior High items');
    },

    'the Super Admin edits the groups: order kept, blank groups dropped, lines trimmed, and the save is audited' => function (TestRunner $t): void {
        withRequirementsRestored(function () use ($t): void {
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            $count = PublicRequirementsService::save($admin, ['groups' => [
                ['title' => '  For   Visitors ', 'items' => "  First thing \n\n Second   thing\n"],
                ['title' => '', 'items' => ''],
                ['title' => 'Second group', 'items' => 'Only one'],
            ]]);
            $t->assertEquals(2, $count);
            $groups = PublicRequirementsService::groups();
            $t->assertEquals('For Visitors', $groups[0]['title']);
            $t->assertEquals(['First thing', 'Second thing'], $groups[0]['items']);
            $t->assertEquals('Second group', $groups[1]['title']);
            $t->assertEquals(1, (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'requirements.published' AND user_id = ?", [$admin->id]));

            $t->assertEquals(0, PublicRequirementsService::save($admin, ['groups' => []]), 'Everything can be cleared');
            $t->assertEquals([], PublicRequirementsService::groups());
        });
    },

    'a group needs a heading, and the limits are enforced, with nothing saved on a mistake' => function (TestRunner $t): void {
        withRequirementsRestored(function () use ($t): void {
            $admin = makeUser(User::ROLE_SUPER_ADMIN);
            $before = PublicRequirementsService::groups();
            $errors = fieldErrors(static fn () => PublicRequirementsService::save($admin, ['groups' => [['title' => '', 'items' => 'A line without a heading']]]));
            $t->assertTrue(isset($errors['groups.0.title']), 'A heading is required');
            $errors = fieldErrors(static fn () => PublicRequirementsService::save($admin, ['groups' => [['title' => str_repeat('x', 101), 'items' => 'a']]]));
            $t->assertTrue(isset($errors['groups.0.title']), 'Heading length');
            $errors = fieldErrors(static fn () => PublicRequirementsService::save($admin, ['groups' => [['title' => 'T', 'items' => str_repeat('y', 201)]]]));
            $t->assertTrue(isset($errors['groups.0.items']), 'Line length');
            $errors = fieldErrors(static fn () => PublicRequirementsService::save($admin, ['groups' => [['title' => 'T', 'items' => implode("\n", array_fill(0, 41, 'z'))]]]));
            $t->assertTrue(isset($errors['groups.0.items']), 'At most 40 lines');
            $many = array_map(static fn ($i) => ['title' => "G{$i}", 'items' => 'a'], range(1, 21));
            $t->assertTrue(isset(fieldErrors(static fn () => PublicRequirementsService::save($admin, ['groups' => $many]))['groups']), 'At most 20 groups');
            $t->assertEquals($before, PublicRequirementsService::groups(), 'Nothing was saved');
        });
    },

    'only the Super Admin can edit it, and the pages are in place' => function (TestRunner $t): void {
        withRequirementsRestored(function () use ($t): void {
            foreach ([User::ROLE_REGISTRAR, User::ROLE_CASHIER, User::ROLE_TEACHER, User::ROLE_STUDENT] as $role) {
                $t->assertEquals(403, statusOf(static fn () => PublicRequirementsService::save(makeUser($role), ['groups' => []])), "{$role} cannot");
            }
            $routes = (string) file_get_contents(BASE_PATH . '/routes/web.php');
            $t->assertContains("'/admin/settings/requirements'", $routes);
            $t->assertContains("settings.manage", $routes);
            $t->assertContains('Admission requirements', (string) file_get_contents(BASE_PATH . '/app/Views/admin/settings/index.php'));
            $t->assertContains('requirementGroups', (string) file_get_contents(BASE_PATH . '/app/Views/public/requirements.php'));
            $t->assertContains('Requirements for admission (editable', (string) file_get_contents(BASE_PATH . '/docs/06_PUBLIC_WEBSITE.md'));
        });
    },

    'a new student is asked for Form 138, birth certificate, good moral and the medical clearance, and nothing else' => function (TestRunner $t): void {
        $codes = checklistCodes('New');
        sort($codes);
        $t->assertEquals(['birth_certificate', 'good_moral', 'medical_certificate', 'report_card'], $codes);
    },

    'a transferee is asked for honorable dismissal, transcript, birth certificate, good moral and the medical clearance' => function (TestRunner $t): void {
        $codes = checklistCodes('Transferee');
        sort($codes);
        $t->assertEquals(['birth_certificate', 'good_moral', 'medical_certificate', 'transcript', 'transfer_credential'], $codes);
    },

    'a married applicant also brings the marriage certificate; old and summer students are asked for nothing' => function (TestRunner $t): void {
        $t->assertTrue(in_array('marriage_certificate', checklistCodes('New', 'Married'), true));
        $t->assertFalse(in_array('marriage_certificate', checklistCodes('New', 'Single'), true));
        $t->assertEquals([], checklistCodes('Old'));
        $t->assertEquals([], checklistCodes('Summer'));
    },

    'the medical clearance is optional to submit and says it is due within 30 days; the old photo and ID stay as inactive rows' => function (TestRunner $t): void {
        $medical = Database::selectOne("SELECT * FROM requirement_types WHERE code = 'medical_certificate'");
        $t->assertEquals(0, (int) $medical['is_required'], 'Not needed to submit the application');
        $t->assertContains('30 days', (string) $medical['description']);
        $t->assertContains('Medical Clearance', (string) $medical['name']);
        foreach (['id_photo', 'valid_id'] as $code) {
            $row = Database::selectOne('SELECT is_active FROM requirement_types WHERE code = ?', [$code]);
            $t->assertTrue($row === null || (int) $row['is_active'] === 0, "{$code} is no longer asked");
        }
    },
];
