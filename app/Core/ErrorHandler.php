<?php

declare(strict_types=1);

namespace App\Core;

/**
 * One central place where every failure becomes an honest, safe response.
 *
 * Rules enforced here (see the security rule and the reporting rule):
 *  - a client sees a Persian message and a request id, never a stack trace or a path
 *  - the full detail goes to the log
 *  - a warning or notice is logged, never hidden
 *  - errors are never silenced to make a symptom disappear
 */
final class ErrorHandler
{
    public function __construct(
        private readonly Logger $logger,
        private readonly bool $debug = false,
    ) {
    }

    public function register(): void
    {
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleUncaught']);
        register_shutdown_function([$this, 'handleShutdown']);
    }

    public function handleError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        if ((error_reporting() & $severity) === 0) {
            return false;
        }

        $this->logger->warning('php_error', [
            'severity' => $severity,
            'message' => $message,
            'file' => $file,
            'line' => $line,
        ]);

        return false; // let PHP's normal handling continue; never swallow
    }

    public function handleUncaught(\Throwable $e): void
    {
        $this->render($this->errorResponse($e));
    }

    public function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        $this->logger->error('fatal_error', [
            'message' => $error['message'],
            'file' => $error['file'],
            'line' => $error['line'],
        ]);

        $this->render(Response::error(
            'server_error',
            'خطای غیرمنتظره‌ای رخ داد. لطفاً بعداً دوباره تلاش کنید.',
            500,
        ));
    }

    /** Converts any throwable into a safe response. */
    public function errorResponse(\Throwable $e, string $requestId = ''): Response
    {
        if ($e instanceof HttpException) {
            $this->logClientError($e);

            return Response::error($e->errorCode, $e->getMessage(), $e->status, $e->fields, $requestId);
        }

        if ($e instanceof ConfigurationException) {
            $this->logger->error('configuration_error', ['code' => $e->errorCode, 'message' => $e->getMessage()]);

            return Response::error('config_error', $e->getMessage(), 503, [], $requestId);
        }

        if ($e instanceof \App\Core\Database\DatabaseException && $e->isSetupProblem()) {
            // The installation is not usable yet: answer 503 with the actionable Persian message
            // written by the database layer, and keep the driver detail in the log only.
            $this->logger->error('database_setup_error', ['code' => $e->errorCode, 'message' => $e->getMessage()]);

            return Response::error('setup_required', $e->getMessage(), 503, [], $requestId);
        }

        $this->logger->error('unhandled_exception', [
            'type' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 12),
        ]);

        $message = 'خطای غیرمنتظره‌ای رخ داد. لطفاً بعداً دوباره تلاش کنید.';
        if ($this->debug) {
            // Debug mode may include detail, but only when configuration explicitly asks for it.
            $message .= ' [' . $e::class . ': ' . $e->getMessage() . ']';
        }

        return Response::error('server_error', $message, 500, [], $requestId);
    }

    private function logClientError(HttpException $e): void
    {
        $context = ['status' => $e->status, 'code' => $e->errorCode];
        if ($e->status >= 500) {
            $this->logger->error('request_failed', $context);

            return;
        }
        $this->logger->info('request_rejected', $context);
    }

    private function render(Response $response): void
    {
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            $response->send();

            return;
        }

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $response->body . "\n");
        }
    }
}
