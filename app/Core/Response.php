<?php

declare(strict_types=1);

namespace App\Core;

/**
 * An outgoing HTTP response (JSON for the API, HTML for pages).
 */
final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        return new self(
            $status,
            (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            array_merge(['Content-Type' => 'application/json; charset=utf-8'], $headers),
        );
    }

    /** @param array<string, mixed> $data */
    public static function ok(array $data = [], int $status = 200): self
    {
        return self::json(['ok' => true, 'data' => $data], $status);
    }

    /** @param array<string, string> $fields */
    public static function error(string $code, string $message, int $status, array $fields = [], string $requestId = ''): self
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== []) {
            $error['fields'] = $fields;
        }
        if ($requestId !== '') {
            $error['request_id'] = $requestId;
        }

        return self::json(['ok' => false, 'error' => $error], $status);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, $html, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self($status, '', ['Location' => $location]);
    }

    public function withHeader(string $name, string $value): self
    {
        $headers = $this->headers;
        $headers[$name] = $value;

        return new self($this->status, $this->body, $headers);
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        if ($this->body !== '') {
            echo $this->body;
        }
    }
}
