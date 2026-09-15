<?php
/**
 * Deployment preflight (docs/23): run on the school server before opening SchoolHub,
 * and again after any change to the server or configuration.
 *
 *   php database/check_deployment.php
 *   php database/check_deployment.php --url=https://enroll.school.edu.ph/
 *   php database/check_deployment.php --url=https://192.168.1.10/ --insecure
 *
 * Without --url it checks configuration, php.ini, accounts, files, the database,
 * storage, and backups. With --url it also requests the running site: headers, the
 * session cookie, the HTTP-to-HTTPS redirect, and that private files are refused.
 * --insecure accepts a self-signed certificate. Changes nothing.
 *
 * Exits with status 1 when any check fails.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('This script may only be run from the command line.');
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Config;
use App\Services\DeploymentCheck;
use App\Services\SystemHealth;

$options = getopt('', ['url:', 'insecure']);
$checks = DeploymentCheck::checks();

$url = (string) ($options['url'] ?? '');
if ($url !== '') {
    if (!preg_match('#^https?://#i', $url)) {
        fwrite(STDERR, "  --url must be a full address, such as https://enroll.school.edu.ph/\n");
        exit(2);
    }
    $checks = array_merge($checks, DeploymentCheck::httpChecks($url, !isset($options['insecure'])));
}

$marks = ['OK' => '  ok  ', 'WARN' => ' WARN ', 'FAIL' => ' FAIL '];
printf("\n  SchoolHub %s deployment check · %s\n", Config::get('app.version'), date('Y-m-d H:i'));
$group = '';
foreach ($checks as $check) {
    if ($check['group'] !== $group) {
        $group = $check['group'];
        echo "\n  {$group}\n";
    }
    printf("  [%s] %-30s %s\n", $marks[$check['status']], $check['label'], $check['detail']);
}

$counts = array_count_values(array_column($checks, 'status'));
$overall = SystemHealth::overall($checks);
printf("\n  %d ok, %d warnings, %d failures.\n", $counts['OK'] ?? 0, $counts['WARN'] ?? 0, $counts['FAIL'] ?? 0);
echo match ($overall) {
    'FAIL'  => "  NOT READY: fix every FAIL before students use the system.\n\n",
    'WARN'  => "  Ready, with warnings to review.\n\n",
    default => "  Ready.\n\n",
};
if ($url === '') {
    echo "  Add --url=https://your-address/ to also test the running site.\n\n";
}
exit($overall === 'FAIL' ? 1 : 0);
