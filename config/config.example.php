<?php

declare(strict_types=1);

/**
 * Configuration template.
 *
 * Copy this file to config/config.php and fill in the real values.
 * config/config.php is NOT in the repository: it holds secrets and host paths.
 *
 * Every value here has a safe default in Config::defaults(); the file only needs the
 * keys you actually want to change. Never commit real credentials (see the security rule).
 */

return [
    'app' => [
        'name' => 'chapino',
        'env' => 'production',       // production | staging | local
        'debug' => false,            // must stay false in production: debug messages can contain internals
        'url' => 'https://example.ir',
        'timezone' => 'UTC',         // storage is UTC; Iranian time is a display concern
    ],

    'storage' => [
        // Writable directory OUTSIDE the web root. Holds logs, cache, uploads, backups.
        'path' => 'storage',
    ],

    'database' => [
        // 'mysql' for the target host (MySQL/MariaDB compatible), 'sqlite' for local work and tests.
        'driver' => 'mysql',
        'host' => 'localhost',
        'port' => 3306,
        'name' => '',
        'user' => '',
        'password' => '',
        'charset' => 'utf8mb4',
    ],

    'security' => [
        'session_name' => 'chapino_session',
        'session_idle_timeout' => 3600,
        'session_absolute_timeout' => 86400,
    ],

    'logging' => [
        'level' => 'info',           // debug | info | warning | error
        'path' => 'storage/logs',
    ],

    'sms' => [
        'provider' => 'kavenegar',   // C-11
        'api_key' => '',             // never commit a real key
        'sender' => '',
    ],

    'payment' => [
        'provider' => 'zarinpal',    // C-10
        'merchant_id' => '',
        'sandbox' => true,           // keep true until a real transaction is verified
    ],

    'ai' => [
        // C-12: no AI provider in the current scope. The seam is off; the UI shows a
        // disabled control and the server refuses the endpoint either way (ADR-0003).
        'enabled' => false,
        'provider' => 'none',
    ],
];
