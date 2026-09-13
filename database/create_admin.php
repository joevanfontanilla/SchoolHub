<?php
/**
 * Create the first Super Admin, interactively.
 *
 *   php database/create_admin.php
 *
 * The password is prompted for, never hardcoded and never committed — docs/23
 * step 7 ("create initial Super Admin securely") and the DEPLOYMENT security
 * rule about development passwords.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('This script may only be run from the command line.');
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Database;
use App\Models\User;
use App\Services\AuthService;

try {
    $roleId = Database::scalar('SELECT id FROM roles WHERE name = ?', [User::ROLE_SUPER_ADMIN]);
    if ($roleId === null) {
        throw new RuntimeException('Roles are not seeded yet. Run: php database/seed.php');
    }

    echo "\n  Create a Super Admin account\n";
    echo "  ----------------------------\n";

    $firstName = prompt('First name');
    $lastName  = prompt('Last name');
    $username  = prompt('Username');
    $email     = prompt('Email');

    if ($firstName === '' || $lastName === '' || $username === '' || $email === '') {
        throw new RuntimeException('All fields are required.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('That is not a valid email address.');
    }

    $existing = Database::selectOne(
        'SELECT id FROM users WHERE username = ? OR email = ?',
        [$username, $email]
    );
    if ($existing !== null) {
        throw new RuntimeException('A user with that username or email already exists.');
    }

    $password = promptSecret('Password');
    $confirm  = promptSecret('Confirm password');

    if ($password !== $confirm) {
        throw new RuntimeException('The passwords do not match.');
    }

    $problem = AuthService::passwordPolicyProblem($password);
    if ($problem !== null) {
        throw new RuntimeException($problem);
    }

    $id = Database::insert('users', [
        'role_id'              => (int) $roleId,
        'username'             => $username,
        'email'                => $email,
        'password_hash'        => password_hash($password, PASSWORD_DEFAULT),
        'first_name'           => $firstName,
        'last_name'            => $lastName,
        'status'               => User::STATUS_ACTIVE,
        'must_change_password' => 0,
    ]);

    // Clear the plaintext from memory as soon as it is no longer needed.
    $password = $confirm = null;

    Database::insert('audit_logs', [
        'user_id'    => $id,
        'actor_role' => 'System',
        'action'     => 'user.created',
        'module'     => 'administration',
        'target_type' => 'User',
        'target_id'  => $id,
        'new_values' => json_encode(['username' => $username, 'role' => User::ROLE_SUPER_ADMIN]),
        'ip_address' => 'cli',
        'result'     => 'SUCCESS',
        'remarks'    => 'Initial Super Admin created from the command line',
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    echo "\n  Super Admin created: {$username} <{$email}>\n";
    echo "  Sign in at the /login page.\n\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\n  ERROR: " . $e->getMessage() . "\n\n");
    exit(1);
}

// ---------------------------------------------------------------------------

function prompt(string $label): string
{
    echo '  ' . $label . ': ';
    return trim((string) fgets(STDIN));
}

/**
 * Read a password without echoing it.
 *
 * Windows has no stty, so PowerShell's Read-Host -AsSecureString is used there.
 * If neither is available the input is visible and the user is warned.
 */
function promptSecret(string $label): string
{
    if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
        $command = 'powershell -NoProfile -Command "$p = Read-Host -AsSecureString \'' . $label . '\';'
            . ' [Runtime.InteropServices.Marshal]::PtrToStringAuto('
            . '[Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))"';

        $value = shell_exec($command);
        if (is_string($value)) {
            return rtrim($value, "\r\n");
        }
    } elseif (function_exists('shell_exec') && stripos((string) shell_exec('which stty'), 'stty') !== false) {
        echo '  ' . $label . ': ';
        shell_exec('stty -echo');
        $value = rtrim((string) fgets(STDIN), "\r\n");
        shell_exec('stty echo');
        echo "\n";
        return $value;
    }

    echo "  (warning: this terminal cannot hide input — the password will be visible)\n";
    return prompt($label);
}
