<?php
/**
 * Check the mail settings by sending one test message (docs/23).
 *
 *   php database/send_test_email.php you@example.com
 *
 * Uses the mail.* settings in config/config.local.php. With the "log" driver the message is
 * written to storage/logs/mail.log instead of being sent. Exit status 1 when it fails, with the
 * mail server's own reason (never the password).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('This script may only be run from the command line.');
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Config;
use App\Core\Mailer;
use App\Models\SystemSetting;

$to = (string) ($argv[1] ?? '');
if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "  Usage: php database/send_test_email.php you@example.com\n");
    exit(1);
}

$driver = Mailer::driver();
echo "  Driver: {$driver}" . ($driver === 'smtp' ? ' (' . Config::get('mail.host') . ':' . Config::get('mail.port') . ', ' . Config::get('mail.encryption') . ')' : '') . "\n";
echo '  From:   ' . Config::get('mail.from_address') . "\n";

$school = (string) SystemSetting::get('school_name', (string) Config::get('app.name', 'SchoolHub'));
try {
    $started = microtime(true);
    Mailer::send($to, "Test message · {$school}", "This is a test message from the {$school} portal.\n\nIf you can read it, email from SchoolHub works: \"Forgot password?\" links and emailed account links will arrive.\n\nSent " . date('F j, Y g:i A') . '.');
    printf("  Sent to %s in %.1f s%s\n", $to, microtime(true) - $started, $driver === 'log' ? ' (written to storage/logs/mail.log, not delivered)' : '');
} catch (Throwable $e) {
    fwrite(STDERR, '  FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
