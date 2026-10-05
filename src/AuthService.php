<?php
declare(strict_types=1);

namespace WireGuardManager;

class AuthService
{
    public const DEFAULT_PASSWORD = '[REDACTED]';

    public static function isAuthenticated(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        return !empty($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
    }

    public static function getAdminPassword(): string
    {
        $env = getenv('ADMIN_PASSWORD');
        if ($env !== false && $env !== '') {
            return $env;
        }
        return self::DEFAULT_PASSWORD;
    }

    public static function verifyPassword(string $password): bool
    {
        return hash_equals(self::getAdminPassword(), $password);
    }

    public static function login(string $password): bool
    {
        if (self::verifyPassword($password)) {
            if (session_status() === PHP_SESSION_NONE) {
                @session_start();
            }
            session_regenerate_id(true);
            $_SESSION['authenticated'] = true;
            $_SESSION['auth_time'] = time();
            return true;
        }
        return false;
    }

    public static function logout(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        @session_destroy();
    }

    public static function requireAuth(): void
    {
        if (!self::isAuthenticated()) {
            $uri = $_SERVER['REQUEST_URI'] ?? '/index.php';
            // Protect against open redirect
            if (!str_starts_with($uri, '/') || str_starts_with($uri, '//')) {
                $uri = '/index.php';
            }
            header('Location: /login.php?return=' . urlencode($uri));
            exit;
        }
    }
}
