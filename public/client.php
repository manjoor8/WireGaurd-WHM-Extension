<?php
declare(strict_types=1);

session_start();

require_once dirname(__DIR__) . '/src/bootstrap.php';

use WireGuardManager\WireGuardService;
use WireGuardManager\ConfigService;
use WireGuardManager\ClientService;
use WireGuardManager\QRService;
use WireGuardManager\Database;

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$db = Database::getConnection();
$wg = new WireGuardService();
$configService = new ConfigService($db);
$clientService = new ClientService($db, $wg, $configService);
$qrService = new QRService();

$clientId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$client = $clientService->getClient($clientId);

if (!$client) {
    $_SESSION['flash_error'] = "Client not found.";
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

    $configText = $clientService->generateClientConfig($client);
    $qrDataUri = $qrService->generateDataUri($configText);

    if (!empty($qrDataUri)) {
        echo json_encode(['success' => true, 'qr' => $qrDataUri]);
    } else {
        echo json_encode(['success' => false, 'error' => 'QR generation failed. Ensure qrencode is installed.']);
    }
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $_SESSION['flash_error'] = 'Invalid or expired CSRF token.';
        header("Location: /client.php?id={$clientId}");
        exit;
    }

    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'disable') {
            $clientService->disableClient($clientId);
            $_SESSION['flash_success'] = "Client has been disabled.";
        } elseif ($action === 'enable') {
            $clientService->enableClient($clientId);
            $_SESSION['flash_success'] = "Client has been enabled.";
        } elseif ($action === 'revoke') {
            $clientService->revokeClient($clientId);
            $_SESSION['flash_success'] = "Client has been revoked.";
        }
    } catch (\Throwable $e) {
        $_SESSION['flash_error'] = "Action failed: " . $e->getMessage();
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
