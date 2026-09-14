<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Application;
use App\Core\Request;
use App\Core\Response;

/**
 * The landing page.
 *
 * Server-rendered HTML, like every page that is not the design canvas: the browser renders it, but
 * nothing here computes a design. Controllers in this project validate, decide and hand a finished
 * dataset to a view - they never build markup by concatenation, which is how escaping gets forgotten.
 */
final class HomeController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function show(Request $request): Response
    {
        $html = $this->app->view()->render('layouts/base', [
            'title' => '',
            'base' => $request->basePath(),
            'content' => $this->app->view()->render('home'),
        ]);

        return Response::html($html);
    }
}
