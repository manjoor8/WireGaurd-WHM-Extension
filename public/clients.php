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

// Handle POST actions (delete, toggle, update, disconnect, kick, enable, revoke)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $clientId = (int)($_POST['client_id'] ?? 0);
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
        || !empty($_POST['ajax']);

    $newState = null;
    try {
        if ($action === 'delete') {
            $client = $clientService->getClient($clientId);
            $clientName = $client['name'] ?? "ID {$clientId}";
            $clientService->deleteClient($clientId);
            $msg = "Client '{$clientName}' has been permanently deleted from database and WireGuard.";
        } elseif ($action === 'toggle') {
            $client = $clientService->getClient($clientId);
            if (!$client) {
                throw new \InvalidArgumentException('Client not found.');
            }
            if ($client['state'] === 'active') {
                $clientService->disconnectClient($clientId);
                $newState = 'disabled';
                $msg = "Client '{$client['name']}' has been disabled.";
            } else {
                $clientService->enableClient($clientId);
                $newState = 'active';
                $msg = "Client '{$client['name']}' has been enabled.";
            }
        } elseif ($action === 'update') {
            $name = (string)($_POST['name'] ?? '');
            $desc = isset($_POST['description']) ? (string)$_POST['description'] : null;
            $clientService->updateClient($clientId, $name, $desc);
            $msg = "Client updated successfully.";
        } elseif ($action === 'disconnect' || $action === 'disable') {
            $clientService->disconnectClient($clientId);
            $newState = 'disabled';
            $msg = "Client has been forcefully disconnected from WireGuard.";
        } elseif ($action === 'reset_session' || $action === 'kick') {
            $clientService->resetSession($clientId);
            $msg = "Active session for client was forcefully terminated (kicked).";
        } elseif ($action === 'enable') {
            $clientService->enableClient($clientId);
            $newState = 'active';
            $msg = "Client has been enabled and restored to WireGuard.";
        } elseif ($action === 'revoke') {
            $clientService->revokeClient($clientId);
            $newState = 'revoked';
            $msg = "Client has been permanently revoked.";
        } else {
            throw new \InvalidArgumentException('Unknown action.');
        }

        if ($isAjax) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'success' => true,
                'message' => $msg,
                'action' => $action,
                'client_id' => $clientId,
                'state' => $newState,
            ]);
            exit;
        }

        Session::flash('success', $msg);
    } catch (\Throwable $e) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
            exit;
        }
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
