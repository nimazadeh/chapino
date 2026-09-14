<?php

declare(strict_types=1);

namespace App\Core;

/**
 * The application container: configuration, logging, error handling, routing and the
 * middleware pipeline. Everything is wired explicitly here - no discovery, no magic,
 * no framework (C-4, ADR-0002).
 *
 * The request lifecycle is:
 *   front controller -> Application::handle()
 *     -> middleware (pre) -> router -> controller -> middleware (post) -> Response::send()
 */
final class Application
{
    private ?Config $config = null;
    private ?Logger $logger = null;
    private ?ErrorHandler $errorHandler = null;
    private ?Router $router = null;
    private ?\App\Core\Database\Connection $database = null;
    private ?\App\Core\Security\Session $session = null;
    private ?\App\Core\Security\Csrf $csrf = null;
    private ?\App\Core\Security\RateLimiter $rateLimiter = null;
    private ?\App\Core\Jobs\Queue $queue = null;
    private ?View $view = null;
    private ?\App\Core\Jobs\JobRunner $jobRunner = null;

    public function __construct(private readonly string $root)
    {
    }

    public function root(): string
    {
        return $this->root;
    }

    public function config(): Config
    {
        return $this->config ??= Config::load($this->root);
    }

    /** @param array<string, mixed>|null $overrides */
    public function withConfig(?array $overrides): self
    {
        $this->config = Config::load($this->root, $overrides);

        return $this;
    }

    public function logger(): Logger
    {
        if ($this->logger === null) {
            $config = $this->config();
            $this->logger = new Logger($config->path('logging.path'), $config->string('logging.level', 'info'));
        }

        return $this->logger;
    }

    public function errorHandler(): ErrorHandler
    {
        return $this->errorHandler ??= new ErrorHandler($this->logger(), $this->config()->isDebug());
    }

    /**
     * The HTML renderer for this request.
     *
     * Views live outside the web root and are rendered by the application, never by the web server
     * directly: a template that could be requested over HTTP would be executed without the
     * application's context, or - worse - served as source.
     */
    public function view(): View
    {
        return $this->view ??= new View($this->root . '/app/Views');
    }

    /**
     * The shared database connection for this request.
     *
     * Created once and reused: shared hosting allows very few MySQL connections, and a second
     * connection would also mean a second transaction context. Controllers receive this through the
     * container instead of opening their own.
     */
    public function database(): \App\Core\Database\Connection
    {
        return $this->database ??= \App\Core\Database\Connection::fromConfig($this->config(), $this->root);
    }

    /**
     * True when the request reached the application over HTTPS.
     *
     * Only server-provided facts are trusted: the `HTTPS` server variable, or a configured URL that
     * is explicitly https. A client-supplied `X-Forwarded-Proto` is ignored here - trusting it would
     * let an attacker make the application believe a plain connection was secure, which weakens the
     * session cookie (security rule). A deployment behind a reverse proxy states that in configuration
     * instead of guessing.
     */
    public function isHttps(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off' && $https !== '0') {
            return true;
        }

        return str_starts_with(strtolower($this->config()->string('app.url', '')), 'https://');
    }

    /**
     * The session for this request.
     *
     * Not started here on purpose: the session layer decides *when* to start (a cookie is present,
     * or the request changes state), so an anonymous page view does not create session storage.
     */
    public function session(): \App\Core\Security\Session
    {
        return $this->session ??= new \App\Core\Security\Session(
            $this->config(),
            $this->database(),
            $this->logger(),
            $this->isHttps(),
        );
    }

    public function csrf(): \App\Core\Security\Csrf
    {
        return $this->csrf ??= new \App\Core\Security\Csrf($this->session());
    }

    /** Counters are namespaced per installation so two deployments sharing a database cannot collide. */
    public function rateLimiter(): \App\Core\Security\RateLimiter
    {
        return $this->rateLimiter ??= new \App\Core\Security\RateLimiter(
            $this->database(),
            $this->config()->string('app.name', 'chapino') . '|',
        );
    }

    public function queue(): \App\Core\Jobs\Queue
    {
        return $this->queue ??= new \App\Core\Jobs\Queue($this->database());
    }

    /** The job runner, with the handler registry loaded from app/Jobs/handlers.php. */
    public function jobs(string $runnerId = 'cron'): \App\Core\Jobs\JobRunner
    {
        if ($this->jobRunner === null) {
            /** @var array<string, callable> $handlers */
            $handlers = require $this->root . '/app/Jobs/handlers.php';
            $this->jobRunner = new \App\Core\Jobs\JobRunner(
                $this->database(),
                $this->queue(),
                $this->logger(),
                $handlers,
                $runnerId,
            );
        }

        return $this->jobRunner;
    }

    public function router(): Router
    {
        if ($this->router === null) {
            $this->router = new Router();
            $register = require $this->root . '/app/routes.php';
            $register($this->router, $this);
        }

        return $this->router;
    }

    /**
     * Handles one request and returns the response. Never throws to the caller:
     * every failure becomes a safe response (see ErrorHandler).
     */
    public function handle(Request $request): Response
    {
        // The error handler itself needs configuration and logging, and configuration can
        // be the very thing that is missing. Everything is therefore inside the try, and a
        // failure to build the error handler falls back to a safe response that needs
        // neither (a missing configuration must produce an honest 503, not a blank page).
        try {
            $this->errorHandler()->register();
            $response = $this->dispatch($request);
        } catch (\Throwable $e) {
            $response = $this->safeErrorResponse($e, $request->requestId);
        }

        return $this->addDefaultHeaders($response, $request);
    }

    private function safeErrorResponse(\Throwable $e, string $requestId): Response
    {
        try {
            return $this->errorHandler()->errorResponse($e, $requestId);
        } catch (\Throwable) {
            // Last resort: no logger, no config. Only messages written by this application
            // are ever shown; anything else becomes a generic Persian error.
            if ($e instanceof ConfigurationException) {
                return Response::error('config_error', $e->getMessage(), 503, [], $requestId);
            }

            return Response::error(
                'server_error',
                'خطای غیرمنتظره‌ای رخ داد. لطفاً بعداً دوباره تلاش کنید.',
                500,
                [],
                $requestId,
            );
        }
    }

    private function dispatch(Request $request): Response
    {
        /** @var list<callable> $middleware */
        $middleware = require $this->root . '/app/middleware.php';

        // Dependencies are resolved once per request, and the closures are static:
        // a `static fn` cannot use `$this`, and a per-layer container lookup would
        // repeat the same work for every middleware.
        $logger = $this->logger();
        $router = $this->router();
        $app = $this;

        $handler = static function (Request $request) use ($logger, $router): Response {
            $logger->info('request', [
                'request_id' => $request->requestId,
                'method' => $request->method,
                'path' => $request->path,
            ]);

            return $router->dispatch($request);
        };

        foreach (array_reverse($middleware) as $layer) {
            $next = $handler;
            $handler = static fn (Request $request): Response => $layer($request, $next, $app);
        }

        return $handler($request);
    }

    private function addDefaultHeaders(Response $response, Request $request): Response
    {
        $headers = [
            // Content type and framing are declared explicitly, not inherited from host defaults.
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Request-Id' => $request->requestId,
        ];

        // HSTS is only meaningful over HTTPS and must be introduced deliberately
        // when the deployment is confirmed (O-20).
        if (($request->header('x-forwarded-proto') ?? '') === 'https') {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $response->withHeader('X-Content-Type-Options', $headers['X-Content-Type-Options'])
            ->withHeader('X-Frame-Options', $headers['X-Frame-Options'])
            ->withHeader('Referrer-Policy', $headers['Referrer-Policy'])
            ->withHeader('X-Request-Id', $headers['X-Request-Id']);
    }

    /** CLI entry point (cron, migrations, installer): runs a PHP file with the app wired. */
    public function runCli(string $script): int
    {
        /** @var callable $runner */
        $runner = require $script;

        return (int) $runner($this);
    }
}
