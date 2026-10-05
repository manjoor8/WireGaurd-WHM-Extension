<?php
declare(strict_types=1);

// bootstrap enforces Host allow-list, hardened session and CSRF on POST
require_once dirname(__DIR__) . '/src/bootstrap.php';

use WireGuardManager\AuthService;
use WireGuardManager\WireGuardService;
use WireGuardManager\ConfigService;
use WireGuardManager\ClientService;
use WireGuardManager\QRService;
use WireGuardManager\Database;
use WireGuardManager\Session;

AuthService::requireAuth();

$db = Database::getConnection();
$wg = new WireGuardService();
$configService = new ConfigService($db);
$clientService = new ClientService($db, $wg, $configService);
$qrService = new QRService();

$clientId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$client = $clientService->getClient($clientId);

if (!$client) {
    Session::flash('error', 'Client not found.');
    header('Location: /clients.php');
    exit;
}

// Handle Configuration File Download
if (!empty($_GET['download'])) {
    if ($client['state'] === 'revoked') {
        http_response_code(403);
        echo "Configuration unavailable for revoked client.";
        exit;
    }

    $configText = $clientService->generateClientConfig($client);
    $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $client['name']) . '.conf';

    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($configText));
    echo $configText;
    exit;
}

// Handle Dynamic QR Code JSON endpoint
if (!empty($_GET['qr'])) {
    header('Content-Type: application/json; charset=UTF-8');
    if ($client['state'] === 'revoked') {
        echo json_encode(['success' => false, 'error' => 'Client is revoked.']);
        exit;
    }

    if (empty($client['private_key'])) {
        echo json_encode([
            'success' => false,
            'error' => 'This client was imported from wg0 without a private key. Click "View" and generate a new key pair to enable mobile barcode scanning.'
        ]);
        exit;
    }

    $configText = $clientService->generateClientConfig($client);
    $qrDataUri = $qrService->generateDataUri($configText);

    if (!empty($qrDataUri)) {
        echo json_encode(['success' => true, 'qr' => $qrDataUri]);
    } else {
        echo json_encode(['success' => false, 'error' => 'QR generation failed. Ensure qrencode is installed on the server (dnf install qrencode).']);
    }
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'disconnect' || $action === 'disable') {
            $clientService->disconnectClient($clientId);
            $msg = "Client has been forcefully disconnected from WireGuard.";
        } elseif ($action === 'reset_session' || $action === 'kick') {
            $clientService->resetSession($clientId);
            $msg = "Active session was forcefully terminated (kicked).";
        } elseif ($action === 'enable') {
            $clientService->enableClient($clientId);
            $msg = "Client has been enabled.";
        } elseif ($action === 'revoke') {
            $clientService->revokeClient($clientId);
            $msg = "Client has been revoked.";
        } elseif ($action === 'rekey') {
            $clientService->rekeyClient($clientId);
            $msg = "New Curve25519 key pair generated. Mobile QR barcode is now active!";
        } else {
            throw new \InvalidArgumentException('Unknown action.');
        }
        Session::flash('success', $msg);
    } catch (\Throwable $e) {
        Session::flash('error', "Action failed: " . $e->getMessage());
    }
    header("Location: /client.php?id={$clientId}");
    exit;
}

$configText = '';
$qrDataUri = '';
if ($client['state'] !== 'revoked') {
    $configText = $clientService->generateClientConfig($client);
    $qrDataUri = $qrService->generateDataUri($configText);
}

$pageTitle = 'Client: ' . $client['name'];
$activeNav = 'clients';

require dirname(__DIR__) . '/templates/header.php';
require dirname(__DIR__) . '/templates/client.php';
require dirname(__DIR__) . '/templates/footer.php';
