<?php
/**
 * Test helpers.
 *
 * Tests run against the configured database. They create their own fixtures
 * with a distinctive prefix and remove them afterwards.
 */

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Models\User;

const TEST_PREFIX = '__test_';

/**
 * Create a user for a test and return it.
 * Always pair with deleteTestUsers() in a finally block.
 */
function makeUser(
    string $roleName,
    string $password = 'TestPass123',
    string $status = User::STATUS_ACTIVE,
    bool $mustChange = false
): User {
    $roleId = (int) Database::scalar('SELECT id FROM roles WHERE name = ?', [$roleName]);
    if ($roleId === 0) {
        throw new RuntimeException("Role '{$roleName}' is not seeded. Run: php database/seed.php");
    }

    $suffix = bin2hex(random_bytes(4));
    $username = TEST_PREFIX . $suffix;

    $id = Database::insert('users', [
        'role_id'              => $roleId,
        'username'             => $username,
        'email'                => $username . '@test.local',
        'password_hash'        => password_hash($password, PASSWORD_DEFAULT),
        'first_name'           => 'Test',
        'last_name'            => ucfirst(strtolower(str_replace(' ', '', $roleName))),
        'status'               => $status,
        'must_change_password' => $mustChange ? 1 : 0,
    ]);

    $user = User::find($id);
    if ($user === null) {
        throw new RuntimeException('Failed to create the test user.');
    }

    return $user;
}

/** Remove every fixture this suite created. */
function deleteTestUsers(): void
{
    $ids = array_column(
        Database::select('SELECT id FROM users WHERE username LIKE ?', [TEST_PREFIX . '%']),
        'id'
    );

    if ($ids === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    Database::query("DELETE FROM login_logs WHERE user_id IN ({$placeholders})", $ids);
    Database::query("DELETE FROM audit_logs WHERE user_id IN ({$placeholders})", $ids);
    Database::query("DELETE FROM users WHERE id IN ({$placeholders})", $ids);
}

/** Also clear login_logs rows keyed only by the attempted username. */
function deleteTestLoginLogs(): void
{
    Database::query('DELETE FROM login_logs WHERE username_attempted LIKE ?', [TEST_PREFIX . '%']);
}

/** Sign a user in for the duration of a test, without a real HTTP session. */
function actingAs(?User $user): void
{
    $_SESSION = $_SESSION ?? [];
    Auth::forgetCache();

    if ($user === null) {
        unset($_SESSION['_auth_user_id']);
        return;
    }

    $_SESSION['_auth_user_id'] = $user->id;
}

/** A fresh Request for middleware tests. */
function makeRequest(string $method = 'GET', string $path = '/', array $post = []): App\Core\Request
{
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = $path;
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'test-runner';
    $_POST = $post;
    $_GET = [];

    return new App\Core\Request();
}

/** The HTTP status an HttpException carries, or null if none was thrown. */
function statusOf(callable $callback): ?int
{
    try {
        $callback();
    } catch (App\Core\HttpException $e) {
        return $e->status();
    }
    return null;
}
