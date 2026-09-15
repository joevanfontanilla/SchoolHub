<?php
/**
 * Build an installation SQL file for hosting where commands cannot be run on the server (docs/23).
 *
 *   php database/build_install_sql.php --username=admin --email=registrar@lyceum.edu.ph --first=Maria --last=Santos
 *
 * Runs on a development computer. It creates a throwaway database, applies every migration, seeds the
 * reference data, imports the curricula, and adds the first Super Admin with a random temporary
 * password (printed once; it must be changed at the first sign-in). It then writes
 * build/schoolhub-install.sql, which phpMyAdmin or the hosting panel imports into the empty
 * database, and drops the throwaway database.
 *
 * The schema comes from the migration files themselves, not from this computer's MariaDB, so the
 * file imports into the provider's MySQL 8 or MariaDB alike. Delete the file after importing it:
 * it holds the administrator's password hash.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('This script may only be run from the command line.');
}

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/lib/migrations.php';

use App\Core\Config;
use App\Core\Database;
use App\Models\User;

$options = getopt('', ['username:', 'email:', 'first:', 'last:', 'out::']);
$username = trim((string) ($options['username'] ?? ''));
$email = trim((string) ($options['email'] ?? ''));
$first = trim((string) ($options['first'] ?? ''));
$last = trim((string) ($options['last'] ?? ''));
$out = (string) ($options['out'] ?? BASE_PATH . '/build/schoolhub-install.sql');

if ($username === '' || $first === '' || $last === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || !preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
    fwrite(STDERR, "  Usage: php database/build_install_sql.php --username=admin --email=you@school.edu.ph --first=First --last=Last\n");
    exit(1);
}
if (Config::get('app.env') === 'production') {
    fwrite(STDERR, "  Run this on a development computer, not on the production server.\n");
    exit(1);
}

$temp = 'schoolhub_install_' . bin2hex(random_bytes(4));
$env = getenv() + ['SCHOOLHUB_DATABASE' => $temp];
$env['SCHOOLHUB_DATABASE'] = $temp;

/** Run one of the database scripts against the throwaway database. */
$run = static function (array $script) use ($env): void {
    $process = proc_open(array_merge([PHP_BINARY], $script), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BASE_PATH, $env);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException(implode(' ', $script) . " failed:\n" . $output);
    }
    echo '  ' . basename($script[0]) . " done\n";
};

try {
    echo "\n  Building the installation database ({$temp})\n";
    $run(['database/migrate.php']);
    $run(['database/seed.php']);
    $run(['database/import_curricula.php']);

    $pdo = App\Services\BackupService::connect($temp);
    $roleId = (int) $pdo->query("SELECT id FROM roles WHERE name = " . $pdo->quote(User::ROLE_SUPER_ADMIN))->fetchColumn();
    // A temporary password: letters and digits in groups, easy to type once.
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $groups = [];
    for ($g = 0; $g < 4; $g++) {
        $group = '';
        for ($i = 0; $i < 4; $i++) {
            $group .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $groups[] = $group;
    }
    $password = implode('-', $groups) . random_int(2, 9);
    $insert = $pdo->prepare('INSERT INTO users (role_id, username, email, password_hash, first_name, last_name, status, must_change_password) VALUES (?, ?, ?, ?, ?, ?, ?, 1)');
    $insert->execute([$roleId, $username, $email, password_hash($password, PASSWORD_DEFAULT), $first, $last, User::STATUS_ACTIVE]);

    if (!is_dir(dirname($out))) {
        mkdir(dirname($out), 0750, true);
    }
    $handle = fopen($out, 'wb');
    fwrite($handle, "-- SchoolHub " . Config::get('app.version') . " installation, built " . date('c') . "\n");
    fwrite($handle, "-- Import into an EMPTY database (utf8mb4). Delete this file afterwards: it holds the administrator's password hash.\n");
    fwrite($handle, "SET NAMES utf8mb4;\nSET time_zone = '" . date('P') . "';\nSET FOREIGN_KEY_CHECKS = 0;\n\n");

    fwrite($handle, preg_replace('/\s*\R\s*/', ' ', migrationsTableSql()) . ";\n");
    foreach (migrationFiles() as $file) {
        fwrite($handle, "\n-- {$file}\n");
        foreach (parseMigration($file)['up'] as $statement) {
            fwrite($handle, trim($statement) . ";\n");
        }
    }

    fwrite($handle, "\n-- Data\n");
    $rows = 0;
    foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as [$table]) {
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        $batch = [];
        $columns = null;
        foreach ($pdo->query("SELECT * FROM {$quoted}")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns ??= implode(', ', array_map(static fn ($c) => '`' . str_replace('`', '``', $c) . '`', array_keys($row)));
            $batch[] = '(' . implode(', ', array_map(static fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)) . ')';
            $rows++;
            if (count($batch) === 200) {
                fwrite($handle, "INSERT INTO {$quoted} ({$columns}) VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        if ($batch !== []) {
            fwrite($handle, "INSERT INTO {$quoted} ({$columns}) VALUES\n" . implode(",\n", $batch) . ";\n");
        }
    }
    fwrite($handle, "\nSET FOREIGN_KEY_CHECKS = 1;\n");
    fclose($handle);

    printf("\n  Wrote %s (%s KB, %d rows)\n", $out, number_format(filesize($out) / 1024), $rows);
    echo "\n  First Super Admin\n";
    echo "    Username:            {$username}\n";
    echo "    Temporary password:  {$password}\n";
    echo "  Shown only now. It must be changed at the first sign-in.\n\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\n  ERROR: " . $e->getMessage() . "\n\n");
    $failed = true;
} finally {
    try {
        Database::serverConnection()->exec('DROP DATABASE IF EXISTS `' . $temp . '`');
    } catch (Throwable) {
        fwrite(STDERR, "  Could not drop the throwaway database {$temp}; drop it by hand.\n");
    }
}
exit(isset($failed) ? 1 : 0);
