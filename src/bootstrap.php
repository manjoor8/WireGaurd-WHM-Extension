<?php
declare(strict_types=1);

// Configure application session save path to prevent permission issues on AlmaLinux/RHEL
$sessionDir = getenv('SESSION_DIR') ?: '/var/lib/wireguard-manager/sessions';
if (!is_dir($sessionDir)) {
    $fallbackSession = dirname(__DIR__) . '/storage/sessions';
    if (!is_dir($fallbackSession)) {
        @mkdir($fallbackSession, 0700, true);
    }
    if (is_dir($fallbackSession) && is_writable($fallbackSession)) {
        $sessionDir = $fallbackSession;
    }
}

if (is_dir($sessionDir) && is_writable($sessionDir)) {
    @ini_set('session.save_path', $sessionDir);
}

require_once __DIR__ . '/autoload.php';

use WireGuardManager\Security;
use WireGuardManager\Session;

// Web request guards. Order matters:
//  1. security headers on every response
//  2. reject foreign Host headers (DNS rebinding) before touching the session
//  3. start the hardened session
//  4. reject POSTs without a valid CSRF token
if (PHP_SAPI !== 'cli') {
    Security::sendHeaders();
    Security::enforceHost();
    Session::start();
    Security::enforceCsrf();
}
