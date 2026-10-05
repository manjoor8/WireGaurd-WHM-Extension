<?php
declare(strict_types=1);

// Class autoloader and template helpers. Safe to include from CLI scripts,
// the router (static assets), and web pages alike. Has no side effects.

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

if (!function_exists('csrf_field')) {
    /**
     * Hidden CSRF input. Must be placed inside every POST form.
     */
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="'
            . h(\WireGuardManager\Security::csrfToken()) . '">';
    }
}
