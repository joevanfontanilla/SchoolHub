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
        'version'  => '1.0.0-rc.1',          // shown in System Health and written into backups
        'env'      => 'local',          // local | production
        'debug'    => true,             // MUST be false in production
        'timezone' => 'Asia/Manila',
        // Leave null to auto-detect the base path from the request. Auto-detection
        // is what lets the app run from a subdirectory without configuration.
        'base_url' => null,
        // Proxies allowed to tell SchoolHub the visitor's real address and that the connection is
        // HTTPS (X-Forwarded-For, X-Forwarded-Proto): a hosting provider's load balancer or
        // Cloudflare's published ranges. Empty means none is believed (docs/23).
        'trusted_proxies' => [],
        // Only for command-line scripts when the web folder was moved out of the project, as on
        // shared hosting: the full path of public_html. Web requests find it on their own.
        'public_path' => null,
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

    // Email for "Forgot password?" and emailed account links (docs/07, docs/23). The password
    // belongs in config.local.php. driver: smtp | log | none. Left null, it is "log" outside
    // production (messages go to storage/logs/mail.log) and "none" in production.
    'mail' => [
        'driver'       => null,
        'host'         => '',
        'port'         => 587,
        'encryption'   => 'tls',     // tls = STARTTLS (usually port 587) | ssl (usually port 465)
        'username'     => '',
        'password'     => '',
        'from_address' => 'no-reply@schoolhub.test',
        'from_name'    => null,      // null uses the school name from System Settings
        'timeout'      => 15,
        'verify_peer'  => true,
    ],

    'uploads' => [
        'max_size_kb'        => 5120,
        'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png'],
        'allowed_mime'       => ['application/pdf', 'image/jpeg', 'image/png'],
    ],
];
