<?php
declare(strict_types=1);

// bootstrap enforces Host allow-list, hardened session and CSRF on POST
require_once dirname(__DIR__) . '/src/bootstrap.php';

use WireGuardManager\AuthService;
use WireGuardManager\Session;

// Logout is POST-only (CSRF protected by bootstrap). A GET cannot sign you out.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /index.php');
    exit;
}

AuthService::logout();
Session::flash('success', 'You have been logged out successfully.');

header('Location: /login.php');
exit;
