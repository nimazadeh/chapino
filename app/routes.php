<?php

declare(strict_types=1);

use App\Controllers\DesignSystemController;
use App\Controllers\HealthController;
use App\Controllers\HomeController;
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
    $router->get('/', static fn ($request) => (new HomeController($app))->show($request));

    $router->get('/api/health', static fn ($request, $params) => (new HealthController($app))->show($request));

    // Development-only: the style guide refuses to serve itself when app.env is production, so the
    // route can be registered unconditionally and cannot be forgotten during a deployment.
    $router->get('/design-system', static fn ($request) => (new DesignSystemController($app))->show($request));
    $router->post('/design-system', static fn ($request) => (new DesignSystemController($app))->submit($request));
};
