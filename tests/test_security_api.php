<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/autoload.php';

use WireGuardManager\App;
use WireGuardManager\AuthService;
use WireGuardManager\Security;
use WireGuardManager\Database;

$passed = 0;
$failed = 0;

function it(string $desc, bool $condition): void {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] $desc\n";
        $passed++;
    } else {
        echo "[FAIL] $desc\n";
        $failed++;
    }
}

echo "=== Running Security & Authorization Verification Tests ===\n\n";

// Use isolated test database
$testDb = sys_get_temp_dir() . '/wgm_sec_test_' . uniqid() . '.db';
Database::setPath($testDb);
$db = Database::getConnection();

// 1. Password setup
AuthService::setPassword('SuperSecurePassword2026!');
it('Admin credentials initialized', AuthService::hasPassword() === true);

// Ensure session is unauthenticated
$_SESSION = [];
it('Session is unauthenticated', AuthService::isAuthenticated() === false);

// 2. Structured error format helper verification
$err = App::formatError('WIREGUARD_INSTALL_FAILED', 'Could not install WireGuard.', 'Network timeout', true);
it('Structured error has success=false', $err['success'] === false);
it('Structured error has machine-readable code', $err['code'] === 'WIREGUARD_INSTALL_FAILED');
it('Structured error has user message', $err['message'] === 'Could not install WireGuard.');
it('Structured error has technical details', $err['details'] === 'Network timeout');
it('Structured error indicates retryable', $err['retryable'] === true);

// 3. Security::isAllowedHost tests (defeats DNS rebinding)
it('Allows 10.50.0.1:5443', Security::isAllowedHost('10.50.0.1:5443') === true);
it('Allows 127.0.0.1:5050', Security::isAllowedHost('127.0.0.1:5050') === true);
it('Allows localhost:5443', Security::isAllowedHost('localhost:5443') === true);
it('Allows valid server IP 192.168.1.100:5443', Security::isAllowedHost('192.168.1.100:5443') === true);
it('Allows valid server IP 203.0.113.15:5443', Security::isAllowedHost('203.0.113.15:5443') === true);
it('Rejects malicious domain attacker.com (DNS rebinding)', Security::isAllowedHost('attacker.com') === false);
it('Rejects malicious domain evil.internal:5443 (DNS rebinding)', Security::isAllowedHost('evil.internal:5443') === false);
it('Rejects invalid IP 999.999.999.999:5443', Security::isAllowedHost('999.999.999.999:5443') === false);

// 4. Privileged Helper Security Review: Check no generic command execution exists
$helperContent = file_get_contents(dirname(__DIR__) . '/bin/wireguard-manager-helper');
it('Helper has NO eval command', !str_contains($helperContent, 'eval '));
it('Helper has NO system/exec of arbitrary input', !str_contains($helperContent, '$@') && !str_contains($helperContent, '$*') && !str_contains($helperContent, 'exec "$@"'));
it('Helper enforces strict allow-list on COMMAND', str_contains($helperContent, 'case "$COMMAND" in'));
it('Helper has error_exit on wildcard default (*)', str_contains($helperContent, '*)') && str_contains($helperContent, 'Unknown or unsupported command'));
it('Helper validates public keys with KEY_REGEX', str_contains($helperContent, "KEY_REGEX='^[A-Za-z0-9+/]{43}=$'"));
it('Helper validates IP addresses with IP_REGEX', str_contains($helperContent, "IP_REGEX="));
it('Helper accepts private keys strictly via stdin', str_contains($helperContent, 'read -r SERVER_PRIVKEY') && str_contains($helperContent, 'read -r PRIVKEY'));

// 5. CSRF Token Generation & Rotation
$token1 = Security::csrfToken();
it('CSRF token is generated (64 hex chars)', strlen($token1) === 64 && ctype_xdigit($token1));
Security::rotateCsrfToken();
$token2 = Security::csrfToken();
it('CSRF token rotates to new value', $token1 !== $token2);

// 6. Brute-Force Rate Limiting Verification
AuthService::resetLockouts();
$testClientIp = '198.51.100.42';
$_SERVER['REMOTE_ADDR'] = $testClientIp;

for ($i = 0; $i < 4; $i++) {
    AuthService::login('WrongPassword!');
}
it('4 failed attempts do not trigger lockout yet', AuthService::lockoutRemaining($testClientIp) === 0);

// 5th failed attempt
AuthService::login('WrongPassword!');
it('5 failed attempts trigger 15-minute IP lockout', AuthService::lockoutRemaining($testClientIp) > 0);

$lockedAttempt = AuthService::login('SuperSecurePassword2026!');
it('Locked out IP cannot sign in even with correct password', $lockedAttempt['ok'] === false && str_contains((string)$lockedAttempt['error'], 'failed attempts'));

AuthService::resetLockouts($testClientIp);
it('Resetting lockout restores login capability', AuthService::lockoutRemaining($testClientIp) === 0);

$validLogin = AuthService::login('SuperSecurePassword2026!');
it('Valid login succeeds after lockout reset', $validLogin['ok'] === true);
it('Session is now authenticated', AuthService::isAuthenticated() === true);

AuthService::logout();
it('Session is unauthenticated after logout', AuthService::isAuthenticated() === false);

@unlink($testDb);

echo "\nSummary: $passed passed, $failed failed.\n";
if ($failed > 0) {
    exit(1);
}
