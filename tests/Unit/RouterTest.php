<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use Tests\TestCase;

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        $router = new Router();
        $router->get('/', static fn (): Response => Response::html('<h1>خانه</h1>'));
        $router->get('/api/health', static fn (): Response => Response::ok(['status' => 'ok']));
        $router->get('/api/products/{id}', static fn (Request $request, array $params): Response => Response::ok([
            'id' => $params['id'],
        ]));
        $router->post('/api/products', static fn (): Response => Response::ok([], 201));

        return $router;
    }

    public function testStaticRouteIsDispatched(): void
    {
        $response = $this->router()->dispatch(Request::create('GET', '/api/health'));
        $this->assertSame(200, $response->status);
        $this->assertStringContains('"status":"ok"', $response->body);
    }

    public function testRootRouteMatchesWithAndWithoutTrailingSlash(): void
    {
        $this->assertSame(200, $this->router()->dispatch(Request::create('GET', '/'))->status);
        $this->assertSame(200, $this->router()->dispatch(Request::create('GET', '/'))->status);
    }

    public function testPathParameterIsPassedToHandler(): void
    {
        $response = $this->router()->dispatch(Request::create('GET', '/api/products/42'));
        $this->assertStringContains('"id":"42"', $response->body);
    }

    public function testUnknownPathReturns404(): void
    {
        $exception = $this->assertThrows(
            HttpException::class,
            fn () => $this->router()->dispatch(Request::create('GET', '/api/does-not-exist')),
        );

        $this->assertSame(404, $exception->status);
        $this->assertSame('not_found', $exception->errorCode);
    }

    public function testWrongMethodOnExistingPathReturns405NotDefault(): void
    {
        $exception = $this->assertThrows(
            HttpException::class,
            fn () => $this->router()->dispatch(Request::create('DELETE', '/api/products/42')),
        );

        $this->assertSame(405, $exception->status);
        $this->assertSame(['GET'], $this->router()->allowedMethods('/api/products/42'));
    }

    public function testHandlerMustReturnAResponse(): void
    {
        $router = new Router();
        $router->get('/broken', static fn (): string => 'oops');

        $this->assertThrows(\LogicException::class, fn () => $router->dispatch(Request::create('GET', '/broken')));
    }

    public function testPathSegmentsDoNotMatchAcrossSlashes(): void
    {
        $exception = $this->assertThrows(
            HttpException::class,
            fn () => $this->router()->dispatch(Request::create('GET', '/api/products/42/extra')),
        );

        $this->assertSame(404, $exception->status);
    }
}
