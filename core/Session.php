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
        // Fail-closed session mode: an ID the server has never issued is
        // rejected (blocks session fixation), and the ID only travels in
        // cookies — never in URLs where logs/referrers can pick it up.
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        // Keep session files inside the project when no explicit path is
        // configured: the shared /tmp collides between apps and many shared
        // hosts expose no writable temp elsewhere.
        $custom = (string) (getenv('WIDDX_SESSION_PATH') ?: '');
        if ($custom !== '') {
            if (is_dir($custom) && is_writable($custom)) {
                session_save_path($custom);
            }
        } else {
            $inProject = (defined('WIDDX_BASE_PATH') ? WIDDX_BASE_PATH : dirname(__DIR__)) . '/storage/sessions';
            if (!is_dir($inProject)) {
                @mkdir($inProject, 0775, true);
            }
            if (is_dir($inProject) && is_writable($inProject)) {
                session_save_path($inProject);
            }
        }

        // Hardened cookie: HttpOnly + SameSite=Lax, Secure when on HTTPS —
        // including termination behind a TRUSTED proxy that tells us so.
        // (PHP defaults cookie_httponly to 0, so set it explicitly.)
        $secure = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? null) === '443'
            || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
                && Request::isTrustedPeer());
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
        $f = &$_SESSION['_flash'];
        foreach ($f['old'] ?? [] as $key => $_) {
            unset($f['data'][$key]);
        }
        $f['old'] = $f['new'] ?? [];
        $f['new'] = [];
        unset($f);
    }

    /** Get a value (flash data included — it lives under _flash now) */
    public static function get(string $key, mixed $default = null): mixed
    {
        static::start();
        if (array_key_exists($key, $_SESSION)) {
            return $_SESSION[$key];
        }
        return $_SESSION['_flash']['data'][$key] ?? $default;
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
        return array_key_exists($key, $_SESSION)
            || isset($_SESSION['_flash']['data'][$key]);
    }

    /** Remove a value (normal and flash storage) */
    public static function forget(string $key): void
    {
        static::start();
        unset($_SESSION[$key], $_SESSION['_flash']['data'][$key], $_SESSION['_flash']['new'][$key], $_SESSION['_flash']['old'][$key]);
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
        $_SESSION['_flash']['data'][$key] = $value;
        $_SESSION['_flash']['new'][$key] = true;
    }

    /** Get all session data (flash entries merged, internals hidden) */
    public static function all(): array
    {
        static::start();
        $data = $_SESSION;
        $flash = $data['_flash']['data'] ?? [];
        unset($data['_flash']);
        foreach ($flash as $k => $v) {
            $data[$k] ??= $v;
        }
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
