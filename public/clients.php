<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

use WireGuardManager\WireGuardService;
use WireGuardManager\ConfigService;
use WireGuardManager\ClientService;
use WireGuardManager\Database;

$csrfToken = $_SESSION['csrf_token'] ?? '';

$db = Database::getConnection();
$wg = new WireGuardService();
$configService = new ConfigService($db);
$clientService = new ClientService($db, $wg, $configService);

// Handle POST actions (disable, enable, revoke)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $clientId = (int)($_POST['client_id'] ?? 0);
    $msg = '';
    $error = '';

    try {
        if ($action === 'disable') {
            $clientService->disableClient($clientId);
            $msg = "Client has been disabled and removed from active interface.";
        } elseif ($action === 'enable') {
            $clientService->enableClient($clientId);
            $msg = "Client has been enabled and restored to WireGuard.";
        } elseif ($action === 'revoke') {
            $clientService->revokeClient($clientId);
            $msg = "Client has been permanently revoked.";
        }
        $_SESSION['flash_success'] = $msg;
        header('Location: /clients.php?msg=' . urlencode($msg));
        exit;
    } catch (\Throwable $e) {
        $error = "Action failed: " . $e->getMessage();
        $_SESSION['flash_error'] = $error;
        header('Location: /clients.php?error=' . urlencode($error));
        exit;
    }
}

$clients = $clientService->listClients();
$pageTitle = 'Client Management';
$activeNav = 'clients';

require dirname(__DIR__) . '/templates/header.php';
require dirname(__DIR__) . '/templates/clients.php';
require dirname(__DIR__) . '/templates/footer.php';
