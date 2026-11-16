<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Secure session handling + flash data + CSRF token.
 *
 * Flash data written during request N is readable during request N+1 only
 * (JSON/AJAX requests do not consume it, so background polling never
 * swallows a success message).
 */
final class Session
{
    private static bool $started = false;

    public static function start(bool $secure, bool $ageFlash = true): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        $cfg = (array) Config::get('security.session', []);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
        // Explicit GC: some distributions ship gc_probability=0, which would never clean storage/sessions.
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        ini_set('session.gc_maxlifetime', (string) (((int) ($cfg['absolute_hours'] ?? 10)) * 3600));

        $savePath = BASE_PATH . '/storage/sessions';
        if (is_dir($savePath) && is_writable($savePath)) {
            session_save_path($savePath);
        }

        $secureSetting = $cfg['secure_cookie'] ?? 'auto';
        $secureCookie = $secureSetting === 'auto' ? $secure : (bool) $secureSetting;

        session_name((string) ($cfg['name'] ?? 'medistock_sid'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => (Request::basePath() ?: '') . '/',
            'secure'   => $secureCookie,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        self::$started = true;

        if ($ageFlash) {
            $_SESSION['_flash_current'] = $_SESSION['_flash_next'] ?? [];
            $_SESSION['_flash_next'] = [];
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Store a value for the NEXT request. */
    public static function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash_next'][$key] = $value;
    }

    /** Read flash data from the previous request. */
    public static function getFlash(string $key, mixed $default = null): mixed
    {
        return $_SESSION['_flash_current'][$key] ?? $default;
    }

    /** Read flash data already queued for the next request. */
    public static function getNextFlash(string $key, mixed $default = null): mixed
    {
        return $_SESSION['_flash_next'][$key] ?? $default;
    }

    /** Keep current flash data for one more request (used when re-rendering). */
    public static function reflash(): void
    {
        $_SESSION['_flash_next'] = array_merge($_SESSION['_flash_current'] ?? [], $_SESSION['_flash_next'] ?? []);
    }

    /** New session ID; called on login and privilege change (prevents session fixation). */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        unset($_SESSION['_csrf']);
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000, 'path' => $params['path'], 'secure' => $params['secure'],
            'httponly' => true, 'samesite' => 'Lax',
        ]);
        session_destroy();
        self::$started = false;
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function validCsrf(?string $token): bool
    {
        return is_string($token) && !empty($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $token);
    }
}
