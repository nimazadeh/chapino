<?php

declare(strict_types=1);

/**
 * Bootstraps the application: constants, autoloader, configuration, error handling.
 *
 * Returns the application container. Nothing here knows about HTTP request details;
 * that is the front controller's job (public/index.php).
 *
 * Works on plain shared hosting: no shell, no build step, no dependency manager (ADR-0002).
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

require_once APP_ROOT . '/app/Core/Autoloader.php';

App\Core\Autoloader::register(APP_ROOT . '/app', 'App\\');

App\Core\Clock::init();

$app = new App\Core\Application(APP_ROOT);

return $app;
