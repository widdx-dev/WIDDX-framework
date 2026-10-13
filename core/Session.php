<?php

declare(strict_types=1);

namespace Widdx;

/**
 * Session manager with flash data support.
 *
 * Flash data survives exactly one redirect — perfect for
 * validation errors and success messages.
 *
 * Usage (all methods are static):
 *   Session::put('key', 'value');
 *   Session::flash('success', 'Saved!');
 *
 * start() opens the session once per request and sweeps flash data once
 * (guarded by session_status()). The flash sweep must never run again in
 * the same request, otherwise freshly flashed data (e.g. validation
 * errors) would be aged out before the user ever sees it.
 *
 * SessionInterface documents the driver contract for custom back-ends
 * (e.g. DatabaseSession) that implement it as instance methods and are
 * resolved through the container.
 */
class Session
{
    /**
     * Ensure the session is started for this request (no-op if already
     * active). The flash sweep runs exactly once, right after opening.
     */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return; // Already opened this request — do NOT sweep again.
        }
        if (headers_sent()) {
            return; // Too late to send the session cookie.
        }
        // Hardened cookie: HttpOnly + SameSite=Lax, Secure when on HTTPS.
        // (PHP defaults cookie_httponly to 0, so set it explicitly.)
        $secure = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? null) === '443';
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        static::sweepFlash();
    }

    /** Move flash data lifecycle forward: delete old, age new */
    protected static function sweepFlash(): void
    {
        foreach ($_SESSION['_flash_old'] ?? [] as $key => $_) {
            unset($_SESSION[$key]);
        }
        $_SESSION['_flash_old'] = $_SESSION['_flash_new'] ?? [];
        $_SESSION['_flash_new'] = [];
    }

    /** Get a value */
    public static function get(string $key, mixed $default = null): mixed
    {
        static::start();
        return $_SESSION[$key] ?? $default;
    }

    /** Store a value */
    public static function put(string $key, mixed $value): void
    {
        static::start();
        $_SESSION[$key] = $value;
    }

    /** Check existence */
    public static function has(string $key): bool
    {
        static::start();
        return isset($_SESSION[$key]);
    }

    /** Remove a value */
    public static function forget(string $key): void
    {
        static::start();
        unset($_SESSION[$key]);
    }

    /** Remove everything */
    public static function flush(): void
    {
        static::start();
        $_SESSION = [];
    }

    /** Store flash data (available on next request only) */
    public static function flash(string $key, mixed $value): void
    {
        static::start();
        $_SESSION[$key] = $value;
        $_SESSION['_flash_new'][$key] = true;
    }

    /** Get all session data */
    public static function all(): array
    {
        static::start();
        $data = $_SESSION;
        unset($data['_flash_old'], $data['_flash_new']);
        return $data;
    }

    /** Regenerate session ID */
    public static function regenerate(): void
    {
        static::start();
        session_regenerate_id(true);
    }

    /** Destroy session */
    public static function destroy(): void
    {
        static::start();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
    }
}
