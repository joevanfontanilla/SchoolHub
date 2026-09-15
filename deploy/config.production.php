<?php
/**
 * Production settings template (docs/23).
 *
 * On the server, copy this file to schoolhub/config/config.local.php and replace every value in
 * angle brackets. That file holds passwords: never commit it, never put it in the web folder, and
 * make it readable only by the web server's account (chmod 640 on Linux).
 *
 * Check the result with: php database/check_deployment.php --url=https://<your address>/
 */

return [
    'app' => [
        'env'      => 'production',
        'debug'    => false,
        // The address people type, with https:// and without a trailing slash.
        'base_url' => 'https://<enroll.yourschool.edu.ph>',
        // Only if visitors reach this server through Cloudflare or the host's load balancer:
        // list those proxies' address ranges (see docs/23). Leave empty otherwise.
        'trusted_proxies' => [],
        // Shared hosting only, when public_html is outside this folder and you run the command-line
        // scripts: the full path of public_html, for example '/home/<account>/public_html'.
        'public_path' => null,
    ],

    'db' => [
        // On shared hosting: the database host shown in the panel (often "localhost").
        'host'     => 'localhost',
        'port'     => 3306,
        // Shared hosting adds the account name as a prefix, for example "u123456_schoolhub".
        'database' => '<database name>',
        'username' => '<database user, never root>',
        'password' => '<long random password, 16+ characters>',
    ],

    'session' => [
        'secure'       => true,     // the sign-in cookie travels only over HTTPS
        'idle_timeout' => 1800,     // 30 minutes of inactivity
    ],

    // A mailbox created in the hosting panel, for "Forgot password?" and emailed account links.
    // Test it with: php database/send_test_email.php you@example.com
    'mail' => [
        'driver'       => 'smtp',
        'host'         => '<smtp.hostinger.com, mail.yourschool.edu.ph, smtp.gmail.com, ...>',
        'port'         => 587,          // 587 with 'tls', or 465 with 'ssl'
        'encryption'   => 'tls',
        'username'     => '<no-reply@yourschool.edu.ph>',
        'password'     => '<mailbox password or app password>',
        'from_address' => '<no-reply@yourschool.edu.ph>',
        'from_name'    => null,         // null uses the school name from System Settings
    ],
];
