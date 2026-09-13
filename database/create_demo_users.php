<?php
/**
 * Create one demo account per role for local testing.
 *
 *   php database/create_demo_users.php
 *
 * REFUSED when app.env is production. These accounts exist so the role matrix
 * in docs/22 can actually be exercised; they are not a production seeder and
 * must never be run on a live installation (docs/23).
 *
 * The password is prompted for and never stored in this file.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('This script may only be run from the command line.');
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Models\User;
use App\Services\AuthService;

const DEMO_USERS = [
    ['student',   'student@schoolhub.test',   'Maria',  'Santos',   User::ROLE_STUDENT],
    ['teacher',   'teacher@schoolhub.test',   'Jose',   'Rivera',   User::ROLE_TEACHER],
    ['registrar', 'registrar@schoolhub.test', 'Ana',    'Delgado',  User::ROLE_REGISTRAR],
    ['cashier',   'cashier@schoolhub.test',   'Ramon',  'Villamor', User::ROLE_CASHIER],
];

try {
    if (Config::get('app.env') === 'production') {
        throw new RuntimeException('Refusing to create demo accounts in production.');
    }

    $roleIds = array_column(Database::select('SELECT id, name FROM roles'), 'id', 'name');
    if ($roleIds === []) {
        throw new RuntimeException('Roles are not seeded yet. Run: php database/seed.php');
    }

    echo "\n  Demo accounts (local testing only)\n";
    echo "  ----------------------------------\n";
    echo "  Set one password to be shared by all demo accounts.\n\n";

    // --password=... is accepted so the suite can be provisioned without a
    // prompt. Local only: this script already refuses to run in production.
    $password = null;
    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--password=')) {
            $password = substr($argument, 11);
        }
    }
    $password ??= promptSecret('Password');

    $problem = AuthService::passwordPolicyProblem($password);
    if ($problem !== null) {
        throw new RuntimeException($problem);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $password = null;

    foreach (DEMO_USERS as [$username, $email, $first, $last, $roleName]) {
        Database::query(
            'INSERT INTO users (role_id, username, email, password_hash, first_name, last_name, status, must_change_password)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE
                password_hash = VALUES(password_hash),
                status = VALUES(status),
                failed_login_attempts = 0,
                locked_until = NULL',
            [$roleIds[$roleName], $username, $email, $hash, $first, $last, User::STATUS_ACTIVE]
        );
        echo "  {$roleName} ... {$username}\n";
    }

    echo "\n  Done. These accounts are for local testing only.\n\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\n  ERROR: " . $e->getMessage() . "\n\n");
    exit(1);
}

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
    }

    echo '  ' . $label . ': ';
    return rtrim((string) fgets(STDIN), "\r\n");
}
