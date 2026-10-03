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

// Autoloader
spl_autoload_register(function (string $class): void {
    $prefix = 'WireGuardManager\\';
    $baseDir = __DIR__ . '/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// Helper functions for templates
if (!function_exists('h')) {
    function h(?string $str): string
    {
        return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
