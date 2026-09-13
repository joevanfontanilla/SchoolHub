<?php
/**
 * Default configuration.
 *
 * Do NOT put secrets here — this file is committed. Copy
 * config/config.local.example.php to config/config.local.php and override there;
 * config.local.php is gitignored and is merged over these values.
 */

return [
    'app' => [
        'name'     => 'SchoolHub',
        'env'      => 'local',          // local | production
        'debug'    => true,             // MUST be false in production
        'timezone' => 'Asia/Manila',
        // Leave null to auto-detect the base path from the request. Auto-detection
        // is what lets the app run from a subdirectory without configuration.
        'base_url' => null,
    ],

    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'schoolhub',
        'username' => 'root',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],

    'session' => [
        'name'         => 'schoolhub_session',
        'lifetime'     => 7200,   // seconds the cookie survives
        'idle_timeout' => 1800,   // seconds of inactivity before forced logout
        'secure'       => false,  // set true once HTTPS is in place (docs/23)
    ],

    'security' => [
        // Login rate limiting (docs/07). Counted from login_logs.
        'max_login_attempts'    => 5,    // per account before lockout
        'lockout_minutes'       => 15,
        'ip_max_attempts'       => 20,   // per IP within the window
        'ip_window_minutes'     => 15,
        'password_min_length'   => 8,
        'reset_token_ttl_min'   => 60,
    ],

    'uploads' => [
        'max_size_kb'        => 5120,
        'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png'],
        'allowed_mime'       => ['application/pdf', 'image/jpeg', 'image/png'],
    ],
];
