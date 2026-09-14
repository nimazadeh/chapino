<?php

declare(strict_types=1);

use App\Controllers\HealthController;
use App\Core\Application;
use App\Core\Router;

/**
 * The route table.
 *
 * Read it as a list: method, path, controller action. No attributes, no file-name
 * conventions, no discovery. Phase 0 defines only what exists; product routes are added
 * by the phase that builds them.
 */
return static function (Router $router, Application $app): void {
    $router->get('/', static fn () => App\Core\Response::html(
        '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
        . '<title>چاپینو</title><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '</head><body><main><h1>چاپینو</h1>'
        . '<p>سامانه در حال راه‌اندازی است.</p></main></body></html>',
    ));

    $router->get('/api/health', static fn ($request, $params) => (new HealthController($app))->show($request));
};
