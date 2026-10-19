<?php
/**
 * The Backups page must open on a locked-down shared host (docs/20, docs/23): looking for the command-line php must never raise an error there.
 * On the live InfinityFree site the page returned a 500 because is_file() on the PHP folder warns under open_basedir, and the app turns every warning into an error.
 */

declare(strict_types=1);

require_once __DIR__ . '/support.php';

/** Run PHP code in a separate process with open_basedir limited to the site, the way a shared host is, and the app's web error handler installed. @return array{0:int,1:string} */
function onLockedDownHost(string $code): array
{
    $script = tempnam(sys_get_temp_dir(), 'sh_obd_');
    file_put_contents($script, "<?php\nchdir(" . var_export(BASE_PATH, true) . ");\nrequire 'bootstrap.php';\nset_error_handler([App\\Core\\ErrorHandler::class, 'handleError']);\n"
        . "ini_set('open_basedir', " . var_export(BASE_PATH . PATH_SEPARATOR . sys_get_temp_dir(), true) . ");\n{$code}\n");
    $output = [];
    $status = 0;
    exec('"' . PHP_BINARY . '" ' . escapeshellarg($script) . ' 2>&1', $output, $status);
    @unlink($script);

    return [$status, trim(implode("\n", $output))];
}

return [
    'finding the command-line php never raises an error on a host that restricts file access' => function (TestRunner $t): void {
        [$status, $output] = onLockedDownHost("echo App\\Services\\BackupService::phpCommand();");
        $t->assertEquals(0, $status, "The page must not fail: {$output}");
        $t->assertTrue($output !== '' && !str_contains($output, 'Warning') && !str_contains($output, 'Exception'), "It names a command, quietly: {$output}");
    },

    'the same lookup on a normal install still finds the real php' => function (TestRunner $t): void {
        $command = App\Services\BackupService::phpCommand();
        $t->assertTrue($command === 'php' || is_file($command), 'Either the plain command or a real file');
    },

    'the Backups page uses it, and says what to do on a host without scheduled tasks' => function (TestRunner $t): void {
        $view = (string) file_get_contents(BASE_PATH . '/app/Views/admin/backups/index.php');
        $t->assertContains('BackupService::phpCommand()', $view);
        $t->assertFalse(str_contains($view, 'is_file('), 'No direct file probing in the view');
        $t->assertFalse(str_contains($view, 'php_ini_loaded_file'), 'And no direct php_ini_loaded_file()');
        $t->assertContains('no scheduled tasks', $view, 'Free hosting without a task scheduler is explained');
        $t->assertContains('phpMyAdmin', $view, 'With the fallback: create a backup by hand or export from the host');
    },
];
