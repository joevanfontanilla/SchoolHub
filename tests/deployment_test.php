<?php
/**
 * The deployment preflight (docs/23): it must catch each production misconfiguration,
 * and the helpers it depends on must behave on a real school server address.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

use App\Models\User;
use App\Services\DeploymentCheck;

/** @return array<string,string> label => status for a set of checks */
function checkStatuses(array $checks): array
{
    return array_column($checks, 'status', 'label');
}

const PRODUCTION_CONFIG = [
    'app.env'              => 'production',
    'app.debug'            => false,
    'app.base_url'         => 'https://enroll.lyceum.edu.ph/',
    'session.secure'       => true,
    'session.idle_timeout' => 1800,
    'db.username'          => 'schoolhub_app',
    'db.password'          => 'a-long-random-database-password',
    'app.trusted_proxies'  => [],
    'mail.driver'          => 'smtp',
    'mail.host'            => 'smtp.hostinger.com',
    'mail.port'            => 587,
    'mail.encryption'      => 'tls',
    'mail.verify_peer'     => true,
    'mail.username'        => 'no-reply@lyceum.edu.ph',
    'mail.password'        => 'mailbox-password',
    'mail.from_address'    => 'no-reply@lyceum.edu.ph',
];

return [
    'a correct production configuration passes every configuration check' => function (TestRunner $t): void {
        $statuses = checkStatuses(DeploymentCheck::configChecks(PRODUCTION_CONFIG));
        unset($statuses['config/config.local.php']); // depends on this machine
        $t->assertEquals([], array_keys(array_filter($statuses, static fn ($s) => $s !== 'OK')), 'Every check is OK');
    },

    'each development default is reported as a failure' => function (TestRunner $t): void {
        $cases = [
            'Environment'                 => ['app.env' => 'local'],
            'Debug mode'                  => ['app.debug' => true],
            'Site address (app.base_url)' => ['app.base_url' => 'http://enroll.lyceum.edu.ph/'],
            'Secure session cookie'       => ['session.secure' => false],
            'Database account'            => ['db.username' => 'root'],
            'Database password'           => ['db.password' => ''],
        ];
        foreach ($cases as $label => $change) {
            $t->assertEquals('FAIL', checkStatuses(DeploymentCheck::configChecks(array_merge(PRODUCTION_CONFIG, $change)))[$label], "{$label} fails");
        }
        $t->assertEquals('FAIL', checkStatuses(DeploymentCheck::configChecks(array_merge(PRODUCTION_CONFIG, ['app.base_url' => null])))['Site address (app.base_url)'], 'An unset address fails');
        $t->assertEquals('WARN', checkStatuses(DeploymentCheck::configChecks(array_merge(PRODUCTION_CONFIG, ['db.password' => 'short'])))['Database password'], 'A short password warns');
        $t->assertEquals('WARN', checkStatuses(DeploymentCheck::configChecks(array_merge(PRODUCTION_CONFIG, ['session.idle_timeout' => 14400])))['Idle sign-out'], 'A four-hour idle time warns');
    },

    'email and proxy settings that would fail online are reported' => function (TestRunner $t): void {
        $status = static fn (array $change, string $label): ?string => checkStatuses(DeploymentCheck::configChecks(array_merge(PRODUCTION_CONFIG, $change)))[$label] ?? null;
        $t->assertEquals('FAIL', $status(['mail.driver' => 'log'], 'Mail driver'), 'The development log driver fails');
        $t->assertEquals('WARN', $status(['mail.driver' => 'none'], 'Mail driver'), 'Email off only warns');
        $t->assertEquals('WARN', $status(['mail.driver' => null], 'Mail driver'), 'Unset in production means off');
        $t->assertEquals('FAIL', $status(['mail.host' => ''], 'Mail server'), 'No mail server fails');
        $t->assertEquals('FAIL', $status(['mail.encryption' => 'none'], 'Mail encryption'), 'An unencrypted remote server fails');
        $t->assertEquals('OK', $status(['mail.encryption' => 'none', 'mail.host' => 'localhost'], 'Mail encryption'), 'A relay on the same server is fine');
        $t->assertEquals('WARN', $status(['mail.verify_peer' => false], 'Certificate check'), 'Not checking the certificate warns');
        $t->assertEquals('FAIL', $status(['mail.from_address' => 'no-reply@schoolhub.test'], 'Sender address'), 'The sample sender fails');
        $t->assertEquals('FAIL', $status(['app.trusted_proxies' => ['0.0.0.0/0']], 'Trusted proxies'), 'Trusting every address fails');
        $t->assertEquals('OK', $status(['app.trusted_proxies' => ['173.245.48.0/20']], 'Trusted proxies'), 'A provider range is fine');
    },

    'demo accounts and leftover test accounts are found, and disabled ones are not' => function (TestRunner $t): void {
        try {
            $active = makeUser(User::ROLE_REGISTRAR);
            $disabled = makeUser(User::ROLE_REGISTRAR, 'TestPass123', User::STATUS_DISABLED);
            $found = DeploymentCheck::sampleAccounts();
            $t->assertTrue(in_array($active->username, $found, true), 'An active fixture account is reported');
            $t->assertFalse(in_array($disabled->username, $found, true), 'A disabled account is not');
            $t->assertEquals('FAIL', checkStatuses(DeploymentCheck::dataChecks())['Demo and test accounts']);
        } finally {
            deleteTestUsers();
        }
    },

    'private files are probed under the site address and every folder above it' => function (TestRunner $t): void {
        $urls = DeploymentCheck::probeUrls('https://192.168.1.10:8443/SchoolHub/public');
        $t->assertTrue(in_array('https://192.168.1.10:8443/SchoolHub/public/.git/HEAD', $urls, true), 'Under public/');
        $t->assertTrue(in_array('https://192.168.1.10:8443/SchoolHub/config/config.local.php', $urls, true), 'The project root under htdocs');
        $t->assertTrue(in_array('https://192.168.1.10:8443/storage/backups/', $urls, true), 'The domain root');
        $t->assertEquals(count(DeploymentCheck::PRIVATE_FILES) * 3, count($urls), 'Three folder levels');
        $t->assertEquals(count(DeploymentCheck::PRIVATE_FILES), count(DeploymentCheck::probeUrls('https://enroll.lyceum.edu.ph/')), 'A site at the domain root has one level');
    },

    'an application account limited to its database passes, and server-wide privileges fail' => function (TestRunner $t): void {
        $limited = "GRANT USAGE ON *.* TO `schoolhub_app`@`localhost` IDENTIFIED BY PASSWORD '*ABC'\nGRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES ON `schoolhub`.* TO `schoolhub_app`@`localhost`";
        $t->assertFalse(DeploymentCheck::hasServerPrivileges($limited), 'USAGE on *.* plus database grants is limited');
        $t->assertTrue(DeploymentCheck::hasServerPrivileges("GRANT ALL PRIVILEGES ON *.* TO `root`@`localhost` WITH GRANT OPTION"), 'root');
        $t->assertTrue(DeploymentCheck::hasServerPrivileges("GRANT SELECT, FILE ON *.* TO `app`@`localhost`"), 'FILE reads server files');
        $t->assertTrue(DeploymentCheck::hasServerPrivileges("GRANT ALL PRIVILEGES ON `schoolhub`.* TO `app`@`localhost` WITH GRANT OPTION"), 'May grant to others');
    },

    'the live-site check flags exposed Apache endpoints (against the running server)' => function (TestRunner $t): void {
        $base = getenv('SCHOOLHUB_BASE_URL') ?: 'http://localhost/SchoolHub/public/';
        $probe = @file_get_contents($base . 'login', false, stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]));
        if ($probe === false) {
            // tests/run.php can run without Apache; the crawl covers the live server separately.
            $t->assertTrue(true, 'The web server is not reachable at ' . $base . '; skipped');
            return;
        }
        $checks = checkStatuses(DeploymentCheck::httpChecks($base));
        // On the development XAMPP these are open, so the checks must FAIL/WARN; on a hardened
        // server they turn OK. Either way each check must exist and return a known status.
        foreach (['Apache server-status', 'Apache server-info', 'TRACE method', 'Apache version banner', 'Security headers'] as $label) {
            $t->assertTrue(isset($checks[$label]) && in_array($checks[$label], ['OK', 'WARN', 'FAIL'], true), "{$label} is checked (got " . ($checks[$label] ?? 'nothing') . ')');
        }
    },

    'php.ini sizes are read the way PHP reads them' => function (TestRunner $t): void {
        $t->assertEquals(40 * 1024 * 1024, DeploymentCheck::iniBytes('40M'));
        $t->assertEquals(2 * 1024 ** 3, DeploymentCheck::iniBytes('2G'));
        $t->assertEquals(512 * 1024, DeploymentCheck::iniBytes('512k'));
        $t->assertEquals(8000, DeploymentCheck::iniBytes('8000'));
        $t->assertEquals(PHP_INT_MAX, DeploymentCheck::iniBytes('-1'), 'Unlimited');
    },

    'a full https site address contributes only its path to URLs and the cookie path' => function (TestRunner $t): void {
        // base_path_uri() is computed once per process, so each address runs in a fresh one.
        $run = static function (string $address): string {
            // A script file, not "php -r": Windows argument quoting drops double quotes.
            $temp = (string) tempnam(sys_get_temp_dir(), 'shb');
            $script = $temp . '.php';
            file_put_contents($script, '<?php require ' . var_export(BASE_PATH . '/bootstrap.php', true) . '; App\Core\Config::set(\'app.base_url\', ' . var_export($address, true) . '); echo base_path_uri(), \'|\', url(\'/login\'), \'|\', absolute_url(\'/login\');');
            try {
                return trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)));
            } finally {
                @unlink($script);
                @unlink($temp);
            }
        };
        $t->assertEquals('|/login|https://enroll.lyceum.edu.ph/login', $run('https://enroll.lyceum.edu.ph/'));
        $t->assertEquals('/portal|/portal/login|https://lyceum.edu.ph/portal/login', $run('https://lyceum.edu.ph/portal/'));
        $t->assertEquals('/SchoolHub/public|/SchoolHub/public/login', implode('|', array_slice(explode('|', $run('/SchoolHub/public')), 0, 2)), 'A bare path still works');
    },
];
