<?php

declare(strict_types=1);

/**
 * Front controller. This is the only PHP file the web server needs to reach inside
 * the application; everything else lives outside the document root.
 *
 * It works on plain shared hosting: no shell, no build step, no rewrite requirement
 * (when rewriting is unavailable, requests arrive as /index.php?r=/path and the
 * Request object resolves the path - see ADR-0002).
 */

$app = require dirname(__DIR__) . '/app/bootstrap.php';

$request = App\Core\Request::fromGlobals();

$app->handle($request)->send();
