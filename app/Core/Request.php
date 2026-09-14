<?php

declare(strict_types=1);

namespace App\Core;

/**
 * An incoming HTTP request.
 *
 * Everything here is UNTRUSTED input. Values are never used for security decisions
 * without validation at the point of use (see the security rule).
 */
final class Request
{
    /** @param array<string, mixed> $query @param array<string, mixed> $body @param array<string, string> $headers */
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        private array $query,
        private array $body,
        private array $headers,
        private array $files,
        public readonly string $clientIp,
        public readonly string $requestId,
        private ?array $json = null,
        /**
         * Size of the body that actually arrived, in bytes, or null when it is unknown.
         *
         * Kept because `Content-Length` is a claim, not a fact: a client may omit it (chunked
         * encoding) or understate it, and a size limit that can be bypassed by leaving out a header
         * is not a limit. Null means "not measured" - never "empty".
         */
        private ?int $rawBodyLength = null,
        private readonly string $basePath = '',
    ) {
    }

    /** Builds a request from the current PHP environment. */
    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path = self::resolvePath($_SERVER);
        $headers = self::collectHeaders($_SERVER);
        $raw = (string) file_get_contents('php://input');
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        $json = null;

        if ($raw !== '' && str_contains($contentType, 'application/json')) {
            $decoded = json_decode($raw, true);
            $json = is_array($decoded) ? $decoded : [];
        }

        return new self(
            $method,
            self::normalizePath($path),
            $_GET,
            $_POST,
            $headers,
            $_FILES,
            self::clientIp($_SERVER),
            self::generateRequestId(),
            $json,
            strlen($raw),
            self::deploymentPrefix($_SERVER),
        );
    }

    /** Test/CLI seam: build a request without touching superglobals. */
    public static function create(
        string $method,
        string $path,
        array $query = [],
        array $body = [],
        array $headers = [],
        string $clientIp = '127.0.0.1',
        ?string $requestId = null,
        ?int $rawBodyLength = null,
    ): self {
        return new self(
            strtoupper($method),
            self::normalizePath($path),
            $query,
            $body,
            array_change_key_case($headers, CASE_LOWER),
            [],
            $clientIp,
            $requestId ?? self::generateRequestId(),
            null,
            $rawBodyLength,
        );
    }

    /** The measured body size in bytes, or null when it was not measured. */
    public function rawBodyLength(): ?int
    {
        return $this->rawBodyLength;
    }

    /**
     * The deployment prefix under which the application is served, without a trailing slash:
     * '' when the front controller is the document root, '/chapino/public' in a subfolder install.
     *
     * This is the *output* side of the same fact `resolvePath()` handles on the input side. A link or
     * an asset URL that ignores it works on a developer machine (document root = project) and 404s on
     * the XAMPP/Laragon layout the owner actually uses (htdocs/chapino/public), which is the failure
     * mode the subfolder tests exist for.
     *
     * It is derived from SCRIPT_NAME - a server fact - and never from the request URI, which the
     * client controls: a prefix a client could choose would let a link point anywhere.
     */
    public function basePath(): string
    {
        return $this->basePath;
    }

    /**
     * Supports hosts without URL rewriting: when the front controller is reached as
     * /index.php?r=/api/health, the requested path comes from the `r` parameter.
     *
     * @param array<string, mixed> $server
     */
    /**
     * The directory the front controller is served from, as the web server reports it.
     *
     * Both the input side (`resolvePath`) and the output side (`basePath`) need the same answer, so
     * it is computed once here: two copies of this rule would eventually disagree, and the symptom
     * would be a 404 for a stylesheet that exists.
     *
     * @param array<string, mixed> $server
     */
    private static function deploymentPrefix(array $server): string
    {
        $scriptName = str_replace('\\', '/', (string) ($server['SCRIPT_NAME'] ?? ''));
        if ($scriptName === '' || $scriptName === '/') {
            return '';
        }

        // /index.php -> nothing to strip; /shop/index.php -> /shop
        $directory = str_ends_with($scriptName, '.php')
            ? dirname($scriptName)
            : $scriptName;
        $directory = rtrim($directory, '/');

        return $directory === '' || $directory === '.' || $directory === '/' ? '' : $directory;
    }

    private static function resolvePath(array $server): string
    {
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $scriptName = str_replace('\\', '/', (string) ($server['SCRIPT_NAME'] ?? ''));

        // A host may reach the front controller in four shapes, and all four are normal on shared
        // hosting: /index.php (rewrite disabled), /index.php/api/orders (PATH_INFO), and either of
        // those inside a subfolder - how XAMPP and Laragon installations usually look, where the
        // project sits in htdocs/chapino and the served path is /chapino/public/...
        //
        // The script name, or the directory it lives in, is a deployment prefix, never part of the
        // route. Stripping only the full script name was not enough: a request for /chapino/public/
        // does not start with /chapino/public/index.php, so the prefix stayed in the path and every
        // route in a subfolder installation returned 404 (found by the subfolder test).
        // Two strips, longest first, because the front controller sits inside the deployment
        // directory: /shop/public/index.php/api/health must lose the whole file path, while
        // /shop/public/api/health only has a directory to lose. Stripping the directory first would
        // leave `/index.php/api/health` behind and 404 a URL that a rewrite legitimately produces.
        if ($scriptName !== '' && $scriptName !== '/' && str_starts_with($path, $scriptName)) {
            $path = substr($path, strlen($scriptName));
        } else {
            $prefix = self::deploymentPrefix($server);
            if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
                $path = substr($path, strlen($prefix));
            }
        }

        if ($path === '' || $path === '/') {
            $path = '/';
        }

        if (($path === '/' || $path === '/index.php') && isset($_GET['r']) && is_string($_GET['r'])) {
            // The rewrite-free form carries the route in `r`. A path that tries to climb out of the
            // route space is refused outright rather than normalised: no legitimate route needs it.
            $requested = $_GET['r'];
            $path = str_contains($requested, '..') ? '/' : $requested;
        }

        return $path;
    }

    private static function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }

    /** @param array<string, mixed> $server @return array<string, string> */
    private static function collectHeaders(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
                $headers[$name] = (string) $value;
            }
        }

        return $headers;
    }

    /** @param array<string, mixed> $server */
    private static function clientIp(array $server): string
    {
        // Behind a proxy the client IP must be taken from a header the proxy sets.
        // Which header that is is a deployment fact (O-20); until it is known, the
        // direct connection address is used and forwarded headers are NOT trusted.
        $ip = (string) ($server['REMOTE_ADDR'] ?? '0.0.0.0');

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
    }

    public static function generateRequestId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        return $this->json ?? [];
    }

    public function isJson(): bool
    {
        return $this->json !== null;
    }

    /** Reads one input value from the JSON body, then the form body, then the query string. */
    public function input(string $key, mixed $default = null): mixed
    {
        foreach (['json', 'body', 'query'] as $source) {
            $bag = match ($source) {
                'json' => $this->json ?? [],
                'body' => $this->body,
                default => $this->query,
            };
            if (array_key_exists($key, $bag)) {
                return $bag[$key];
            }
        }

        return $default;
    }

    /**
     * Reads one value from the request body only: the JSON body, then the form body.
     *
     * The query string is deliberately excluded. `input()` merges all three sources, which is useful
     * for ordinary form handling but wrong wherever the *source* is part of the security decision - a
     * CSRF token taken from a URL would leak through logs, browser history and the Referer header of
     * any link on the page.
     */
    public function body(string $key, mixed $default = null): mixed
    {
        foreach ([$this->json ?? [], $this->body] as $bag) {
            if (array_key_exists($key, $bag)) {
                return $bag[$key];
            }
        }

        return $default;
    }

    /** Returns only the given keys, in a stable shape, for explicit validation. */
    public function only(string ...$keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->input($key);
        }

        return $out;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** @return array<string, string> */
    public function queryAll(): array
    {
        $out = [];
        foreach ($this->query as $key => $value) {
            $out[(string) $key] = is_scalar($value) ? (string) $value : '';
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function allInput(): array
    {
        return array_replace_recursive($this->query, $this->body, $this->json ?? []);
    }

    /** @return array<string, mixed> */
    public function files(): array
    {
        return $this->files;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    /** State-changing methods; used by CSRF and rate limiting (Phase 0 slice 2). */
    public function isStateChanging(): bool
    {
        return !in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }
}
