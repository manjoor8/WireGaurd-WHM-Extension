<?php
declare(strict_types=1);

namespace WireGuardManager;

use PDO;
use InvalidArgumentException;

/**
 * Administrator authentication backed by a bcrypt hash stored in SQLite (admin_auth).
 * No password is ever hardcoded; if none is configured the app fails closed.
 */
class AuthService
{
    public const MIN_LENGTH = 12;
    public const MAX_BYTES = 72;          // bcrypt ignores bytes beyond 72
    public const MAX_FAILURES = 5;        // failures allowed inside the window
    public const WINDOW_SECONDS = 900;    // 15 minutes
    public const LOCKOUT_SECONDS = 900;   // 15 minutes
    private const FAILURE_DELAY_US = 300000;

    private static function db(): PDO
    {
        return Database::getConnection();
    }

    // ------------------------------------------------------------------
    // Credential storage
    // ------------------------------------------------------------------

    public static function getHash(): ?string
    {
        $stmt = self::db()->query('SELECT password_hash FROM admin_auth WHERE id = 1');
        $hash = $stmt->fetchColumn();
        return ($hash === false || $hash === '') ? null : (string)$hash;
    }

    public static function hasPassword(): bool
    {
        return self::getHash() !== null;
    }

    /**
     * Returns an error message, or null if the password is acceptable.
     */
    public static function validateNewPassword(string $password): ?string
    {
        if (strlen($password) < self::MIN_LENGTH) {
            return 'Password must be at least ' . self::MIN_LENGTH . ' characters long.';
        }
        if (strlen($password) > self::MAX_BYTES) {
            return 'Password must not exceed ' . self::MAX_BYTES . ' bytes.';
        }
        if (trim($password) !== $password) {
            return 'Password must not start or end with whitespace.';
        }
        return null;
    }

    public static function setPassword(string $password): void
    {
        $error = self::validateNewPassword($password);
        if ($error !== null) {
            throw new InvalidArgumentException($error);
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = self::db()->prepare(
            "INSERT INTO admin_auth (id, password_hash, updated_at) VALUES (1, :hash, datetime('now'))
             ON CONFLICT(id) DO UPDATE SET password_hash = excluded.password_hash, updated_at = excluded.updated_at"
        );
        $stmt->execute([':hash' => $hash]);
    }

    public static function verifyPassword(string $password): bool
    {
        $hash = self::getHash();
        if ($hash === null) {
            // Burn comparable time so absence of a password is not observable.
            password_verify($password, password_hash(random_bytes(16), PASSWORD_DEFAULT));
            return false;
        }

        if (!password_verify($password, $hash)) {
            return false;
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $stmt = self::db()->prepare('UPDATE admin_auth SET password_hash = :h WHERE id = 1');
            $stmt->execute([':h' => password_hash($password, PASSWORD_DEFAULT)]);
        }
        return true;
    }

    /**
     * Fingerprint of the current credential. Stored in the session at login so
     * that a password change invalidates every other session.
     */
    private static function credentialFingerprint(): string
    {
        return hash('sha256', (string)self::getHash());
    }

    // ------------------------------------------------------------------
    // Brute-force throttling
    // ------------------------------------------------------------------

    /**
     * Seconds remaining on a lockout for this IP (0 = not locked).
     */
    public static function lockoutRemaining(string $ip): int
    {
        $stmt = self::db()->prepare(
            "SELECT COUNT(*) AS failures, MAX(created_at) AS last_failure
               FROM login_attempts
              WHERE ip_address = :ip
                AND success = 0
                AND created_at > datetime('now', :window)
                AND id > (SELECT COALESCE(MAX(id), 0) FROM login_attempts WHERE ip_address = :ip2 AND success = 1)"
        );
        $stmt->execute([
            ':ip' => $ip,
            ':ip2' => $ip,
            ':window' => '-' . self::WINDOW_SECONDS . ' seconds',
        ]);
        $row = $stmt->fetch();

        if (!$row || (int)$row['failures'] < self::MAX_FAILURES || empty($row['last_failure'])) {
            return 0;
        }

        $lastFailure = strtotime($row['last_failure'] . ' UTC');
        $remaining = self::LOCKOUT_SECONDS - (time() - (int)$lastFailure);
        return max(0, $remaining);
    }

    private static function recordAttempt(string $ip, bool $success): void
    {
        $db = self::db();
        $stmt = $db->prepare("INSERT INTO login_attempts (ip_address, success, created_at) VALUES (:ip, :s, datetime('now'))");
        $stmt->execute([':ip' => $ip, ':s' => $success ? 1 : 0]);
        $db->exec("DELETE FROM login_attempts WHERE created_at < datetime('now', '-1 day')");
    }

    public static function resetLockouts(?string $ip = null): void
    {
        if ($ip !== null) {
            $stmt = self::db()->prepare("DELETE FROM login_attempts WHERE ip_address = :ip");
            $stmt->execute([':ip' => $ip]);
        } else {
            self::db()->exec("DELETE FROM login_attempts");
        }
    }

    // ------------------------------------------------------------------
    // Session lifecycle
    // ------------------------------------------------------------------

    /**
     * @return array{ok: bool, error: ?string}
     */
    public static function login(string $password): array
    {
        $ip = Security::clientIp();
        $audit = new AuditService(self::db());

        if (!self::hasPassword()) {
            return ['ok' => false, 'error' => 'No administrator password is configured on the server.'];
        }

        $remaining = self::lockoutRemaining($ip);
        if ($remaining > 0) {
            $audit->log('LOGIN_LOCKED', 'Login attempt rejected while locked out');
            return ['ok' => false, 'error' => sprintf(
                'Too many failed attempts. Try again in %d minute(s).',
                (int)ceil($remaining / 60)
            )];
        }

        if (!self::verifyPassword($password)) {
            self::recordAttempt($ip, false);
            $audit->log('LOGIN_FAILED', 'Invalid password');
            usleep(self::FAILURE_DELAY_US);
            return ['ok' => false, 'error' => 'Invalid password. Please try again.'];
        }

        self::recordAttempt($ip, true);

        Session::regenerate();
        $_SESSION['authenticated'] = true;
        $_SESSION['auth_time'] = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['credential_fp'] = self::credentialFingerprint();
        Security::rotateCsrfToken();

        $audit->log('LOGIN_SUCCESS', 'Administrator signed in');
        return ['ok' => true, 'error' => null];
    }

    public static function isAuthenticated(): bool
    {
        if (empty($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
            return false;
        }
        $fp = $_SESSION['credential_fp'] ?? '';
        if (!is_string($fp) || !hash_equals(self::credentialFingerprint(), $fp)) {
            // Password changed elsewhere (or legacy session): force re-login.
            Session::reset();
            return false;
        }
        return true;
    }

    /**
     * Returns an error message, or null on success.
     */
    public static function changePassword(string $current, string $new, string $confirm): ?string
    {
        if (!self::verifyPassword($current)) {
            usleep(self::FAILURE_DELAY_US);
            return 'Current password is incorrect.';
        }
        if ($new !== $confirm) {
            return 'New password and confirmation do not match.';
        }
        if (hash_equals($current, $new)) {
            return 'New password must be different from the current password.';
        }
        $error = self::validateNewPassword($new);
        if ($error !== null) {
            return $error;
        }

        self::setPassword($new);

        // Keep this session, invalidate all others.
        Session::regenerate();
        $_SESSION['credential_fp'] = self::credentialFingerprint();
        Security::rotateCsrfToken();

        (new AuditService(self::db()))->log('PASSWORD_CHANGED', 'Administrator password changed via web UI');
        return null;
    }

    public static function logout(): void
    {
        if (!empty($_SESSION['authenticated'])) {
            (new AuditService(self::db()))->log('LOGOUT', 'Administrator signed out');
        }
        Session::reset();
    }

    public static function requireAuth(): void
    {
        if (self::isAuthenticated()) {
            return;
        }

        // If no password is configured, redirect to First-Time Setup Wizard
        if (!self::hasPassword()) {
            if (!empty($_GET['qr']) || (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))) {
                http_response_code(401);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['success' => false, 'code' => 'SETUP_REQUIRED', 'error' => 'System initialization required.']);
                exit;
            }
            header('Location: /setup.php');
            exit;
        }

        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/index.php');
        if (!str_starts_with($uri, '/') || str_starts_with($uri, '//') || str_contains($uri, '\\')) {
            $uri = '/index.php';
        }

        // JSON endpoints get a JSON error instead of an HTML redirect.
        if (!empty($_GET['qr']) || (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))) {
            http_response_code(401);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'code' => 'UNAUTHENTICATED', 'error' => 'Session expired. Please sign in again.']);
            exit;
        }

        header('Location: /login.php?return=' . urlencode($uri));
        exit;
    }
}
