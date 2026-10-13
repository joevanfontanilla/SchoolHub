<?php
/**
 * A user changing their own name on My Account (docs/08).
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Core\Database;
use App\Core\Router;
use App\Models\User;
use App\Services\AccountNameService;

function nameInput(array $overrides = []): array
{
    return array_merge(['first_name' => 'Maria', 'middle_name' => 'Santos', 'last_name' => 'Reyes', 'suffix' => ''], $overrides);
}

function nameRow(int $userId): array
{
    return Database::selectOne('SELECT first_name, middle_name, last_name, suffix, email, username FROM users WHERE id = ?', [$userId]);
}

return [
    'every role can change their own name, and it is audited with the old and the new name' => function (TestRunner $t): void {
        try {
            foreach ([User::ROLE_TEACHER, User::ROLE_REGISTRAR, User::ROLE_CASHIER, User::ROLE_SUPER_ADMIN] as $role) {
                $user = makeUser($role);
                $before = nameRow($user->id);

                $t->assertTrue(AccountNameService::update($user, nameInput(['suffix' => 'Jr.'])), "{$role} can change their name");

                $after = nameRow($user->id);
                $t->assertEquals(['Maria', 'Santos', 'Reyes', 'Jr.'], [$after['first_name'], $after['middle_name'], $after['last_name'], $after['suffix']]);
                $audit = Database::selectOne("SELECT * FROM audit_logs WHERE action = 'account.name_changed' AND target_id = ? ORDER BY id DESC LIMIT 1", [$user->id]);
                $t->assertNotNull($audit, "{$role}: the change is audited");
                $t->assertEquals($user->id, (int) $audit['user_id']);
                $t->assertEquals($before['first_name'], json_decode((string) $audit['old_values'], true)['first_name'], 'The old name is recorded');
                $t->assertEquals('Maria', json_decode((string) $audit['new_values'], true)['first_name'], 'And the new one');
            }

            // A student with no application yet can too.
            $student = makeUser(User::ROLE_STUDENT);
            $t->assertTrue(AccountNameService::update($student, nameInput()));
            $t->assertEquals('Reyes', nameRow($student->id)['last_name']);
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'extra spaces are collapsed, blank optional fields become empty, and the email and username are never touched' => function (TestRunner $t): void {
        try {
            $user = makeUser(User::ROLE_CASHIER);
            $before = nameRow($user->id);

            AccountNameService::update($user, nameInput([
                'first_name' => '  Ana   Maria ', 'middle_name' => '   ', 'last_name' => ' dela   Cruz', 'suffix' => ' ',
                'email' => 'attacker@example.test', 'username' => 'attacker', 'role' => User::ROLE_SUPER_ADMIN,
            ]));

            $after = nameRow($user->id);
            $t->assertEquals('Ana Maria', $after['first_name'], 'Spaces collapsed');
            $t->assertEquals('dela Cruz', $after['last_name']);
            $t->assertNull($after['middle_name'], 'A blank middle name is stored as empty');
            $t->assertNull($after['suffix']);
            $t->assertEquals($before['email'], $after['email'], 'The email is not editable here');
            $t->assertEquals($before['username'], $after['username'], 'Nor the username');
            $t->assertEquals(User::ROLE_CASHIER, (string) Database::scalar('SELECT r.name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$user->id]), 'Nor the role');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a first and last name are required, and nothing is saved when they are missing or too long' => function (TestRunner $t): void {
        try {
            $user = makeUser(User::ROLE_TEACHER);
            $before = nameRow($user->id);

            $errors = fieldErrors(fn () => AccountNameService::update($user, nameInput(['first_name' => '', 'last_name' => ''])));
            $t->assertTrue(isset($errors['first_name']) && isset($errors['last_name']), 'Both required names are named');
            $t->assertTrue(isset(fieldErrors(fn () => AccountNameService::update($user, nameInput(['first_name' => str_repeat('a', 101)])))['first_name']), 'Too long');
            $t->assertTrue(isset(fieldErrors(fn () => AccountNameService::update($user, nameInput(['suffix' => str_repeat('J', 21)])))['suffix']), 'A suffix is short');

            $t->assertEquals($before, nameRow($user->id), 'Nothing was saved');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'saving an unchanged name writes nothing and is not audited' => function (TestRunner $t): void {
        try {
            $user = makeUser(User::ROLE_REGISTRAR);
            AccountNameService::update($user, nameInput());
            $count = (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'account.name_changed' AND target_id = ?", [$user->id]);

            $t->assertFalse(AccountNameService::update($user, nameInput()), 'Unchanged');
            $t->assertEquals($count, (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'account.name_changed' AND target_id = ?", [$user->id]), 'No new audit entry');
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'a student\'s name is locked once an application is with the registrar, the same as their Profile page' => function (TestRunner $t): void {
        try {
            $draft = makeStudent();
            App\Services\EnrollmentApplicationService::createDraft($draft, applicationInput());
            $t->assertNull(AccountNameService::lockedReason($draft), 'A draft does not lock the name');
            $t->assertTrue(AccountNameService::update($draft, nameInput(['first_name' => 'Lia'])), 'So it can still change');

            [$submitted] = submittedApplication();
            $t->assertNotNull(AccountNameService::lockedReason($submitted), 'A submitted application locks it');
            $before = nameRow($submitted->id);
            $t->assertThrows(fn () => AccountNameService::update($submitted, nameInput(['first_name' => 'Changed'])), 'locked', 'The change is refused');
            $t->assertEquals($before, nameRow($submitted->id), 'The name stayed');

            // Staff are never locked.
            $t->assertNull(AccountNameService::lockedReason(makeUser(User::ROLE_TEACHER)));
        } finally {
            actingAs(null);
            deleteTestUsers();
        }
    },

    'the name route needs a signed-in user' => function (TestRunner $t): void {
        $router = new Router();
        require BASE_PATH . '/routes/web.php';
        $found = array_filter($router->routes(), static fn ($r) => $r['pattern'] === '/account/name' && $r['method'] === 'POST');
        $t->assertEquals(1, count($found), 'The route exists');
        $t->assertTrue(in_array('auth', current($found)['middleware'], true), 'It requires sign-in');
    },
];
