<?php
declare(strict_types=1);

// Built-in PHP server router
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $uri;

// If request is for a physical file that exists in public/ (e.g. css/js), serve it directly
if ($uri !== '/' && file_exists($file) && !is_dir($file)) {
    $ext = pathinfo($file, PATHINFO_EXTENSION);
    if ($ext === 'css') {
        header('Content-Type: text/css; charset=UTF-8');
        readfile($file);
        exit;
    } elseif ($ext === 'js') {
        header('Content-Type: application/javascript; charset=UTF-8');
        readfile($file);
        exit;
    } elseif ($ext === 'svg') {
        header('Content-Type: image/svg+xml');
        readfile($file);
        exit;
    } elseif ($ext === 'php') {
        require $file;
        exit;
    }
    return false;
}

// Route root to index.php
if ($uri === '/' || $uri === '') {
    require __DIR__ . '/index.php';
    exit;
}

// Check for script without .php extension
if (file_exists(__DIR__ . $uri . '.php')) {
    require __DIR__ . $uri . '.php';
    exit;
}

// Default 404
http_response_code(404);
echo "404 Not Found";
