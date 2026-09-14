<?php

declare(strict_types=1);

/**
 * Installation self-check.
 *
 * Answers one question before anything else is trusted: "does this installation serve the
 * application correctly?" It touches no data and changes nothing.
 *
 *     php bin/smoke.php
 *     php bin/smoke.php /api/health /some/other/path
 *     php bin/smoke.php --config=/path/to/config.php
 *
 * How the check works, and why:
 *   1. The REAL front controller (public/index.php) is included once, with superglobals set
 *      exactly as a web server sets them, and the HTTP status is read from the SAPI. This
 *      proves the file a web server actually executes is wired correctly.
 *   2. Every further path is dispatched through the same Application object and the status is
 *      read from the Response value. Reading the status from the SAPI again would be wrong:
 *      once this script has printed anything, PHP refuses to set response headers, so the
 *      SAPI value would silently stay 200 - a check that cannot fail is not a check.
 */

$root = dirname(__DIR__);

$arguments = array_slice($argv, 1);
$paths = [];
foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--config=')) {
        // An explicit configuration path is passed through the process environment, because
        // environment variables are not reliably visible to PHP on shared hosting.
        putenv('CHAPINO_CONFIG=' . substr($argument, 9));
        continue;
    }
    $paths[] = $argument;
}

if ($paths === []) {
    $paths = ['/', '/api/health', '/api/no-such-route'];
}

$app = require $root . '/app/bootstrap.php';

$expectedStatus = [
    '/' => 200,
    '/api/health' => 200,
    '/api/no-such-route' => 404,
];

$failures = 0;

// --- 1. The real front controller, as the web server runs it -------------------------
$firstPath = array_shift($paths);
$originalServer = $_SERVER;
$originalGet = $_GET;

$_SERVER = [
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI' => $firstPath,
    'SCRIPT_NAME' => '/index.php',
    'REMOTE_ADDR' => '127.0.0.1',
    'HTTP_ACCEPT' => 'application/json',
];
$_GET = [];

ob_start();
try {
    require $root . '/public/index.php';
    $frontBody = (string) ob_get_clean();
    $frontStatus = (int) http_response_code();
} catch (\Throwable $e) {
    ob_end_clean();
    $frontBody = '';
    $frontStatus = 0;
    fwrite(STDERR, sprintf("FAIL  front controller threw %s: %s\n", $e::class, $e->getMessage()));
    $failures++;
} finally {
    $_SERVER = $originalServer;
    $_GET = $originalGet;
}

if ($frontStatus !== 0) {
    $expected = $expectedStatus[$firstPath] ?? null;
    $ok = $expected === null || $frontStatus === $expected;
    if (!$ok) {
        $failures++;
    }
    printf(
        "%s  front controller (public/index.php) %-22s status=%d%s  bytes=%d\n",
        $ok ? 'PASS' : 'FAIL',
        $firstPath,
        $frontStatus,
        $expected !== null ? " (expected {$expected})" : '',
        strlen($frontBody),
    );
    print_hint($frontStatus, $frontBody);
}

// --- 2. Every remaining path through the same application ----------------------------
foreach ($paths as $path) {
    try {
        $response = $app->handle(App\Core\Request::create('GET', $path));
    } catch (\Throwable $e) {
        $failures++;
        fwrite(STDERR, sprintf("FAIL  %-34s threw %s: %s\n", $path, $e::class, $e->getMessage()));
        continue;
    }

    $expected = $expectedStatus[$path] ?? null;
    $ok = $expected === null || $response->status === $expected;
    if (!$ok) {
        $failures++;
    }

    printf(
        "%s  %-34s status=%d%s  bytes=%d\n",
        $ok ? 'PASS' : 'FAIL',
        $path,
        $response->status,
        $expected !== null ? " (expected {$expected})" : '',
        strlen($response->body),
    );
    print_hint($response->status, $response->body);
}

// --- 3. The database and its migrations ----------------------------------------------
// Checked after the HTTP checks so that reading a status from the SAPI above is unaffected.
// These checks are stated as facts, not assumed: an installation whose migrations are pending
// will fail in production at the first query, so it must fail here first.
$databaseReported = false;
try {
    $config = $app->config();
    $connection = $app->database();

    $version = $connection->isSqlite()
        ? 'SQLite ' . (string) $connection->scalar('SELECT sqlite_version()')
        : (string) $connection->scalar('SELECT VERSION()');
    printf("%s  %-34s driver=%s، %s\n", 'PASS', 'اتصال پایگاه‌داده', $connection->driver(), $version);

    $schema = new App\Core\Database\Schema(
        $connection,
        $config->string('database.charset', 'utf8mb4'),
        $config->string('database.collation', 'utf8mb4_unicode_ci'),
    );
    $status = (new App\Core\Database\Migrator($connection, $schema, $root . '/database/migrations'))->status();
    $pending = $status['pending'];

    if ($pending === []) {
        printf("%s  %-34s %d اعمال‌شده، 0 در انتظار\n", 'PASS', 'مهاجرت‌های پایگاه‌داده', count($status['applied']));
    } else {
        $failures++;
        printf("%s  %-34s %d در انتظار: %s\n", 'FAIL', 'مهاجرت‌های پایگاه‌داده', count($pending), implode(', ', $pending));
        echo "      راهنما: php bin/migrate.php را اجرا کنید تا نصب کامل شود.\n";
    }
    $databaseReported = true;
} catch (App\Core\Database\DatabaseException $e) {
    $failures++;
    printf("%s  %-34s %s\n", 'FAIL', 'اتصال پایگاه‌داده', $e->getMessage());
    if ($e->isSetupProblem()) {
        echo "      راهنما: php bin/check-requirements.php را اجرا کنید تا وضعیت محیط و پیکربندی را ببینید.\n";
    }
} catch (Throwable $e) {
    // Includes a missing configuration: reported as a failure with a hint, never silently skipped.
    $failures++;
    printf("%s  %-34s %s\n", 'FAIL', 'پایگاه‌داده', $e->getMessage());
    echo "      راهنما: اگر نصب انجام نشده است، php bin/install.php --driver=sqlite را اجرا کنید.\n";
}

printf("\n%d check(s) failed\n", $failures);

exit($failures === 0 ? 0 : 1);

/** Explains an unconfigured or broken installation instead of only reporting a failure. */
function print_hint(int $status, string $body): void
{
    if ($status === 503 && str_contains($body, 'config')) {
        echo "      راهنما: فایل config/config.example.php را به config/config.php کپی کنید و مقادیر را پر کنید.\n";
    }

    $preview = trim((string) preg_replace('/\s+/u', ' ', $body));
    if ($preview !== '') {
        $limit = 150;
        echo '      body: ' . (mb_strlen($preview) > $limit ? mb_substr($preview, 0, $limit) . '…' : $preview) . "\n";
    }
}
