<?php
declare(strict_types=1);

namespace WireGuardManager;

/**
 * Request-level security guards: Host allow-list (anti DNS-rebinding),
 * security headers, and CSRF protection for every POST.
 */
class Security
{
    public const MANAGEMENT_HOST = '10.50.0.1';
    public const TLS_PORT = '5443';
    public const PLAIN_PORT = '5050';

    /**
     * True when the app is published through the TLS terminator (stunnel).
     * Set via WGM_TLS=1 in the systemd unit.
     */
    public static function isHttps(): bool
    {
        if (getenv('WGM_TLS') === '1') {
            return true;
        }
        return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }

    /**
     * Host header values the app answers to. Anything else is rejected,
     * which defeats DNS-rebinding attacks from malicious websites.
     */
    public static function allowedHosts(): array
    {
        $override = getenv('WGM_ALLOWED_HOSTS');
        if ($override !== false && trim($override) !== '') {
            return array_values(array_filter(array_map(
                static fn($h) => strtolower(trim($h)),
                explode(',', $override)
            )));
        }

        return [
            self::MANAGEMENT_HOST . ':' . self::TLS_PORT,
            self::MANAGEMENT_HOST . ':' . self::PLAIN_PORT,
            self::MANAGEMENT_HOST,
            '127.0.0.1:' . self::PLAIN_PORT,
            '127.0.0.1:' . self::TLS_PORT,
            '127.0.0.1',
            'localhost:' . self::PLAIN_PORT,
            'localhost:' . self::TLS_PORT,
            'localhost',
        ];
    }

    public static function allowedOrigins(): array
    {
        $scheme = self::isHttps() ? 'https' : 'http';
        return array_map(static fn($h) => $scheme . '://' . $h, self::allowedHosts());
    }

    public static function enforceHost(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
        if (!in_array($host, self::allowedHosts(), true)) {
            http_response_code(421);
            header('Content-Type: text/plain; charset=UTF-8');
            header('Cache-Control: no-store');
            echo "421 Misdirected Request\n";
            exit;
        }
    }

    public static function sendHeaders(): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; "
            . "style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; "
            . "font-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
        // Pages contain client configs / private keys: never cache.
        header('Cache-Control: no-store, max-age=0');
        header('Pragma: no-cache');
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function rotateCsrfToken(): void
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    /**
     * Every POST must carry a valid token, and (when the browser sends one)
     * an Origin/Referer belonging to this app.
     */
    public static function enforceCsrf(): void
    {
        if (PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }

        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if ($origin !== null && !in_array(strtolower(rtrim($origin, '/')), self::allowedOrigins(), true)) {
            self::reject('Cross-origin request blocked.');
        }

        if ($origin === null && !empty($_SERVER['HTTP_REFERER'])) {
            $ref = parse_url((string)$_SERVER['HTTP_REFERER']);
            $refOrigin = strtolower(($ref['scheme'] ?? '') . '://' . ($ref['host'] ?? '') . (isset($ref['port']) ? ':' . $ref['port'] : ''));
            if (!in_array($refOrigin, self::allowedOrigins(), true)) {
                self::reject('Cross-origin request blocked.');
            }
        }

        $sent = $_POST['csrf_token'] ?? '';
        $expected = $_SESSION['csrf_token'] ?? '';
        if (!is_string($sent) || !is_string($expected) || $expected === '' || !hash_equals($expected, $sent)) {
            self::reject('Your session token is missing or expired. Please reload the page and try again.');
        }
    }

    public static function clientIp(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }

    private static function reject(string $message): void
    {
        http_response_code(403);
        header('Content-Type: text/html; charset=UTF-8');
        $safe = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>Request blocked</title><link rel="stylesheet" href="/assets/css/app.css"></head>'
            . '<body><main class="app-main"><div class="main-container"><div class="card max-w-2xl">'
            . '<div class="card-header"><h1 class="card-title">Request blocked (403)</h1></div>'
            . '<div class="card-body"><p>' . $safe . '</p>'
            . '<div class="form-actions"><a class="btn btn-primary" href="/index.php">Back to dashboard</a></div>'
            . '</div></div></div></main></body></html>';
        exit;
    }
}
