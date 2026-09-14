<?php

declare(strict_types=1);

use App\Core\Autoloader;
use App\Core\Clock;
use App\Core\Install\InstallException;
use App\Core\Install\InstallSession;
use App\Core\Install\WebInstaller;

/**
 * The web installer: installation on a host that gives a panel and a browser, and no shell.
 *
 * This file is a script, not a route, and that is not a detail: before an installation exists there
 * is no `config/config.php`, so the framework cannot boot (`Config::load()` refuses to run) and no
 * route can answer. It therefore boots only what it needs - the autoloader, the clock and the install
 * classes - and renders its own page from `app/Views/install.php`, which reuses the real stylesheets.
 *
 * The security model, in one paragraph: the page is only reachable while no installation exists (it
 * answers 404 afterwards), and doing anything with it requires reading a one-time token file from the
 * filesystem (`storage/install-token.php`) - proof of panel access, which is the same thing as owning
 * the host. Failed attempts are counted per client, every write needs a session-bound CSRF token, and
 * the token is deleted when it has done its job. What it never does is print the token.
 *
 * Documented in the runbook: docs/engineering/runbooks/install-and-deploy.md, section 13.
 */

$root = dirname(__DIR__);
if (!defined('APP_ROOT')) {
    define('APP_ROOT', $root);
}
require_once $root . '/app/Core/Autoloader.php';
Autoloader::register($root . '/app', 'App\\');
Clock::init();

// The script's own location, taken from a server fact and never from the request URI: a form action
// built from a client-controlled value is an open redirect waiting to be reported.
$scriptDirectory = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/install.php'))), '/');
$basePath = in_array($scriptDirectory, ['', '/', '.'], true) ? '' : $scriptDirectory;

$installer = new WebInstaller($root, 'storage');

/**
 * Renders one screen and stops.
 *
 * @param array<string, mixed> $data
 */
$render = static function (string $screen, array $data, int $status = 200) use ($root, $basePath): void {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: same-origin');

    $data['base'] = $basePath;

    // The template reads $screen and $data; both are local to this include on purpose.
    include $root . '/app/Views/install.php';
    exit;
};

if ($installer->isInstalled()) {
    // Self-disable: an installed site must not expose an installer, and 404 (not 403) is what an
    // attacker learns nothing from.
    $render('disabled', [], 404);
}

$session = new InstallSession();
try {
    $session->start();
} catch (InstallException $exception) {
    $render('error', [
        'message' => $exception->getMessage(),
        'code' => $exception->reason,
        'form_action' => $basePath . '/install.php',
    ], 500);
}

$token = $installer->token();
$attempts = $installer->attempts();
$client = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = $method === 'POST' ? (string) ($_POST['action'] ?? '') : '';

$formAction = $basePath . '/install.php';

/** The values the form shows back: everything except the password (see below). */
$submitted = static fn (string $field, string $fallback = ''): string => is_string($_POST[$field] ?? null)
    ? trim((string) $_POST[$field])
    : $fallback;

if ($action === 'authorize') {
    if ($attempts->isLockedOut($client)) {
        $render('gate', [
            'token_path' => $token->displayPath(),
            'token_created' => false,
            'csrf' => $session->csrfToken(),
            'action' => $formAction,
            'error' => 'تلاش‌های ناموفق زیاد بوده است. چند دقیقه بعد دوباره امتحان کنید.',
        ], 429);
    }

    if (!$session->verifyCsrf($_POST['_csrf'] ?? null)) {
        $render('gate', [
            'token_path' => $token->displayPath(),
            'token_created' => false,
            'csrf' => $session->csrfToken(),
            'action' => $formAction,
            'error' => 'این درخواست معتبر نبود. صفحه را از نو باز کنید و دوباره توکن را وارد کنید.',
        ], 400);
    }

    if ($token->matches($_POST['token'] ?? null)) {
        $attempts->clear($client);
        $session->authorize();

        // Redirect after authorising, so a refresh does not repeat the POST.
        header('Location: ' . $formAction, true, 303);
        exit;
    }

    $attempts->recordFailure($client);

    $render('gate', [
        'token_path' => $token->displayPath(),
        'token_created' => false,
        'csrf' => $session->csrfToken(),
        'action' => $formAction,
        'error' => 'توکن درست نیست. مقدار داخل فایل توکن را کامل و بدون فاصله اضافه کپی کنید.',
    ], 401);
}

$values = [
    'driver' => $submitted('driver', 'mysql'),
    'host' => $submitted('host', 'localhost'),
    'port' => $submitted('port', '3306'),
    'name' => $submitted('name'),
    'user' => $submitted('user'),
    // Never echoed back into the page: a password that appears in HTML ends up in screenshots, in
    // browser history and in support tickets. The operator retypes it if the form comes back.
    'password' => '',
    'url' => $submitted('url'),
];

if ($action === 'install') {
    if (!$session->isAuthorized()) {
        $render('gate', [
            'token_path' => $token->displayPath(),
            'token_created' => false,
            'csrf' => $session->csrfToken(),
            'action' => $formAction,
            'error' => 'نشست شما معتبر نیست. توکن را دوباره وارد کنید.',
        ], 401);
    }

    if (!$session->verifyCsrf($_POST['_csrf'] ?? null)) {
        $render('error', [
            'message' => 'این درخواست معتبر نبود. فرم را از نو باز کنید و دوباره نصب را شروع کنید.',
            'code' => 'csrf_failed',
            'form_action' => $formAction,
        ], 400);
    }

    try {
        $result = $installer->install([
            'driver' => $values['driver'],
            'host' => $values['host'],
            'port' => $values['port'],
            'name' => $values['name'],
            'user' => $values['user'],
            'password' => (string) ($_POST['password'] ?? ''),
            'url' => $values['url'],
        ]);

        // Single-use, as designed: the token has done its job, and the page disables itself because
        // config/config.php now exists.
        $token->consume();
        $session->forget();

        $render('success', [
            'server_version' => $result['server_version'],
            'config' => str_replace($root . '/', '', $result['config']),
            'migrations' => $result['migrations'],
            'seeded' => $result['seeded'],
            'app_root' => $root,
            'php_binary' => PHP_BINARY !== '' ? PHP_BINARY : 'php',
        ]);
    } catch (InstallException $exception) {
        $render('error', [
            'message' => $exception->getMessage(),
            'code' => $exception->reason,
            'form_action' => $formAction,
        ], 400);
    } catch (Throwable $exception) {
        // Anything else is a defect, not an operator error: the operator gets the fact, the server log
        // gets the detail (never the credentials).
        error_log('chapino installer: ' . $exception::class . ': ' . $exception->getMessage());
        $render('error', [
            'message' => 'خطای پیش‌بینی‌نشده در نصب. لاگ سرور را ببینید (رمزها در این پیام نیستند).',
            'code' => 'unexpected',
            'form_action' => $formAction,
        ], 500);
    }
}

// GET: either the token gate, or the install form when this session is already authorised.
if ($session->isAuthorized()) {
    $overrides = [
        'storage' => ['path' => $installer->storagePath()],
        'database' => ['driver' => $values['driver'] === 'sqlite' ? 'sqlite' : 'mysql'],
    ];

    $render('form', [
        'checks' => $installer->checks($overrides),
        'summary' => $installer->summary($overrides),
        'values' => $values,
        'errors' => [],
        'csrf' => $session->csrfToken(),
        'action' => $formAction,
    ]);
}

$created = false;
if (!$token->exists()) {
    try {
        $token->create();
        $created = true;
    } catch (InstallException $exception) {
        $render('error', [
            'message' => $exception->getMessage(),
            'code' => $exception->code,
            'form_action' => $formAction,
        ], 500);
    }
}

if ($attempts->isLockedOut($client)) {
    $render('gate', [
        'token_path' => $token->displayPath(),
        'token_created' => $created,
        'csrf' => $session->csrfToken(),
        'action' => $formAction,
        'error' => 'تلاش‌های ناموفق زیاد بوده است. چند دقیقه بعد دوباره امتحان کنید.',
    ], 429);
}

$render('gate', [
    'token_path' => $token->displayPath(),
    'token_created' => $created,
    'csrf' => $session->csrfToken(),
    'action' => $formAction,
    'error' => '',
]);
