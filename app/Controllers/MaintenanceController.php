<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Application;
use App\Core\Database\Migrator;
use App\Core\Database\Schema;
use App\Core\Database\Seeder;
use App\Core\Install\AttemptLimiter;
use App\Core\Install\InstallToken;
use App\Core\Install\RequirementsChecker;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;

/**
 * The operator's update page: migrations and default settings on a host with no shell.
 *
 * Why it is not development tooling and therefore not 404 in production: on a shared host, applying a
 * new migration is exactly what `php bin/migrate.php` does for someone with a terminal, and the owner
 * has no terminal. Disabling it in production would mean "deploy the update by asking the hosting
 * support to run a command".
 *
 * Authorisation is the same idea as the installer's: a one-time token in `storage/`, which only
 * someone with panel access can read. That is the whole gate, and it is deliberate - possession of the
 * filesystem is already possession of the site. On top of it: a session-bound CSRF token (required by
 * the middleware for every write), per-client attempt counting, and a single-use token that is deleted
 * when the work is done.
 *
 * Every action is logged: this page changes the shape of a live installation, and "who ran the
 * migrations and when" must be answerable afterwards.
 */
final class MaintenanceController
{
    private const AUTHORIZED = 'maintenance.authorized';

    public function __construct(private readonly Application $app)
    {
    }

    /** GET /maintenance */
    public function show(Request $request): Response
    {
        return $this->page($request, [], '');
    }

    /** POST /maintenance */
    public function submit(Request $request): Response
    {
        $action = (string) $request->input('action', '');
        $token = $this->token();
        $attempts = $this->attempts();
        $session = $this->app->session();

        if ($action === 'authorize') {
            $client = $request->clientIp;
            if ($attempts->isLockedOut($client)) {
                return $this->page($request, [], 'تلاشهای ناموفق زیاد بوده است. چند دقیقه بعد دوباره امتحان کنید.', 429);
            }

            if (!$token->matches($request->input('token'))) {
                $attempts->recordFailure($client);
                $this->app->logger()->warning('maintenance: wrong token', ['ip' => $client]);

                return $this->page($request, [], 'توکن درست نیست. مقدار داخل فایل توکن را کامل کپی کنید.', 401);
            }

            $attempts->clear($client);
            $session->put(self::AUTHORIZED, true);
            $this->app->logger()->info('maintenance: authorized', ['ip' => $client]);

            return $this->page($request, ['notice' => 'دسترسی تأیید شد.'], '');
        }

        if (!$this->isAuthorized()) {
            return $this->page($request, [], 'نشست شما معتبر نیست. توکن را دوباره وارد کنید.', 401);
        }

        try {
            switch ($action) {
                case 'migrate':
                    $applied = $this->migrator()->up();
                    $token->consume();
                    $session->forget(self::AUTHORIZED);
                    $this->app->logger()->info('maintenance: migrations applied', ['count' => count($applied)]);

                    return $this->page($request, [
                        'locked' => true,
                        'notice' => $applied === []
                            ? 'مهاجرت در انتظاری نبود؛ چیزی تغییر نکرد.'
                            : count($applied) . ' مهاجرت اجرا شد: ' . implode('، ', $applied)
                                . ' — توکن مصرف شد و صفحه قفل شد.',
                    ]);

                case 'seed':
                    $result = (new Seeder(
                        $this->app->database(),
                        $this->app->root() . '/database/seeds',
                    ))->run();
                    $this->app->logger()->info('maintenance: defaults applied', ['added' => count($result['added'])]);

                    return $this->page($request, [
                        'notice' => count($result['added']) . ' تنظیم افزوده شد، '
                            . count($result['kept']) . ' مورد دستنخورده ماند.',
                    ]);

                case 'lock':
                    $token->consume();
                    $session->forget(self::AUTHORIZED);
                    $this->app->logger()->info('maintenance: locked');

                    return $this->page($request, [
                        'locked' => true,
                        'notice' => 'توکن مصرف شد و صفحه قفل شد. برای کار بعدی، این صفحه را از نو باز کنید تا توکن تازه‌ای ساخته شود.',
                    ]);
            }
        } catch (\Throwable $exception) {
            $this->app->logger()->error('maintenance: action failed', [
                'action' => $action,
                'exception' => $exception::class,
            ]);

            return $this->page(
                $request,
                [],
                'اجرای این کار ناموفق بود: ' . $exception->getMessage(),
                500,
            );
        }

        return $this->page($request, [], 'درخواست ناشناخته.', 400);
    }

    private function isAuthorized(): bool
    {
        return $this->app->session()->get(self::AUTHORIZED) === true;
    }

    private function token(): InstallToken
    {
        return new InstallToken($this->app->root(), $this->storagePath());
    }

    private function attempts(): AttemptLimiter
    {
        return new AttemptLimiter($this->app->root(), $this->storagePath());
    }

    private function storagePath(): string
    {
        return (string) $this->app->config()->get('storage.path', 'storage');
    }

    private function migrator(): Migrator
    {
        $connection = $this->app->database();

        return new Migrator(
            $connection,
            new Schema(
                $connection,
                (string) $this->app->config()->get('database.charset', 'utf8mb4'),
                (string) $this->app->config()->get('database.collation', 'utf8mb4_unicode_ci'),
            ),
            $this->app->root() . '/database/migrations',
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function page(Request $request, array $data, string $error = '', int $status = 200): Response
    {
        $token = $this->token();
        $authorized = $this->isAuthorized();

        // The token file is created on demand, exactly like the installer's: the operator reads it
        // through the panel's file manager. It is never printed here - that would authorise anyone
        // who can reach the URL, which is the whole thing this gate exists to prevent.
        // A token is prepared when the page is opened - and deliberately not when an action has just
        // consumed one: the response to "run the migrations" must not quietly create the next token,
        // or the operator would never see that the page is locked again.
        $created = false;
        if (!$authorized && !$token->exists() && ($data['locked'] ?? false) !== true) {
            $created = true;
            $token->create();
        }

        $status_report = null;
        if ($authorized) {
            $connection = $this->app->database();
            $migrator = $this->migrator();
            $status_report = $migrator->status();
            $settings = new Settings($connection);
            $checker = new RequirementsChecker($this->app->root(), $this->app->config());

            $data['migration_status'] = $status_report;
            $data['settings'] = $settings->all();
            $data['requirements'] = $checker->summary();
        }

        $view = $this->app->view();
        $content = $view->render('maintenance', $data + [
            'authorized' => $authorized,
            'token_path' => $token->displayPath(),
            'token_created' => $created,
            'csrf' => $this->app->csrf()->token(),
            'error' => $error,
            'base' => $request->basePath(),
            'storage_path' => $this->storagePath(),
        ]);

        return Response::html($view->render('layouts/base', [
            'title' => 'نگهداری',
            'base' => $request->basePath(),
            'csrfToken' => $this->app->csrf()->token(),
            'content' => $content,
        ]), $status);
    }
}
