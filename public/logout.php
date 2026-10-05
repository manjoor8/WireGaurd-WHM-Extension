<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

use WireGuardManager\AuthService;

AuthService::logout();

header('Location: /login.php?msg=' . urlencode('You have been logged out successfully.'));
exit;
