<?php
declare(strict_types=1);

namespace WireGuardManager;

/**
 * Hardened session handling: strict mode, HttpOnly + SameSite=Strict cookies,
 * Secure flag when served over TLS, idle and absolute timeouts.
 */
class Session
{
    public const IDLE_TIMEOUT = 1800;      // 30 minutes
    public const ABSOLUTE_TIMEOUT = 43200; // 12 hours
    public const COOKIE_NAME = 'WGMSESSID';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (PHP_SAPI === 'cli') {
            // CLI (tests / installer helpers): plain array, no cookies.
            if (!isset($_SESSION) || !is_array($_SESSION)) {
                $_SESSION = [];
            }
            return;
        }

        $secure = Security::isHttps();

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.cookie_secure', $secure ? '1' : '0');
        ini_set('session.gc_maxlifetime', (string)self::ABSOLUTE_TIMEOUT);

        session_name(self::COOKIE_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        session_start();

        $now = time();
        if (!empty($_SESSION['authenticated'])) {
            $idle = $now - (int)($_SESSION['last_activity'] ?? 0);
            $age = $now - (int)($_SESSION['auth_time'] ?? 0);
            if ($idle > self::IDLE_TIMEOUT || $age > self::ABSOLUTE_TIMEOUT) {
                self::reset();
                $_SESSION['flash_error'] = 'Your session expired. Please sign in again.';
            }
        }
        $_SESSION['last_activity'] = $now;
    }

    /**
     * Wipe all session data and issue a fresh session ID.
     */
    public static function reset(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION[$type === 'error' ? 'flash_error' : 'flash_success'] = $message;
    }
}
