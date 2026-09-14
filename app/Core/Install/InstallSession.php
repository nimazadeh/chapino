<?php

declare(strict_types=1);

namespace App\Core\Install;

/**
 * The session the installer uses - deliberately separate from the application's session.
 *
 * Why a second session type exists at all: the application's session layer reads its policy from
 * `config/config.php`, which does not exist until the installation succeeds. The installer therefore
 * cannot use it, and re-implementing "a session" carelessly is how security bugs get in. So the
 * policy is written down here, once, and it is the same policy the application uses - same cookie
 * rules, same strict mode, same CSRF contract - only without the configuration file:
 *
 *   * `HttpOnly` and `SameSite=Lax` cookies (a script must not read the session id; a cross-site
 *     POST must not carry it);
 *   * `Secure` whenever the request is already HTTPS;
 *   * strict mode on, so a session id the server never issued is not adopted (session fixation);
 *   * `use_only_cookies`, because a session id in a URL ends up in logs and referrers;
 *   * a CSRF token bound to the session, compared in constant time, required on every write.
 *
 * The install itself is not a secret, but it is a write to the filesystem and the database, and it
 * happens on a URL anybody can reach: the checks that treat it as a write are not optional.
 */
final class InstallSession
{
    private const AUTHORIZED = 'chapino_install_authorized';
    private const CSRF = 'chapino_install_csrf';

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');

        session_name('chapino_install');
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $this->isHttps(),
            // The installer may run from a subfolder: the cookie follows the deployment prefix, so a
            // second installation on the same host cannot be authorised by the first one's cookie.
            'path' => $this->cookiePath(),
        ]);

        // A session that cannot start must be visible, not silent: PHP returns false and warns, and an
        // installer that continued without a session would lose the CSRF token and the authorisation
        // between two requests - the operator would see "the token is wrong" for a correct token.
        // The usual cause on a shared host is session.save_path pointing at a directory PHP cannot
        // write, which is a one-line fix in the panel - so the message says that.
        if (session_start() === false) {
            throw new InstallException(
                'نشست PHP شروع نشد. مقدار session.save_path در php.ini (یا پنل هاست) باید به پوشه‌ای '
                . 'نوشتن‌پذیر اشاره کند.',
                'session_unavailable',
            );
        }
    }

    public function isAuthorized(): bool
    {
        return ($_SESSION[self::AUTHORIZED] ?? false) === true;
    }

    /** Called once, after the token from the filesystem has been accepted. */
    public function authorize(): void
    {
        // A new session id after the privilege change: a session that becomes authorised must not keep
        // the id it had while it was anonymous.
        session_regenerate_id(true);
        $_SESSION[self::AUTHORIZED] = true;
    }

    public function forget(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function csrfToken(): string
    {
        $token = $_SESSION[self::CSRF] ?? null;
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::CSRF] = $token;
        }

        return $token;
    }

    public function verifyCsrf(mixed $candidate): bool
    {
        $expected = $_SESSION[self::CSRF] ?? null;

        return is_string($candidate) && is_string($expected) && hash_equals($expected, $candidate);
    }

    private function isHttps(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));

        return ($https !== '' && $https !== 'off') || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    }

    /** The directory the installer script itself lives in, so a subfolder install keeps its own cookie. */
    private function cookiePath(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $directory = $script === '' ? '' : rtrim(dirname($script), '/');

        return $directory === '/' || $directory === '.' ? '/' : $directory . '/';
    }
}
