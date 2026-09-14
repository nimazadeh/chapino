<?php

declare(strict_types=1);

/**
 * Router for PHP's built-in web server (`php -S`).
 *
 * Why this file exists: `php -S` has no rewriting and no `.htaccess`, so out of the box it serves the
 * front controller for `/` (because `index.php` is the directory index) and returns 404 for every
 * other route - `/design-system`, `/api/health`, `/tests/browser`. The application is the same; only
 * the web server is missing. This script is the missing two lines: real files are served as files,
 * everything else goes to the front controller.
 *
 * It is a **local development aid and never deployed** (it lives in `tools/dev/`, outside `public/`):
 * on XAMPP and Laragon the `.htaccess` does this job, and that path is the one the runbook verifies.
 *
 * Usage (from the repository root):
 *
 *     php -S localhost:8000 -t public tools/dev/serve-router.php
 *
 * Then open http://localhost:8000/ - the same pages as an Apache installation, with clean URLs.
 */

// Only the built-in server may execute this. Reached any other way (an Apache misconfiguration, a
// copied file), it does nothing rather than becoming a second entry point into the application.
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit("serve-router.php is a router for PHP's built-in server only.\n");
}

$publicPath = realpath(__DIR__ . '/../public');
if ($publicPath === false) {
    http_response_code(500);
    exit("public/ not found next to tools/dev/.\n");
}

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$candidate = realpath($publicPath . $path);

// An existing file inside public/ (stylesheet, font, script) is served by the built-in server itself,
// which is exactly what Apache does for those URLs. Returning false is how a router says "not mine".
if ($candidate !== false && is_file($candidate) && str_starts_with($candidate, $publicPath . DIRECTORY_SEPARATOR)) {
    return false;
}

// Everything else is a route. The built-in server does not rewrite, so the front controller is told
// what a rewritten request would look like: the script is /index.php and the path is untouched.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $publicPath . '/index.php';

require $publicPath . '/index.php';
