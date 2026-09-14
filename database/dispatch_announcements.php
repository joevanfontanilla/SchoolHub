<?php
/**
 * Deliver scheduled announcements whose publish time has come (docs/16).
 *
 *   php database/dispatch_announcements.php
 *
 * The portal already does this on page loads, so a scheduled announcement goes out
 * as soon as anyone signs in after its time. Run this from Windows Task Scheduler
 * (for example every 5 minutes) to deliver on time even when nobody is using the
 * portal. Safe to run any number of times: each announcement is delivered once.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('This script may only be run from the command line.');
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\AnnouncementService;

$sent = AnnouncementService::dispatchDue(true);
echo '  ' . date('Y-m-d H:i:s') . "  notifications sent: {$sent}\n";
