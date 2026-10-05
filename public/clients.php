<?php
declare(strict_types=1);

// bootstrap enforces Host allow-list, hardened session and CSRF on POST
require_once dirname(__DIR__) . '/src/bootstrap.php';

use WireGuardManager\AuthService;
use WireGuardManager\WireGuardService;
use WireGuardManager\ConfigService;
use WireGuardManager\ClientService;
use WireGuardManager\Database;
use WireGuardManager\Session;

AuthService::requireAuth();

$db = Database::getConnection();
$wg = new WireGuardService();
$configService = new ConfigService($db);
$clientService = new ClientService($db, $wg, $configService);

// Handle POST actions (disconnect, kick, enable, revoke)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $clientId = (int)($_POST['client_id'] ?? 0);

    try {
        if ($action === 'disconnect' || $action === 'disable') {
            $clientService->disconnectClient($clientId);
            $msg = "Client has been forcefully disconnected from WireGuard.";
        } elseif ($action === 'reset_session' || $action === 'kick') {
            $clientService->resetSession($clientId);
            $msg = "Active session for client was forcefully terminated (kicked).";
        } elseif ($action === 'enable') {
            $clientService->enableClient($clientId);
            $msg = "Client has been enabled and restored to WireGuard.";
        } elseif ($action === 'revoke') {
            $clientService->revokeClient($clientId);
            $msg = "Client has been permanently revoked.";
        } else {
            throw new \InvalidArgumentException('Unknown action.');
        }
        Session::flash('success', $msg);
    } catch (\Throwable $e) {
        Session::flash('error', "Action failed: " . $e->getMessage());
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
