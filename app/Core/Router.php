<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Explicit routing table. No magic, no annotations, no framework: the list of routes
 * is a real list you can read (see app/routes.php).
 *
 * Patterns use {name} placeholders; parameters are matched as path segments and passed
 * to the handler as an array. A 405 is returned when the path exists for another method.
 */
final class Router
{
    /** @var list<array{method: string, pattern: string, regex: string, params: list<string>, handler: callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function put(string $pattern, callable $handler): void
    {
        $this->add('PUT', $pattern, $handler);
    }

    public function patch(string $pattern, callable $handler): void
    {
        $this->add('PATCH', $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    public function add(string $method, string $pattern, callable $handler): void
    {
        $params = [];
        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];

                return '([^/]+)';
            },
            '/' . trim($pattern, '/'),
        );

        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => '/' . trim($pattern, '/'),
            'regex' => '#^' . $regex . '$#',
            'params' => $params,
            'handler' => $handler,
        ];
    }

    /** @return list<string> routes matching the path with a different method */
    public function allowedMethods(string $path): array
    {
        $methods = [];
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path) === 1) {
                $methods[] = $route['method'];
            }
        }

        return array_values(array_unique($methods));
    }

    public function dispatch(Request $request): Response
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path, $matches) !== 1) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }

            array_shift($matches);
            /** @var array<string, string> $params */
            $params = array_combine($route['params'], $matches) ?: [];

            /** @var mixed $result */
            $result = ($route['handler'])($request, $params);

            if ($result instanceof Response) {
                return $result;
            }

            throw new \LogicException('Route handler must return a Response instance.');
        }

        if ($pathMatched) {
            throw HttpException::methodNotAllowed();
        }

        throw HttpException::notFound();
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_map(static fn (array $r): string => $r['method'] . ' ' . $r['pattern'], $this->routes);
    }
}
