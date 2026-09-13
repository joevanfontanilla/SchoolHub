<?php
/**
 * Copy this file to config/config.local.php and edit it.
 *
 * config.local.php is gitignored. It is merged recursively over config.php, so
 * include only the keys you want to change.
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
];
