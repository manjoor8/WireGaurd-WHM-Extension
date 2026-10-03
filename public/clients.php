<?php
declare(strict_types=1);

session_start();

require_once dirname(__DIR__) . '/src/bootstrap.php';

use WireGuardManager\WireGuardService;
use WireGuardManager\ConfigService;
use WireGuardManager\ClientService;
use WireGuardManager\Database;

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$db = Database::getConnection();
$wg = new WireGuardService();
$configService = new ConfigService($db);
$clientService = new ClientService($db, $wg, $configService);

// Handle POST actions (disable, enable, revoke)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $_SESSION['flash_error'] = 'Invalid or expired CSRF token.';
        header('Location: /clients.php');
        exit;
    }

    $action = $_POST['action'] ?? '';
    $clientId = (int)($_POST['client_id'] ?? 0);

    try {
        if ($action === 'disable') {
            $clientService->disableClient($clientId);
            $_SESSION['flash_success'] = "Client has been disabled and removed from active interface.";
        } elseif ($action === 'enable') {
            $clientService->enableClient($clientId);
            $_SESSION['flash_success'] = "Client has been enabled and restored to WireGuard.";
        } elseif ($action === 'revoke') {
            $clientService->revokeClient($clientId);
            $_SESSION['flash_success'] = "Client has been permanently revoked.";
        }
    } catch (\Throwable $e) {
        $_SESSION['flash_error'] = "Action failed: " . $e->getMessage();
    }

    header('Location: /clients.php');
    exit;
}

$clients = $clientService->listClients();
$pageTitle = 'Client Management';
$activeNav = 'clients';

require dirname(__DIR__) . '/templates/header.php';
require dirname(__DIR__) . '/templates/clients.php';
require dirname(__DIR__) . '/templates/footer.php';
