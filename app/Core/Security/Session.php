<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database\Connection;
use App\Core\Database\DatabaseException;
use App\Core\Logger;

/**
 * Server-side session state with an explicit policy.
 *
 * Why this class exists instead of "just call session_start()": the security rule requires
 * server-generated high-entropy identifiers, regeneration on privilege change, invalidation on
 * logout, and defined idle and absolute timeouts. PHP provides the storage, not that policy - and a
 * session that silently outlives its timeout is a defect nobody notices until it matters.
 *
 * What this adds on top of PHP sessions:
 *  - a private save path (shared hosts often keep every account's session files in one directory);
 *  - strict mode, cookie-only identifiers, HttpOnly, SameSite=Lax, and Secure when HTTPS is on;
 *  - one row in `sessions` per **authenticated** session, holding a SHA-256 of the identifier, so a
 *    database dump cannot be turned into hijacked sessions and so sessions can be revoked;
 *  - idle and absolute timeouts enforced against that row rather than against the client's cookie;
 *  - identifier regeneration on login and after a timeout, which closes session fixation.
 *
 * Deliberately lazy: an anonymous visitor does not get a database row. Only a session that carries a
 * privilege is tracked server-side, because that is the session that must be revocable - and because
 * on shared hosting one insert per visitor is a real cost for no security benefit.
 */
final class Session implements SessionStore
{
    public const KEY_USER_ID = 'user_id';
    public const KEY_ROLE = 'role';

    private const TOUCH_INTERVAL_SECONDS = 60;

    private bool $started = false;
    /** @var array<string, mixed>|null */
    private ?array $record = null;
    private bool $recordLoaded = false;

    public function __construct(
        private readonly Config $config,
        private readonly Connection $database,
        private readonly Logger $logger,
        private readonly bool $https = false,
    ) {
    }

    /** Starts (or adopts) the session. Idempotent: calling it twice starts nothing twice. */
    public function start(): void
    {
        if ($this->started) {
            return;
        }

        // Headers already sent: nothing below can work. PHP refuses to change session settings, and
        // session_start() fails, so the honest answer is one clear exception - not a screenful of
        // `ini_set` warnings followed by a session that silently did not start.
        if (session_status() !== PHP_SESSION_ACTIVE && headers_sent($file, $line)) {
            throw new SessionException(
                'نشست قابل شروع نیست چون خروجی پیش از شروع نشست ارسال شده است '
                . '(این پیام برای برنامه‌نویس است و باید گزارش شود)',
                'session_headers_sent',
            );
        }

        // PHP refuses to change session settings while a session is active, so configuration happens
        // only when we are the ones starting it. An already-active session is adopted instead:
        // in production that happens when `session.auto_start` is enabled on the host, which is
        // worth knowing about, because it means the host started a session before the application
        // could apply its own cookie policy.
        if (session_status() === PHP_SESSION_ACTIVE) {
            if ((int) ini_get('session.auto_start') === 1) {
                $this->logger->warning('session_auto_start_enabled', [
                    'note' => 'the host started the session before the application could apply its policy',
                ]);
            }
        } else {
            $this->configurePhpSession();
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (!@session_start()) {
                throw new SessionException(
                    'نشست کاربر ایجاد نشد. دسترسی نوشتن پوشه نشست‌ها و تنظیمات PHP را بررسی کنید.',
                    'session_start_failed',
                );
            }
        }

        $this->started = true;
        $this->record = $this->findRecord();
        $this->recordLoaded = true;
        $this->enforceTimeouts();
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    /** The session identifier. Never logged and never placed in a URL. */
    public function id(): string
    {
        $this->start();

        return (string) session_id();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();

        return $_SESSION[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    public function userId(): ?int
    {
        $value = $this->get(self::KEY_USER_ID);

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    public function role(): ?string
    {
        $value = $this->get(self::KEY_ROLE);

        return is_string($value) ? $value : null;
    }

    public function isAuthenticated(): bool
    {
        return $this->userId() !== null;
    }

    /**
     * Makes the session authenticated.
     *
     * The identifier is regenerated *before* the privilege is stored, so an identifier captured
     * before login cannot be reused after it (session fixation).
     */
    public function login(int $userId, string $role): void
    {
        $this->start();
        $this->regenerate();
        $_SESSION[self::KEY_USER_ID] = $userId;
        $_SESSION[self::KEY_ROLE] = $role;
        $this->writeRecord();
        $this->logger->info('session_login', ['user_id' => $userId, 'role' => $role]);
    }

    public function logout(): void
    {
        if (!$this->started) {
            $this->start();
        }

        $this->deleteRecord($this->hashOf((string) session_id()));
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $this->expireCookie();
        $this->started = false;
        $this->record = null;
        $this->logger->info('session_logout');
    }

    /** Regenerates the identifier and moves the server-side record to the new one. */
    public function regenerate(): void
    {
        $this->start();

        $previous = (string) session_id();
        if (!session_regenerate_id(true)) {
            throw new SessionException('تولید شناسه نشست جدید ممکن نشد.', 'session_regenerate_failed');
        }

        $this->deleteRecord($this->hashOf($previous));
    }

    /** @return array<string, mixed>|null the row that authorises this session, when there is one */
    private function findRecord(): ?array
    {
        // A row exists only for a signed-in user (see login()), so an anonymous visitor is not worth a
        // query - and skipping it has two real consequences: a plain page view stays database-free, and
        // an unmigrated or briefly unavailable database does not take the whole site down with a 500.
        // Browsing still works, and only the actions that genuinely need storage fail, with a message
        // that says what to do.
        if ($this->userId() === null) {
            return null;
        }

        $row = $this->database->first(
            'SELECT id, user_id, created_at, last_seen_at FROM sessions WHERE token_hash = ?',
            [$this->hashOf((string) session_id())],
        );

        return $row === null ? null : $row;
    }

    /**
     * Applies idle and absolute timeouts.
     *
     * A session whose row is gone is treated as anonymous: it is not trusted merely because the
     * browser still presents a cookie. PHP's strict mode independently refuses an identifier the
     * server never issued.
     */
    private function enforceTimeouts(): void
    {
        if ($this->record === null) {
            // No server-side record: either a brand-new anonymous session, or a session whose record
            // was revoked. Drop any leftover privilege either way.
            if ($this->userId() !== null) {
                $this->logger->info('session_revoked', ['reason' => 'record_missing']);
                $_SESSION = [];
            }

            return;
        }

        $idle = $this->config->int('security.session_idle_timeout', 3600);
        $absolute = $this->config->int('security.session_absolute_timeout', 86400);
        $now = Clock::now();

        $createdAt = strtotime((string) $this->record['created_at'] . ' UTC');
        $lastSeen = strtotime((string) $this->record['last_seen_at'] . ' UTC');

        $expiredByIdle = $idle > 0 && $lastSeen !== false && ($now - $lastSeen) > $idle;
        $expiredAbsolutely = $absolute > 0 && $createdAt !== false && ($now - $createdAt) > $absolute;

        if ($expiredByIdle || $expiredAbsolutely) {
            $this->logger->info('session_expired', ['reason' => $expiredByIdle ? 'idle' : 'absolute']);
            $this->deleteRecord($this->hashOf((string) session_id()));
            $this->record = null;
            $_SESSION = [];
            $this->regenerate();

            return;
        }

        $storedUser = $this->record['user_id'] === null ? null : (int) $this->record['user_id'];
        if ($storedUser !== $this->userId()) {
            // The row and the session data disagree: neither can be trusted, so both are dropped.
            $this->logger->info('session_revoked', ['reason' => 'record_mismatch']);
            $this->deleteRecord($this->hashOf((string) session_id()));
            $this->record = null;
            $_SESSION = [];

            return;
        }

        if ($lastSeen === false || ($now - $lastSeen) >= self::TOUCH_INTERVAL_SECONDS) {
            $this->touch($this->hashOf((string) session_id()), $now);
        }
    }

    /** Creates the row that authorises this session. Called on login. */
    private function writeRecord(): void
    {
        $hash = $this->hashOf((string) session_id());
        $now = Clock::now();
        $nowText = gmdate('Y-m-d H:i:s', $now);
        $absolute = max(60, $this->config->int('security.session_absolute_timeout', 86400));

        try {
            $this->database->insert('sessions', [
                'token_hash' => $hash,
                'user_id' => $this->userId(),
                'ip_address' => $this->clientIp(),
                'user_agent' => $this->userAgent(),
                'created_at' => $nowText,
                'last_seen_at' => $nowText,
                'expires_at' => gmdate('Y-m-d H:i:s', $now + $absolute),
            ]);
        } catch (DatabaseException $e) {
            if (!$e->isDuplicate()) {
                throw $e;
            }

            $this->touch($hash, $now);
        }

        $this->record = $this->findRecord();
    }

    private function touch(string $hash, int $now): void
    {
        $this->database->update(
            'sessions',
            [
                'last_seen_at' => gmdate('Y-m-d H:i:s', $now),
                'ip_address' => $this->clientIp(),
            ],
            ['token_hash' => $hash],
        );
    }

    private function deleteRecord(string $hash): void
    {
        if ($hash === '') {
            return;
        }

        $this->database->delete('sessions', ['token_hash' => $hash]);
    }

    /** The identifier is a bearer secret; the database stores only its hash. */
    private function hashOf(string $sessionId): string
    {
        return $sessionId === '' ? '' : hash('sha256', $sessionId);
    }

    private function configurePhpSession(): void
    {
        $savePath = $this->config->path('security.session_save_path', 'storage/sessions');
        if ($savePath !== '' && !is_dir($savePath)) {
            if (!@mkdir($savePath, 0700, true) && !is_dir($savePath)) {
                throw new SessionException(
                    'پوشه ذخیره نشست‌ها ساخته نشد. دسترسی نوشتن پوشه storage را بررسی کنید.',
                    'session_path_unwritable',
                );
            }
        }

        ini_set('session.use_strict_mode', '1');   // refuse an identifier the server never issued
        ini_set('session.use_only_cookies', '1');  // an identifier in the URL leaks through Referer
        ini_set('session.cookie_httponly', '1');   // not readable from JavaScript: limits XSS damage
        ini_set('session.cookie_samesite', 'Lax'); // first line of CSRF defence
        ini_set('session.cookie_secure', $this->https ? '1' : '0');
        ini_set('session.gc_maxlifetime', (string) max(3600, $this->config->int('security.session_absolute_timeout', 86400)));

        if ($savePath !== '') {
            session_save_path($savePath);
        }

        session_name($this->config->string('security.session_name', 'chapino_session'));
    }

    private function expireCookie(): void
    {
        // The expiry must carry the same attributes the cookie was created with, otherwise some
        // browsers keep the original cookie alive.
        if (!headers_sent()) {
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => $this->https,
            ]);
        }
    }

    private function clientIp(): ?string
    {
        // REMOTE_ADDR only: X-Forwarded-For is client-controlled unless a trusted proxy is configured.
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        return is_string($ip) && $ip !== '' ? substr($ip, 0, 45) : null;
    }

    private function userAgent(): ?string
    {
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        return is_string($agent) && $agent !== '' ? mb_substr($agent, 0, 191) : null;
    }
}
