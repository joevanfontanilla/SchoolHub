<?php
/**
 * Copy this file to config/config.local.php and edit it.
 *
 * config.local.php is gitignored. It is merged recursively over config.php, so
 * include only the keys you want to change.
 *
 * These are development settings for XAMPP. For a server, start from
 * deploy/config.production.php instead (docs/23).
 */

return [
    'app' => [
        'env'   => 'local',
        'debug' => true,
    ],

    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'schoolhub',
        'username' => 'root',
        'password' => '',
    ],

    // Outside production, email is written to storage/logs/mail.log instead of being sent,
    // so "Forgot password?" can be tried locally. To send real mail, set 'driver' => 'smtp'
    // and the server settings shown in deploy/config.production.php.
    // 'mail' => ['driver' => 'log'],
];
