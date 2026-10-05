<?php
declare(strict_types=1);

// Built-in PHP server router.
// Only explicitly allow-listed entry scripts and files under /assets/ are served.
// Paths containing traversal sequences are rejected outright.

require_once dirname(__DIR__) . '/src/autoload.php';

use WireGuardManager\Security;

$rawPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$uri = rawurldecode(is_string($rawPath) ? $rawPath : '/');
if ($uri === '') {
    $uri = '/';
}

if (
    str_contains($uri, "\0")
    || str_contains($uri, '..')
    || str_contains($uri, '\\')
    || str_contains($uri, '//')
) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "400 Bad Request\n";
    exit;
}

Security::enforceHost();

$routes = [
    '/'               => 'index.php',
    '/index.php'      => 'index.php',
    '/clients.php'    => 'clients.php',
    '/client.php'     => 'client.php',
    '/add-client.php' => 'add-client.php',
    '/settings.php'   => 'settings.php',
    '/logs.php'       => 'logs.php',
    '/login.php'      => 'login.php',
    '/logout.php'     => 'logout.php',
];

// Allow extension-less URLs, e.g. /clients
$lookup = $uri;
if (!isset($routes[$lookup]) && isset($routes[$lookup . '.php'])) {
    $lookup .= '.php';
}

if (isset($routes[$lookup])) {
    require __DIR__ . '/' . $routes[$lookup];
    exit;
}

// Static assets (only under /assets/, only known types)
if (str_starts_with($uri, '/assets/')) {
    $base = realpath(__DIR__ . '/assets');
    $real = realpath(__DIR__ . $uri);

    $types = [
        'css'   => 'text/css; charset=UTF-8',
        'js'    => 'application/javascript; charset=UTF-8',
        'svg'   => 'image/svg+xml',
        'png'   => 'image/png',
        'ico'   => 'image/x-icon',
        'woff2' => 'font/woff2',
    ];

    if ($base !== false && $real !== false
        && str_starts_with($real, $base . DIRECTORY_SEPARATOR)
        && is_file($real)
    ) {
        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        if (isset($types[$ext])) {
            header('Content-Type: ' . $types[$ext]);
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: public, max-age=3600');
            header('Content-Length: ' . (string)filesize($real));
            readfile($real);
            exit;
        }
    }
}

http_response_code(404);
header('Content-Type: text/plain; charset=UTF-8');
echo "404 Not Found\n";
